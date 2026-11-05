<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive, bounded operational samples. Never consumes or replays a business queue. */
final class PraAgentDiagnosticEvidence
{
    public const RETENTION_DAYS = 30;

    public function ingest(Company $company, mixed $deviceUid, mixed $raw): ?array
    {
        if ($company->product_type !== 'pos' || !is_string($deviceUid) || $deviceUid === '' || strlen($deviceUid) > 150
            || !is_array($raw) || ($raw['version'] ?? null) !== 1 || !is_string($raw['stream_id'] ?? null)
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $raw['stream_id'])
            || !is_array($raw['events'] ?? null) || count($raw['events']) > 50) return null;
        $ack = ['version' => 1, 'stream_id' => $raw['stream_id'], 'accepted' => [], 'status' => 'unavailable'];
        try {
            if (!Schema::hasTable('pra_diagnostic_samples') || !Schema::hasTable('pra_diagnostic_streams')) return $ack;
            return DB::transaction(function () use ($company, $deviceUid, $raw, $ack) {
                $identity = ['company_id' => $company->id, 'device_hash' => hash('sha256', $company->id."\0".$deviceUid), 'stream_id' => $raw['stream_id']];
                DB::table('pra_diagnostic_streams')->insertOrIgnore($identity + ['first_received_at' => now(), 'last_received_at' => now(), 'storage_status' => 'unknown']);
                $stream = DB::table('pra_diagnostic_streams')->where($identity)->lockForUpdate()->first();
                $ack['status'] = 'stored';
                foreach ($raw['events'] as $event) {
                    $clean = $this->clean($event);
                    if ($clean === null) { $ack['status'] = 'partial'; continue; }
                    $hash = hash('sha256', json_encode($clean));
                    $key = ['diagnostic_stream_id' => $stream->id, 'sequence' => $clean['sequence']];
                    DB::table('pra_diagnostic_samples')->insertOrIgnore($key + [
                        'company_id' => $company->id, 'source_event_at' => $clean['at'], 'received_at' => now(),
                        'content_hash' => $hash, 'facts' => json_encode($clean['facts']),
                    ]);
                    $stored = DB::table('pra_diagnostic_samples')->where($key)->where('company_id', $company->id)->first(['content_hash']);
                    if ($stored && hash_equals($stored->content_hash, $hash)) $ack['accepted'][] = $clean['sequence'];
                    else $ack['status'] = 'conflicting_sequence';
                }
                DB::table('pra_diagnostic_streams')->where('id', $stream->id)->update([
                    'last_received_at' => now(), 'pending' => $this->integer($raw['pending'] ?? null, 4096),
                    'dropped' => max((int) $stream->dropped, $this->integer($raw['dropped'] ?? null, 1000000000) ?? 0),
                    'resets' => max((int) $stream->resets, $this->integer($raw['resets'] ?? null, 1000000) ?? 0),
                    'storage_status' => in_array($raw['storage_status'] ?? null, ['available', 'storage_unavailable', 'buffer_reset'], true) ? $raw['storage_status'] : 'unknown',
                ]);
                return $ack;
            });
        } catch (\Throwable $ignored) {
            // No acknowledgement until commit; retries preserve samples locally.
            return $ack;
        }
    }

    private function clean(mixed $event): ?array
    {
        if (!is_array($event) || !is_int($event['sequence'] ?? null) || $event['sequence'] < 1 || $event['sequence'] > 9007199254740991
            || !is_string($event['at'] ?? null) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?Z$/D', $event['at'])) return null;
        try { $at = Carbon::parse($event['at'])->setTimezone(config('app.timezone')); } catch (\Throwable $e) { return null; }
        if ($at->year < 2000 || $at->year > 2100) return null;
        return ['sequence' => $event['sequence'], 'at' => $at->format('Y-m-d H:i:s.v'), 'facts' => self::facts($event)];
    }

    public static function facts(array $event): array
    {
        $facts = [];
        foreach (['printing_enabled', 'printer_healthy'] as $key) $facts[$key] = is_bool($event[$key] ?? null) ? $event[$key] : null;
        $facts['printers_reported'] = self::integer($event['printers_reported'] ?? null, 100);
        $facts['pending_callbacks'] = self::integer($event['pending_callbacks'] ?? null, 100000);
        $facts['fiscal_state'] = in_array($event['fiscal_state'] ?? null, ['unknown', 'reachable', 'unreachable'], true) ? $event['fiscal_state'] : 'unknown';
        return $facts;
    }

    private static function integer(mixed $value, int $max): ?int
    {
        return is_int($value) && $value >= 0 && $value <= $max ? $value : null;
    }

    public static function prune(): int
    {
        if (!Schema::hasTable('pra_diagnostic_samples')) return 0;
        $ids = DB::table('pra_diagnostic_samples')->where('received_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->orderBy('received_at')->orderBy('id')->limit(1000)->pluck('id');
        $removed = DB::table('pra_diagnostic_samples')->whereIn('id', $ids)->where('received_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
        if (Schema::hasTable('pra_diagnostic_streams')) {
            $stale = DB::table('pra_diagnostic_streams')->where('last_received_at', '<', now()->subDays(90))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pra_diagnostic_samples')->whereColumn('diagnostic_stream_id', 'pra_diagnostic_streams.id'))
                ->orderBy('id')->limit(100)->pluck('id');
            DB::table('pra_diagnostic_streams')->whereIn('id', $stale)->where('last_received_at', '<', now()->subDays(90))->delete();
        }
        return $removed;
    }
}
