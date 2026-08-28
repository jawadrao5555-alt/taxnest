<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The sale screen reports how many bills are still sitting in THIS device's
 * IndexedDB offline queue.
 *
 * Why this exists
 * ---------------
 * An OFFLINE shop cannot report anything — its silent agent heartbeat is
 * already the signal that it is down. The case nobody could see is the
 * opposite one: a device that is ONLINE while bills stay stuck. A poisoned
 * bill that has burnt its retries, a quota block, an expired session — the
 * queue quietly holds real money and the shop only discovers it at day-close
 * when the totals do not add up.
 *
 * ONE controller serves both the PRA and the FBR sale screens on purpose. The
 * two screens are ports of each other and have a long history of drifting
 * apart; a single action cannot drift.
 *
 * This is telemetry, never a gate: it writes three columns and returns ok. It
 * must never be able to refuse, slow down, or fail a sale.
 */
class OfflineQueueReportController extends Controller
{
    /**
     * Cached column checks. Only a POSITIVE answer is ever kept: these outlive a
     * request in a long-lived worker, so caching "missing" would freeze
     * telemetry off for that worker's whole life if it happened to serve one
     * request in the gap between the code landing and the migration running.
     */
    private static bool $hasBaseColumns = false;
    private static bool $hasDeviceColumn = false;

    /** Test seam — the schema is rebuilt between tests. */
    public static function flushSchemaCache(): void
    {
        self::$hasBaseColumns = false;
        self::$hasDeviceColumn = false;
    }

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'depth' => ['required', 'integer', 'min:0', 'max:100000'],
            'oldest_at' => ['nullable', 'date'],
            'device' => ['nullable', 'string', 'max:64'],
        ]);

        $companyId = (int) (app()->bound('currentCompanyId') ? app('currentCompanyId') : 0);
        if ($companyId <= 0) {
            return response()->json(['success' => false], 422);
        }

        // Column guard: a deploy window where code lands before the migration
        // must never 500 the sale screen's background telemetry.
        if (!self::$hasBaseColumns) {
            self::$hasBaseColumns = Schema::hasColumn('companies', 'offline_queue_depth')
                && Schema::hasColumn('companies', 'offline_queue_oldest_at')
                && Schema::hasColumn('companies', 'offline_queue_reported_at');
        }
        if (!self::$hasBaseColumns) {
            return response()->json(['success' => true, 'stored' => false, 'reason' => 'no_columns']);
        }
        // The device column shipped one migration later than the other three;
        // guard it separately so a half-applied deploy still records depth.
        if (!self::$hasDeviceColumn) {
            self::$hasDeviceColumn = Schema::hasColumn('companies', 'offline_queue_device');
        }
        $hasDevice = self::$hasDeviceColumn;

        $depth = (int) $data['depth'];
        $device = $hasDevice ? trim((string) ($data['device'] ?? '')) : '';

        $oldest = null;
        if (!empty($data['oldest_at'])) {
            try {
                $oldest = \Carbon\Carbon::parse($data['oldest_at']);
                // A wrong PC clock must never post-date a queued bill.
                if ($oldest->gt(now())) {
                    $oldest = now();
                }
            } catch (\Throwable $e) {
                $oldest = null;
            }
        }

        $payload = [
            'offline_queue_depth' => $depth,
            'offline_queue_oldest_at' => $oldest,
            'offline_queue_reported_at' => now(),
        ];
        if ($hasDevice) {
            // Record WHICH till this came from — the zero-clearing rule below is
            // worthless if the device is never actually stored.
            $payload['offline_queue_device'] = $device !== '' ? $device : null;
        }

        // toBase(): a plain query update, no model events. Eloquent's own
        // update() would stamp updated_at on every beat, so a shop's companies
        // row would look freshly edited every thirty seconds on every till.
        // toBase() still applies the soft-delete scope, so a deleted company is
        // not silently written to.
        $query = Company::whereKey($companyId)->toBase();

        // A shop bills from several counters, and each one reports only its OWN
        // queue. An idle till must therefore never erase a busy till's stuck
        // bills — that would restore exactly the silence this telemetry exists
        // to break. A zero may only clear the record when it belongs to nobody,
        // is already empty, is stale enough to be worthless, or is ours.
        //
        // The condition rides ON the UPDATE rather than being read first: a
        // read-then-write leaves a window where another till raises a queue in
        // between and this zero flattens it anyway.
        if ($depth === 0 && $hasDevice) {
            $cutoff = now()->subHours(6);
            $query->where(function ($w) use ($device, $cutoff) {
                $w->whereNull('offline_queue_device')
                    ->orWhere('offline_queue_depth', '<=', 0)
                    ->orWhereNull('offline_queue_depth')
                    ->orWhereNull('offline_queue_reported_at')
                    ->orWhere('offline_queue_reported_at', '<=', $cutoff);
                // An empty device means an older cached sale screen that predates
                // the device key. It is still allowed to report a queue, but it
                // must not be trusted to clear one it may not own.
                if ($device !== '') {
                    $w->orWhere('offline_queue_device', $device);
                }
            });
        }

        $affected = $query->update($payload);

        // An update count is not an answer: MySQL reports CHANGED rows and
        // SQLite MATCHED ones, so a zero here is ambiguous. Resolve it by
        // reading the row back rather than trusting the number.
        if ($depth === 0 && $hasDevice && $affected === 0) {
            $current = Company::whereKey($companyId)
                ->first(['offline_queue_depth', 'offline_queue_device']);
            if ($current
                && (int) $current->offline_queue_depth > 0
                && (string) $current->offline_queue_device !== $device
            ) {
                return response()->json([
                    'success' => true,
                    'stored' => false,
                    'reason' => 'other_device_pending',
                ]);
            }
        }

        return response()->json(['success' => true, 'stored' => true]);
    }
}
