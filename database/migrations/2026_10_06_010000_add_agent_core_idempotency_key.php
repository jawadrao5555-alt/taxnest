<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Forward-compatible companion for installations which ran the initial inbox
 * migration before the protocol gained explicit idempotency keys. Fresh
 * installs receive these columns from the initial migration and this is a
 * deliberate no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agent_core_events')) {
            return;
        }

        $idempotencyColumnWasMissing = !Schema::hasColumn('agent_core_events', 'idempotency_key');
        $hashColumnWasMissing = !Schema::hasColumn('agent_core_events', 'content_hash');

        // Each column is independently guarded: a partial deploy can have one
        // but not the other. Nullable first keeps a populated legacy table
        // migratable; values are deterministically backfilled below.
        if (!Schema::hasColumn('agent_core_events', 'idempotency_key')) {
            Schema::table('agent_core_events', function (Blueprint $table) {
                $table->string('idempotency_key', 128)->nullable()->after('event_id');
            });
        }
        if (!Schema::hasColumn('agent_core_events', 'content_hash')) {
            Schema::table('agent_core_events', function (Blueprint $table) {
                $table->string('content_hash', 64)->nullable()->after('payload');
            });
        }
        if (!Schema::hasColumn('agent_core_events', 'legacy_backfilled')) {
            Schema::table('agent_core_events', function (Blueprint $table) {
                $table->boolean('legacy_backfilled')->default(false)->after('content_hash');
            });
        }

        DB::table('agent_core_events')->orderBy('id')->each(function ($row) use ($idempotencyColumnWasMissing, $hashColumnWasMissing): void {
            $key = trim((string) ($row->idempotency_key ?? ''));
            $hash = trim((string) ($row->content_hash ?? ''));
            $isLegacy = $idempotencyColumnWasMissing || $hashColumnWasMissing || $key === '' || $hash === '';
            if (!$isLegacy) {
                return;
            }
            if ($key === '') {
                $eventId = (string) $row->event_id;
                $eventIdIsUsable = strlen($eventId) <= 64
                    && preg_match('/^[A-Za-z0-9._:-]+$/', $eventId)
                    && DB::table('agent_core_events')
                        ->where('company_id', $row->company_id)
                        ->where('device_uid', $row->device_uid)
                        ->where('event_id', $eventId)
                        ->count() === 1;
                // The canonical Node convention uses event_id as its
                // idempotency key. Retaining that convention for a sound
                // legacy event makes an exact retry a duplicate, not conflict.
                // Duplicated/unusable historic IDs receive a stable row key.
                $key = $eventIdIsUsable
                    ? $eventId
                    : 'legacy-' . $row->id . '-' . substr($eventId, 0, 96);
            }
            $payload = is_array($row->payload)
                ? $row->payload
                : json_decode((string) $row->payload, true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $event = [
                'event_id' => (string) $row->event_id,
                'event_type' => (string) $row->event_type,
                'occurred_at' => $row->occurred_at,
                'idempotency_key' => $key,
                'payload' => $payload,
            ];
            DB::table('agent_core_events')->where('id', $row->id)->update([
                'idempotency_key' => $key,
                'content_hash' => \App\Services\AgentCoreEventInboxService::contentHashFor($event),
                'payload' => \App\Services\AgentCoreEventInboxService::canonicalJson($payload),
                'legacy_backfilled' => true,
            ]);
        });

        // A unique event ID can only be added where legacy data already obeys
        // that invariant. The key unique is safe after deterministic backfill.
        if (!Schema::hasIndex('agent_core_events', 'agent_core_events_idempotency_key')) {
            Schema::table('agent_core_events', function (Blueprint $table) {
                $table->unique(['company_id', 'device_uid', 'idempotency_key'], 'agent_core_events_idempotency_key');
            });
        }
        if (!Schema::hasIndex('agent_core_events', 'agent_core_events_idempotency')
            && !$this->hasDuplicateEventIds()) {
            Schema::table('agent_core_events', function (Blueprint $table) {
                $table->unique(['company_id', 'device_uid', 'event_id'], 'agent_core_events_idempotency');
            });
        }
    }

    private function hasDuplicateEventIds(): bool
    {
        return DB::table('agent_core_events')
            ->select('company_id', 'device_uid', 'event_id')
            ->groupBy('company_id', 'device_uid', 'event_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    public function down(): void
    {
        // Never remove a live inbox's idempotency proof in rollback: the
        // original migration owns the table and its fresh-schema columns.
    }
};