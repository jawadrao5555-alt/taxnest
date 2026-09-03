<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentCoreEventInboxService
{
    /**
     * This is intentionally a small, explicit protocol vocabulary. New types
     * require a versioned contract change rather than becoming arbitrary data
     * storage under an agent key.
     */
    public const V1_EVENT_TYPES = [
        'sale.created',
        'sale.voided',
        'caller.ring',
        'print.requested',
        'print.completed',
        'sync.acked',
        'sync.rejected',
        'order.created',
        'order.held',
        'order.updated',
        'order.settled',
        'order.cancelled',
        'kot.created',
        'kot.updated',
        'kot.completed',
        'stock.adjusted',
        'stock.transferred',
        'customer.ledger.posted',
        'customer.khata.posted',
        'customer.wasooli.posted',
        'customer.refund.posted',
        'cash.opened',
        'cash.movement.posted',
        'expense.created',
        'day-close.created',
        'staff.attendance.recorded',
        'staff.shift.recorded',
    ];

    /**
     * Persist a batch once. The compound unique key is the idempotency boundary:
     * a device can retry freely, while neither another company nor another
     * device can collide with its event identifiers.
     *
     * @return array{acknowledged_ids: array<int, string>, received_count: int, stored_count: int, duplicate_count: int}
     */
    public function accept(Company $company, string $deviceUid, array $events): array
    {
        $stored = 0;
        $duplicates = 0;
        $hasScopeColumn = Schema::hasColumn('agent_core_events', 'event_scope');

        DB::transaction(function () use ($company, $deviceUid, $events, $hasScopeColumn, &$stored, &$duplicates): void {
            foreach ($events as $event) {
                $hash = self::contentHashFor($event);
                $existing = AgentCoreEvent::query()
                    ->where('company_id', $company->id)
                    ->where('device_uid', $deviceUid)
                    ->where(function ($query) use ($event) {
                        $query->where('event_id', $event['event_id'])
                            ->orWhere('idempotency_key', $event['idempotency_key']);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $contentDiffers = !hash_equals((string) $existing->content_hash, $hash)
                        || ($hasScopeColumn && $existing->event_scope !== null
                            && self::canonicalJson((array) $existing->event_scope) !== self::canonicalJson((array) $event['scope']));
                    if ($contentDiffers && !$this->isSafeLegacyPrecisionRetry($existing, $event)) {
                        throw new AgentCoreEventConflictException('An event_id or idempotency_key was reused with different immutable content.');
                    }
                    $duplicates++;
                    continue;
                }

                $insert = [
                    'company_id' => $company->id,
                    'device_uid' => $deviceUid,
                    'event_id' => $event['event_id'],
                    'idempotency_key' => $event['idempotency_key'],
                    'event_type' => $event['event_type'],
                    'occurred_at' => $event['occurred_at'] ?? null,
                    'payload' => self::canonicalJson($event['payload']),
                    'content_hash' => $hash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($hasScopeColumn) $insert['event_scope'] = self::canonicalJson((array) $event['scope']);
                if (Schema::hasColumn('agent_core_events', 'projection_status')) {
                    $insert['projection_status'] = 'received';
                }
                $inserted = AgentCoreEvent::query()->insertOrIgnore($insert);
                if ($inserted) {
                    $stored++;
                    continue;
                }

                // A concurrent retry won the unique-key race. Re-read it and
                // apply the same immutable-content rule instead of silently
                // acknowledging a different request.
                $existing = AgentCoreEvent::query()
                    ->where('company_id', $company->id)
                    ->where('device_uid', $deviceUid)
                    ->where(function ($query) use ($event) {
                        $query->where('event_id', $event['event_id'])
                            ->orWhere('idempotency_key', $event['idempotency_key']);
                    })
                    ->first();
                if (!$existing || !hash_equals((string) $existing->content_hash, $hash)
                    || ($hasScopeColumn && $existing->event_scope !== null
                        && self::canonicalJson((array) $existing->event_scope) !== self::canonicalJson((array) $event['scope']))) {
                    if (!$existing || !$this->isSafeLegacyPrecisionRetry($existing, $event)) {
                        throw new AgentCoreEventConflictException('An event_id or idempotency_key was reused with different immutable content.');
                    }
                }
                $duplicates++;
            }
        });

        return [
            'acknowledged_ids' => array_values(array_column($events, 'event_id')),
            'received_count' => count($events),
            'stored_count' => $stored,
            'duplicate_count' => $duplicates,
        ];
    }

    /** Shared with the legacy-schema migration: this is the immutable contract. */
    public static function contentHashFor(array $event): string
    {
        return hash('sha256', json_encode([
            'event_id' => $event['event_id'],
            'event_type' => $event['event_type'],
            'occurred_at' => self::normalizedOccurredAt($event['occurred_at'] ?? null),
            'idempotency_key' => $event['idempotency_key'],
            // Historical rows and callers of this shared migration helper can
            // predate the payload field. Treat its absence as the canonical
            // empty object; HTTP ingestion still rejects it at validation.
            // Valid flat/nested payload hashes remain byte-for-byte unchanged.
            'payload' => self::canonicalValue((array) ($event['payload'] ?? [])),
        ], JSON_THROW_ON_ERROR));
    }

    public static function canonicalJson(array $payload): string
    {
        return json_encode(self::canonicalValue($payload), JSON_THROW_ON_ERROR);
    }

    private static function normalizedOccurredAt(?string $occurredAt): ?string
    {
        return $occurredAt === null ? null : \Carbon\Carbon::parse($occurredAt)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function canonicalValue(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalValue($item);
            }
        }
        return $value;
    }

    /**
     * Old timestamp columns could retain only whole seconds. This exception is
     * restricted to explicitly migrated rows and still compares every other
     * immutable field exactly; new Core rows always use the full content hash.
     */
    private function isSafeLegacyPrecisionRetry(AgentCoreEvent $existing, array $event): bool
    {
        if (!$existing->legacy_backfilled
            || $existing->event_id !== $event['event_id']
            || $existing->idempotency_key !== $event['idempotency_key']
            || $existing->event_type !== $event['event_type']
            || self::canonicalJson((array) $existing->payload) !== self::canonicalJson($event['payload'])) {
            return false;
        }

        $stored = $existing->getRawOriginal('occurred_at');
        $incoming = $event['occurred_at'] ?? null;
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        // SQL timestamp/datetime strings have no offset. They represent the
        // UTC instant originally sent by Core, but Carbon::parse() would apply
        // config('app.timezone') (Asia/Karachi here) and shift them five hours.
        // Read that legacy wire value explicitly as UTC; offset-bearing values
        // still use their declared offset.
        $storedString = (string) $stored;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $storedString)) {
            $storedSecond = \Carbon\Carbon::createFromFormat(
                'Y-m-d H:i:s',
                substr($storedString, 0, 19),
                'UTC'
            )->format('Y-m-d\TH:i:s\Z');
        } else {
            $storedSecond = \Carbon\Carbon::parse($storedString)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return $storedSecond
            === \Carbon\Carbon::parse($incoming)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}