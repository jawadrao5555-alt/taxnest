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
        static $cols = null;
        if ($cols === null) {
            $cols = Schema::hasColumn('companies', 'offline_queue_depth')
                && Schema::hasColumn('companies', 'offline_queue_oldest_at')
                && Schema::hasColumn('companies', 'offline_queue_reported_at');
        }
        if (!$cols) {
            return response()->json(['success' => true, 'stored' => false]);
        }
        // The device column shipped one migration later than the other three;
        // guard it separately so a half-applied deploy still records depth.
        static $hasDevice = null;
        if ($hasDevice === null) {
            $hasDevice = Schema::hasColumn('companies', 'offline_queue_device');
        }

        $depth = (int) $data['depth'];
        $device = $hasDevice ? (string) ($data['device'] ?? '') : '';

        // A shop can bill from several counters. Each reports only its OWN
        // queue, so an idle till must not be allowed to erase a busy till's
        // stuck bills — that would restore exactly the silence this telemetry
        // exists to break. A zero therefore only clears the record when it
        // comes from the device that raised it, when no device is on record,
        // or when the record is old enough to be worthless anyway.
        if ($depth === 0 && $hasDevice && $device !== '') {
            $current = Company::whereKey($companyId)
                ->first(['offline_queue_depth', 'offline_queue_device', 'offline_queue_reported_at']);
            if ($current
                && (int) $current->offline_queue_depth > 0
                && !empty($current->offline_queue_device)
                && $current->offline_queue_device !== $device
                && $current->offline_queue_reported_at
                && $current->offline_queue_reported_at->gt(now()->subHours(6))
            ) {
                // Another counter is still holding bills; leave its report alone.
                return response()->json(['success' => true, 'stored' => false, 'reason' => 'other_device_pending']);
            }
        }

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
            // Record WHICH till this came from — the zero-clearing rule above is
            // worthless if the device is never actually stored.
            $payload['offline_queue_device'] = $device !== '' ? $device : null;
        }

        // toBase(): a plain query update, no model events. Eloquent's own
        // update() would stamp updated_at on every beat, so a shop's companies
        // row would look freshly edited every thirty seconds on every till.
        Company::whereKey($companyId)->toBase()->update($payload);

        return response()->json(['success' => true, 'stored' => true]);
    }
}
