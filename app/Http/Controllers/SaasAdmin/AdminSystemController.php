<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemControl;
use App\Models\SystemSetting;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminSystemController extends Controller
{
    public function index()
    {
        $controls = SystemControl::orderBy('key')->get();

        $heartbeatRaw = SystemSetting::get('scheduler_last_heartbeat');
        $heartbeatAt = null;
        $heartbeatStale = true;
        if ($heartbeatRaw) {
            try {
                $heartbeatAt = \Carbon\Carbon::parse($heartbeatRaw);
                $heartbeatStale = $heartbeatAt->lt(now()->subHours(26));
            } catch (\Throwable $e) {
                $heartbeatAt = null;
            }
        }

        // Queue worker health: a queue-processed heartbeat + stuck-job count.
        $queueHeartbeatRaw = SystemSetting::get('queue_last_heartbeat');
        $queueHeartbeatAt = null;
        if ($queueHeartbeatRaw) {
            try {
                $queueHeartbeatAt = \Carbon\Carbon::parse($queueHeartbeatRaw);
            } catch (\Throwable $e) {
                $queueHeartbeatAt = null;
            }
        }

        $stuckJobs = 0;
        $oldestStuckAt = null;
        try {
            $threshold = now()->subMinutes(10)->getTimestamp();
            $stuckJobs = \Illuminate\Support\Facades\DB::table('jobs')
                ->where('created_at', '<', $threshold)
                ->count();
            if ($stuckJobs > 0) {
                $oldestTs = \Illuminate\Support\Facades\DB::table('jobs')->min('created_at');
                if ($oldestTs) {
                    $oldestStuckAt = \Carbon\Carbon::createFromTimestamp((int) $oldestTs);
                }
            }
        } catch (\Throwable $e) {
            // jobs table missing or unreadable — treat as no stuck jobs.
        }

        // Stale if: jobs are stuck, or the queue heartbeat is older than 30
        // minutes (job is dispatched every 5 min), or never recorded at all
        // while the scheduler IS running (scheduler dead already flags red above).
        $queueStale = $stuckJobs > 0
            || ($queueHeartbeatAt && $queueHeartbeatAt->lt(now()->subMinutes(30)))
            || (!$queueHeartbeatAt && $heartbeatAt && !$heartbeatStale);

        // MySQL connection health: Threads_connected vs max_connections.
        $mysqlThreads = null;
        $mysqlMaxConn = null;
        $mysqlPct     = null;
        try {
            $rows = \Illuminate\Support\Facades\DB::select("
                SELECT 'Threads_connected' AS name, VARIABLE_VALUE AS value
                  FROM information_schema.GLOBAL_STATUS
                 WHERE VARIABLE_NAME = 'Threads_connected'
                UNION ALL
                SELECT 'max_connections' AS name, VARIABLE_VALUE AS value
                  FROM information_schema.GLOBAL_VARIABLES
                 WHERE VARIABLE_NAME = 'max_connections'
            ");
            $map = [];
            foreach ($rows as $row) {
                $map[$row->name] = (int) $row->value;
            }
            if (isset($map['Threads_connected'], $map['max_connections']) && $map['max_connections'] > 0) {
                $mysqlThreads = $map['Threads_connected'];
                $mysqlMaxConn = $map['max_connections'];
                $mysqlPct     = round($mysqlThreads / $mysqlMaxConn * 100, 1);
            }
        } catch (\Throwable $e) {
            // Fallback: SHOW STATUS / SHOW VARIABLES (older MariaDB versions).
            try {
                $t = \Illuminate\Support\Facades\DB::select("SHOW STATUS LIKE 'Threads_connected'");
                $m = \Illuminate\Support\Facades\DB::select("SHOW VARIABLES LIKE 'max_connections'");
                if (isset($t[0], $m[0]) && (int) $m[0]->Value > 0) {
                    $mysqlThreads = (int) $t[0]->Value;
                    $mysqlMaxConn = (int) $m[0]->Value;
                    $mysqlPct     = round($mysqlThreads / $mysqlMaxConn * 100, 1);
                }
            } catch (\Throwable $e2) {
                // Unavailable — view will render a "Could not read" state.
            }
        }

        // Logging health: daily logs:health-check probe (LogHealth service).
        $logHealthFailure = \App\Services\LogHealth::current();
        $logHealthLastPassRaw = SystemSetting::get('log_health_last_success_at');
        $logHealthLastPassAt = null;
        if ($logHealthLastPassRaw) {
            try {
                $logHealthLastPassAt = \Carbon\Carbon::parse($logHealthLastPassRaw);
            } catch (\Throwable $e) {
                $logHealthLastPassAt = null;
            }
        }
        // Stale if the last pass is older than 26h AND no active failure is
        // recorded — means the daily probe itself stopped running (cron gap).
        $logHealthStale = !$logHealthFailure
            && (!$logHealthLastPassAt || $logHealthLastPassAt->lt(now()->subHours(26)));

        return view('saas-admin.system-control', compact(
            'controls', 'heartbeatAt', 'heartbeatStale',
            'queueHeartbeatAt', 'queueStale', 'stuckJobs', 'oldestStuckAt',
            'logHealthFailure', 'logHealthLastPassAt', 'logHealthStale',
            'mysqlThreads', 'mysqlMaxConn', 'mysqlPct'
        ));
    }

    public function toggle(Request $request, $key)
    {
        $control = SystemControl::toggle($key, auth('admin')->id());
        AdminAuditLog::log(auth('admin')->id(), "System control toggled: {$key}", 'SystemControl', $control->id, [
            'new_value' => $control->value,
        ]);
        return back()->with('success', "'{$key}' is now {$control->value}.");
    }
}
