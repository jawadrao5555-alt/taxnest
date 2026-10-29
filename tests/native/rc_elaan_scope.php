<?php
declare(strict_types=1);

// Actual MariaDB JSON scope semantics in the isolated, migrated RC database.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe announcement scope target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AppUpdate;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    foreach (['pos' => 'restaurant', 'fbr_pos' => 'pharmacy', 'di' => 'di_services', 'all' => 'salon'] as $audience => $category) {
        foreach ([[], [$category]] as $categories) {
            $sha = bin2hex(random_bytes(20));
            $attrs = ['title' => 'Fictional scope proof', 'points' => ['Disposable evidence'],
                'audience' => $audience, 'target_categories' => $categories, 'is_published' => true,
                'deployment_key' => $sha];
            $wanted = AppUpdate::create($attrs);
            $file = dirname($socket).'/scope-'.bin2hex(random_bytes(8)).'.yml';
            $spec = "title: Fictional scope proof\naudience: $audience\npoints:\n  - Disposable evidence\n";
            if ($categories) $spec .= "category: $category\n";
            file_put_contents($file, $spec);
            try {
                $process = proc_open(['python3', dirname(__DIR__, 2).'/scripts/lib/elaan-scope-sql.py', $file, $sha],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($process)) throw new RuntimeException('Scope helper launch failed');
                $scope = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process) !== 0) throw new RuntimeException('Scope helper failed: '.$error);
            } finally { unlink($file); }
            $rows = DB::select('SELECT id FROM app_updates WHERE '.$scope.' AND deployment_key=? AND archived_at IS NULL', [$sha]);
            if (array_map(fn ($row) => (int) $row->id, $rows) !== [$wanted->id]) {
                throw new RuntimeException('Exact audience/categories/receipt scope mismatch');
            }
            // deployment_key is unique: mutate the one fictional receipt,
            // restoring it between cases, rather than fabricating duplicates.
            $originalReceipt = (array) DB::table('app_updates')->where('id', $wanted->id)->first();
            foreach ([
                ['audience' => $audience === 'pos' ? 'all' : 'pos'],
                ['deployment_key' => str_repeat('b', 40)],
                ['is_published' => false],
                ['archived_at' => now()],
                ['target_categories' => json_encode($categories ? [] : [$category])],
            ] as $change) {
                $original = array_intersect_key($originalReceipt, $change);
                DB::table('app_updates')->where('id', $wanted->id)->update($change);
                if (DB::select('SELECT id FROM app_updates WHERE '.$scope.' AND deployment_key=? AND archived_at IS NULL', [$sha])) {
                    throw new RuntimeException('Ineligible receipt passed scope');
                }
                DB::table('app_updates')->where('id', $wanted->id)->update($original);
                if (count(DB::select('SELECT id FROM app_updates WHERE '.$scope.' AND deployment_key=? AND archived_at IS NULL', [$sha])) !== 1) {
                    throw new RuntimeException('Restored valid receipt no longer passes');
                }
            }
            DB::table('app_updates')->where('id', $wanted->id)->delete();
            if (DB::select('SELECT id FROM app_updates WHERE '.$scope.' AND deployment_key=? AND archived_at IS NULL', [$sha])) {
                throw new RuntimeException('Deleted receipt passed scope');
            }
        }
    }
    echo "PASS: eight actual MariaDB receipt/category scopes exclude wrong audience, SHA, publication, archive and category\n";
} finally { DB::rollBack(); }
