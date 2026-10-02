<?php
declare(strict_types=1);

// Run only against the isolated, fully migrated RC database, before booting Laravel.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV') !== 'rc-mariadb'
    || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe notification concurrency target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$attrs = [
    'title' => 'Synthetic notification concurrency',
    'points' => ['A single customer notice'],
    'audience' => 'pos', 'audience_family' => 'all', 'type' => 'improvement',
    'is_published' => true, 'is_featured' => false,
];
$key = (new \App\Models\AppUpdate($attrs))->contentKey();

if (($argv[1] ?? '') === 'worker') {
    $barrier = $argv[3];
    if (!str_starts_with($barrier, '/tmp/taxnest-rc-mariadb-')) {
        throw new RuntimeException('Unsafe barrier');
    }
    file_put_contents($barrier.'.ready.'.$argv[4], 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Barrier timeout');
        usleep(10000);
    }
    if ($argv[2] === 'publish') {
        \App\Models\AppUpdate::firstOrCreate(['manual_publish_key' => $key], $attrs);
    } elseif ($argv[2] === 'reannounce') {
        (new \App\Http\Controllers\AppUpdateController())->reannounce((int) $argv[5]);
    } else {
        throw new RuntimeException('Unknown worker mode');
    }
    echo "worker passed\n";
    exit(0);
}

function race(string $mode, int $sourceId = 0): void {
    $barrier = dirname(getenv('DB_SOCKET')).'/notification-'.bin2hex(random_bytes(8));
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, $barrier, (string) $i, (string) $sourceId],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Worker launch failed');
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 25;
        while (!is_file($barrier.'.ready.0') || !is_file($barrier.'.ready.1')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Workers failed before barrier');
            usleep(10000);
        }
        file_put_contents($barrier, 'go');
        do {
            $running = false;
            foreach ($workers as &$worker) {
                foreach ($worker['pipes'] as $pipe) $worker['output'] .= stream_get_contents($pipe);
                if ($worker['exit'] !== null) continue;
                $status = proc_get_status($worker['process']);
                if ($status['running']) $running = true;
                else $worker['exit'] = $status['exitcode'];
            }
            unset($worker);
            if ($running && microtime(true) > $deadline) throw new RuntimeException('Worker timeout');
            if ($running) usleep(10000);
        } while ($running);
        foreach ($workers as $worker) {
            if ($worker['exit'] !== 0) throw new RuntimeException('Concurrency worker failed: '.$worker['output']);
        }
    } finally {
        foreach ($workers as $worker) {
            if (proc_get_status($worker['process'])['running']) proc_terminate($worker['process']);
            foreach ($worker['pipes'] as $pipe) fclose($pipe);
            proc_close($worker['process']);
        }
        foreach ([$barrier, $barrier.'.ready.0', $barrier.'.ready.1'] as $file) @unlink($file);
    }
}

race('publish');
$matches = \App\Models\AppUpdate::where('manual_publish_key', $key)->get();
if ($matches->count() !== 1) throw new RuntimeException('Repeated concurrent publication created multiple notices');
$source = $matches->first();
$oldTimestamp = now()->subDays(8)->startOfSecond();
\Illuminate\Support\Facades\DB::table('app_updates')->where('id', $source->id)
    ->update(['created_at' => $oldTimestamp, 'updated_at' => $oldTimestamp]);
race('reannounce', $source->id);
$revisions = \App\Models\AppUpdate::where('announcement_parent_id', $source->id)->get();
if ($revisions->count() !== 1 || !$revisions->first()->announcement_revision
    || $revisions->first()->notification_key === $source->notification_key
    || !$source->fresh()->created_at->equalTo($oldTimestamp)) {
    throw new RuntimeException('Concurrent reannouncement did not preserve one new revision and original timestamp');
}
echo "PASS: real concurrent Laravel publication=1; concurrent reannounce revision=1; original timestamp preserved\n";
