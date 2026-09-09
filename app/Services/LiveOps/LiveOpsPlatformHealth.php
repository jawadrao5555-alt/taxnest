<?php

namespace App\Services\LiveOps;

use App\Services\HeartbeatHealth;
use App\Services\LogHealth;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only production platform signals for Live Ops.
 *
 * Never mutates services, never installs packages, never SSHes, never
 * prints secrets. Missing data is UNKNOWN — never fabricated.
 */
class LiveOpsPlatformHealth
{
    public function __construct(private LiveOpsRedactor $redactor)
    {
    }

    /**
     * @return array{
     *   cpu: array,
     *   memory: array,
     *   disk: array,
     *   processes: array,
     *   systemd: array,
     *   availability: string
     * }
     */
    public function server(): array
    {
        return [
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
            'processes' => $this->processes(),
            'systemd' => $this->systemdUnits(),
            'availability' => 'measured',
        ];
    }

    public function application(): array
    {
        $logHealth = null;
        $logHealthLastPass = null;
        try {
            $logHealth = LogHealth::current();
            $raw = SystemSetting::get('log_health_last_success_at');
            $logHealthLastPass = $raw ?: null;
        } catch (\Throwable $e) {
            // table missing in Live Ops harness / unreadable
        }

        $laravelLog = $this->laravelLogScan();

        return [
            'log_health_failure' => $logHealth,
            'log_health_last_success_at' => $logHealthLastPass,
            'laravel_log' => $laravelLog,
        ];
    }

    public function database(): array
    {
        $pingMs = null;
        $ok = false;
        $error = null;
        $started = microtime(true);
        try {
            DB::select('select 1 as ok');
            $ok = true;
            $pingMs = (int) round((microtime(true) - $started) * 1000);
        } catch (\Throwable $e) {
            $error = 'database ping failed';
        }

        $threads = null;
        $maxConn = null;
        $pct = null;
        $threadsAvailability = 'unknown';
        if ($ok) {
            try {
                $rows = DB::select("
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
                    $threads = $map['Threads_connected'];
                    $maxConn = $map['max_connections'];
                    $pct = round($threads / $maxConn * 100, 1);
                    $threadsAvailability = 'measured';
                }
            } catch (\Throwable $e) {
                try {
                    $t = DB::select("SHOW STATUS LIKE 'Threads_connected'");
                    $m = DB::select("SHOW VARIABLES LIKE 'max_connections'");
                    if (isset($t[0], $m[0]) && (int) $m[0]->Value > 0) {
                        $threads = (int) $t[0]->Value;
                        $maxConn = (int) $m[0]->Value;
                        $pct = round($threads / $maxConn * 100, 1);
                        $threadsAvailability = 'measured';
                    }
                } catch (\Throwable $e2) {
                    $threadsAvailability = 'unknown';
                }
            }
        }

        return [
            'connection_ok' => $ok,
            'ping_ms' => $pingMs,
            'error' => $error,
            'threads_connected' => $threads,
            'max_connections' => $maxConn,
            'threads_pct' => $pct,
            'threads_availability' => $threadsAvailability,
        ];
    }

    public function queue(): array
    {
        $heartbeatRaw = null;
        $heartbeatAt = null;
        try {
            $heartbeatRaw = SystemSetting::get('queue_last_heartbeat');
            if ($heartbeatRaw) {
                $heartbeatAt = Carbon::parse($heartbeatRaw);
            }
        } catch (\Throwable $e) {
            $heartbeatRaw = null;
        }

        $failedCount = null;
        $failedAvailability = 'unknown';
        $failedSamples = [];
        if (Schema::hasTable('failed_jobs')) {
            try {
                $failedCount = (int) DB::table('failed_jobs')->count();
                $failedAvailability = 'measured';
                $failedSamples = DB::table('failed_jobs')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get(['id', 'queue', 'failed_at', 'exception'])
                    ->map(function ($row) {
                        $ex = (string) ($row->exception ?? '');
                        $first = strtok($ex, "\n") ?: '';

                        return [
                            'id' => $row->id,
                            'queue' => $row->queue,
                            'failed_at' => $row->failed_at,
                            'exception_class' => $this->redactor->redactString(mb_substr($first, 0, 180)),
                        ];
                    })->all();
            } catch (\Throwable $e) {
                $failedCount = null;
                $failedAvailability = 'unknown';
            }
        }

        $stuckJobs = null;
        $stuckAvailability = 'unknown';
        if (Schema::hasTable('jobs')) {
            try {
                $threshold = now()->subMinutes(10)->getTimestamp();
                $stuckJobs = (int) DB::table('jobs')->where('created_at', '<', $threshold)->count();
                $stuckAvailability = 'measured';
            } catch (\Throwable $e) {
                $stuckJobs = null;
            }
        }

        $stale = null;
        if ($heartbeatAt) {
            $stale = $heartbeatAt->lt(now()->subMinutes(HeartbeatHealth::QUEUE_STALE_MINUTES));
        }

        return [
            'heartbeat_at' => $heartbeatAt?->toIso8601String(),
            'heartbeat_stale' => $stale,
            'heartbeat_availability' => $heartbeatAt ? 'measured' : 'unknown',
            'failed_jobs_count' => $failedCount,
            'failed_jobs_availability' => $failedAvailability,
            'failed_job_samples' => $failedSamples,
            'stuck_jobs_count' => $stuckJobs,
            'stuck_jobs_availability' => $stuckAvailability,
            'queue_connection' => config('queue.default'),
            'worker_process' => $this->processNamed(['queue:work', 'queue:listen']),
        ];
    }

    public function scheduler(): array
    {
        $heartbeatAt = null;
        try {
            $raw = SystemSetting::get('scheduler_last_heartbeat');
            if ($raw) {
                $heartbeatAt = Carbon::parse($raw);
            }
        } catch (\Throwable $e) {
            $heartbeatAt = null;
        }

        $stale = null;
        if ($heartbeatAt) {
            $stale = $heartbeatAt->lt(now()->subMinutes(HeartbeatHealth::SCHEDULER_STALE_MINUTES));
        }

        return [
            'heartbeat_at' => $heartbeatAt?->toIso8601String(),
            'heartbeat_stale' => $stale,
            'heartbeat_availability' => $heartbeatAt ? 'measured' : 'unknown',
            'schedule_run_process' => $this->processNamed(['schedule:run', 'schedule:work']),
        ];
    }

    public function websocket(): array
    {
        if (!$this->httpProbesEnabled()) {
            return [
                'availability' => 'unknown',
                'reason' => 'HTTP probes disabled in this environment',
                'ok' => null,
                'http_status' => null,
                'connections' => null,
                'ttfb_ms' => null,
            ];
        }

        $url = (string) config('live_ops.platform.websocket_health_url', 'http://127.0.0.1:6101/health');
        $probe = $this->timedGet($url);

        $ok = null;
        $connections = null;
        if (is_array($probe['json'] ?? null)) {
            $ok = (bool) ($probe['json']['ok'] ?? false);
            $connections = $probe['json']['connections'] ?? null;
        }

        return [
            'availability' => $probe['reached'] ? 'measured' : 'unknown',
            'reason' => $probe['reached'] ? null : ($probe['error'] ?? 'unreachable'),
            'ok' => $ok,
            'http_status' => $probe['status'],
            'connections' => is_numeric($connections) ? (int) $connections : null,
            'ttfb_ms' => $probe['ms'],
            // Do not echo wake secret or auth metrics beyond counts already public on /health.
        ];
    }

    public function performance(): array
    {
        if (!$this->httpProbesEnabled()) {
            return [
                'availability' => 'unknown',
                'reason' => 'HTTP probes disabled in this environment',
                'probes' => [],
            ];
        }

        $base = rtrim((string) config('live_ops.platform.public_url', config('app.url')), '/');
        $paths = [
            '/up' => 'public_up',
            '/pos/login' => 'pos_login',
        ];
        $probes = [];
        foreach ($paths as $path => $name) {
            $probes[$name] = $this->timedGet($base.$path);
            unset($probes[$name]['body_excerpt'], $probes[$name]['json']);
        }

        return [
            'availability' => 'measured',
            'base_url' => $base,
            'probes' => $probes,
        ];
    }

    public function security(Carbon $from, Carbon $to): array
    {
        $failedLogin = null;
        $failedLoginAvailability = 'unknown';
        $actionCounts = [];
        if (Schema::hasTable('security_logs')) {
            try {
                $q = DB::table('security_logs')
                    ->where('created_at', '>=', $from->copy()->startOfDay())
                    ->where('created_at', '<=', $to->copy()->endOfDay());
                $failedLogin = (int) (clone $q)->where('action', 'failed_login')->count();
                $actionCounts = (clone $q)
                    ->select('action', DB::raw('COUNT(*) as c'))
                    ->groupBy('action')
                    ->orderByDesc('c')
                    ->limit(15)
                    ->get()
                    ->map(fn ($r) => [
                        'action' => $r->action,
                        'count' => (int) $r->c,
                    ])->all();
                $failedLoginAvailability = 'measured';
            } catch (\Throwable $e) {
                $failedLogin = null;
            }
        }

        $env = (string) config('app.env');
        $demoEnabled = (bool) config('app.demo_login_enabled');
        $demoExposed = $demoEnabled && in_array($env, ['production', 'prod'], true);

        return [
            'failed_login_count' => $failedLogin,
            'failed_login_availability' => $failedLoginAvailability,
            'security_log_action_counts' => $actionCounts,
            'app_env' => $env,
            'debug' => (bool) config('app.debug'),
            'demo_login_enabled' => $demoEnabled,
            'demo_login_exposed_in_production' => $demoExposed,
            'note' => 'Counts only — IPs, user agents, and credentials are not included.',
        ];
    }

    private function httpProbesEnabled(): bool
    {
        if (app()->runningUnitTests() && !config('live_ops.platform.http_probes_in_tests')) {
            return false;
        }

        return (bool) config('live_ops.platform.http_probes', true);
    }

    /**
     * @return array{reached: bool, status: ?int, ms: ?int, error: ?string, json: mixed, body_excerpt: ?string}
     */
    private function timedGet(string $url): array
    {
        $timeout = (float) config('live_ops.platform.http_timeout_seconds', 5);
        $started = microtime(true);
        try {
            $response = Http::timeout((int) max(1, $timeout))
                ->connectTimeout((int) max(1, $timeout))
                ->withHeaders(['Accept' => 'text/html,application/json'])
                ->get($url);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $body = $response->body();
            $json = null;
            try {
                $json = $response->json();
            } catch (\Throwable $e) {
                $json = null;
            }

            return [
                'reached' => true,
                'status' => $response->status(),
                'ms' => $ms,
                'error' => null,
                'json' => is_array($json) ? $json : null,
                'body_excerpt' => $this->redactor->redactString(mb_substr(strip_tags($body), 0, 80)),
            ];
        } catch (\Throwable $e) {
            return [
                'reached' => false,
                'status' => null,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => 'probe failed',
                'json' => null,
                'body_excerpt' => null,
            ];
        }
    }

    private function cpu(): array
    {
        $load = null;
        if (function_exists('sys_getloadavg')) {
            $raw = @sys_getloadavg();
            if (is_array($raw) && count($raw) >= 3) {
                $load = [
                    '1m' => round((float) $raw[0], 2),
                    '5m' => round((float) $raw[1], 2),
                    '15m' => round((float) $raw[2], 2),
                ];
            }
        }
        $nproc = $this->cpuCount();

        return [
            'availability' => $load ? 'measured' : 'unknown',
            'load' => $load,
            'nproc' => $nproc,
        ];
    }

    private function cpuCount(): ?int
    {
        if (is_readable('/proc/cpuinfo')) {
            $text = @file_get_contents('/proc/cpuinfo');
            if (is_string($text)) {
                $n = preg_match_all('/^processor\s*:/m', $text);
                if ($n > 0) {
                    return $n;
                }
            }
        }

        return null;
    }

    private function memory(): array
    {
        if (!is_readable('/proc/meminfo')) {
            return ['availability' => 'unknown', 'reason' => '/proc/meminfo not readable'];
        }
        $text = @file_get_contents('/proc/meminfo');
        if (!is_string($text)) {
            return ['availability' => 'unknown', 'reason' => '/proc/meminfo unreadable'];
        }
        $map = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $map[$m[1]] = (int) $m[2];
            }
        }
        $total = $map['MemTotal'] ?? null;
        $available = $map['MemAvailable'] ?? null;
        $usedPct = null;
        if ($total && $available !== null && $total > 0) {
            $usedPct = round((($total - $available) / $total) * 100, 1);
        }

        return [
            'availability' => $total ? 'measured' : 'unknown',
            'mem_total_kb' => $total,
            'mem_available_kb' => $available,
            'used_pct' => $usedPct,
        ];
    }

    private function disk(): array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!$total || $total <= 0 || $free === false) {
            return ['availability' => 'unknown', 'reason' => 'disk_free_space unavailable'];
        }
        $usedPct = round((($total - $free) / $total) * 100, 1);

        return [
            'availability' => 'measured',
            'path' => $path,
            'total_bytes' => (int) $total,
            'free_bytes' => (int) $free,
            'used_pct' => $usedPct,
        ];
    }

    private function processes(): array
    {
        return [
            'php_fpm' => $this->processNamed(['php-fpm']),
            'apache' => $this->processNamed(['httpd', 'apache2']),
            'mariadb' => $this->processNamed(['mariadbd', 'mysqld']),
            'availability' => is_dir('/proc') ? 'measured' : 'unknown',
        ];
    }

    /**
     * @param list<string> $needles
     * @return array{found: bool, availability: string, match?: string}
     */
    private function processNamed(array $needles): array
    {
        if (!is_dir('/proc')) {
            return ['found' => false, 'availability' => 'unknown'];
        }
        $dh = @opendir('/proc');
        if ($dh === false) {
            return ['found' => false, 'availability' => 'unknown'];
        }
        while (($ent = readdir($dh)) !== false) {
            if (!ctype_digit($ent)) {
                continue;
            }
            $comm = @file_get_contents("/proc/{$ent}/comm");
            $cmd = @file_get_contents("/proc/{$ent}/cmdline");
            $hay = strtolower(trim((string) $comm).' '.str_replace("\0", ' ', (string) $cmd));
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($hay, strtolower($needle))) {
                    closedir($dh);

                    return ['found' => true, 'availability' => 'measured', 'match' => $needle];
                }
            }
        }
        closedir($dh);

        return ['found' => false, 'availability' => 'measured'];
    }

    /**
     * Read-only systemd is-active for allow-listed unit names only.
     *
     * @return array<string, array{unit: string, active: ?bool, availability: string}>
     */
    private function systemdUnits(): array
    {
        $units = config('live_ops.platform.systemd_units', []);
        $out = [];
        foreach ($units as $key => $unit) {
            $out[$key] = $this->systemctlIsActive((string) $unit);
        }

        return $out;
    }

    /**
     * @return array{unit: string, active: ?bool, availability: string}
     */
    private function systemctlIsActive(string $unit): array
    {
        if ($unit === '' || !preg_match('/^[a-zA-Z0-9_@.-]+$/', $unit)) {
            return ['unit' => $unit, 'active' => null, 'availability' => 'unknown'];
        }
        if (app()->runningUnitTests() && !config('live_ops.platform.systemd_in_tests')) {
            return ['unit' => $unit, 'active' => null, 'availability' => 'unknown'];
        }
        $bin = is_executable('/usr/bin/systemctl') ? '/usr/bin/systemctl'
            : (is_executable('/bin/systemctl') ? '/bin/systemctl' : null);
        if ($bin === null) {
            return ['unit' => $unit, 'active' => null, 'availability' => 'unknown'];
        }

        $cmd = [$bin, 'is-active', '--quiet', $unit];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['unit' => $unit, 'active' => null, 'availability' => 'unknown'];
        }
        foreach ($pipes as $p) {
            fclose($p);
        }
        $code = proc_close($proc);

        return [
            'unit' => $unit,
            'active' => $code === 0,
            'availability' => 'measured',
        ];
    }

    /**
     * @return array{availability: string, error_count: ?int, critical_count: ?int, samples: list<array>}
     */
    private function laravelLogScan(): array
    {
        $path = storage_path('logs/laravel.log');
        if (!is_readable($path)) {
            return [
                'availability' => 'unknown',
                'reason' => 'laravel.log not readable',
                'error_count' => null,
                'critical_count' => null,
                'samples' => [],
            ];
        }
        $maxBytes = (int) config('live_ops.platform.log_scan_bytes', 262144);
        $size = filesize($path);
        if ($size === false) {
            return [
                'availability' => 'unknown',
                'reason' => 'laravel.log size unreadable',
                'error_count' => null,
                'critical_count' => null,
                'samples' => [],
            ];
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [
                'availability' => 'unknown',
                'reason' => 'laravel.log open failed',
                'error_count' => null,
                'critical_count' => null,
                'samples' => [],
            ];
        }
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
        }
        $chunk = stream_get_contents($fh) ?: '';
        fclose($fh);

        $errorCount = preg_match_all('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $chunk, $m) ?: 0;
        $criticalCount = preg_match_all('/\.(CRITICAL|ALERT|EMERGENCY):/', $chunk, $m2) ?: 0;
        $http5xx = preg_match_all('/\bHTTP\/1\.[01]"\s*5\d\d\b|\bstatus[:\s]+5\d\d\b/i', $chunk) ?: 0;

        $samples = [];
        if (preg_match_all('/^\[.*?\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):.*$/m', $chunk, $lines)) {
            foreach (array_slice($lines[0], -8) as $line) {
                $samples[] = $this->redactor->redactString(mb_substr($line, 0, 240));
            }
        }

        return [
            'availability' => 'measured',
            'scanned_bytes' => strlen($chunk),
            'error_count' => $errorCount,
            'critical_count' => $criticalCount,
            'http_5xx_mentions' => $http5xx,
            'samples' => $samples,
            'note' => 'Tail scan of laravel.log only; not a complete log archive.',
        ];
    }
}
