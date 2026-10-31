<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ElaanReceiptBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ELAAN_RECEIPT_FUNCTIONS_ONLY')) define('ELAAN_RECEIPT_FUNCTIONS_ONLY', true);
        require_once base_path('scripts/lib/elaan-receipt.php');
        Schema::dropAllTables();
        Schema::create('app_updates', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->string('audience');
            $t->boolean('is_published'); $t->string('created_at');
        });
    }

    public function test_legacy_schema_prepares_before_publication_preserving_existing_rows_and_later_migrations(): void
    {
        DB::table('app_updates')->insert(['title'=>'Old notice','audience'=>'pos','is_published'=>1,'created_at'=>'2026-10-01 12:00:00']);
        \elaanReceiptPrepare(); \elaanReceiptPrepare();
        $this->assertTrue(Schema::hasColumn('app_updates','deployment_key'));
        $this->assertTrue(Schema::hasColumn('app_updates','target_categories'));
        $row=DB::table('app_updates')->sole();
        $this->assertSame('Old notice',$row->title);
        $this->assertSame('2026-10-01 12:00:00',$row->created_at);
        $this->assertNull($row->deployment_key);
        foreach (['2026_11_24_100000_add_target_categories_to_app_updates.php','2026_12_03_000000_add_internal_deployment_key_to_app_updates.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->assertSame(1, DB::table('app_updates')->count());
    }

    public function test_counts_use_the_application_connection_and_reject_wrong_scope_old_key_unpublished_and_archived_rows(): void
    {
        \elaanReceiptPrepare();
        Schema::table('app_updates',fn(Blueprint $t)=>$t->string('archived_at')->nullable());
        $sha=str_repeat('a',40);
        $base=['title'=>'Fictional','audience'=>'pos','is_published'=>1,'created_at'=>'2026-10-31 12:00:00','deployment_key'=>$sha];
        DB::table('app_updates')->insert($base);
        DB::table('app_updates')->insert(array_replace($base,['deployment_key'=>str_repeat('b',40)]));
        $scope="is_published=1 AND audience='pos' AND target_categories IS NULL";
        $this->assertSame(1,\elaanReceiptCount($scope,$sha,'2026-10-30 12:00:00'));
        $this->assertSame(0,\elaanReceiptCount($scope,$sha,'2026-10-31 12:00:00'));
        $this->assertSame(1,\elaanReceiptCount($scope,$sha,null));
        $this->assertSame(0,\elaanReceiptCount("is_published=1 AND audience='di'",$sha,null));
        DB::table('app_updates')->where('deployment_key',$sha)->update(['is_published'=>0]);
        $this->assertSame(0,\elaanReceiptCount($scope,$sha,null));
        DB::table('app_updates')->where('deployment_key',$sha)->update(['is_published'=>1,'archived_at'=>'2026-10-31 12:01:00']);
        $this->assertSame(0,\elaanReceiptCount($scope,$sha,null));
    }

    public function test_missing_receipt_schema_is_denied_without_mutation(): void
    {
        $this->expectExceptionMessage('ELAAN_SCHEMA_KEY_MISSING');
        \elaanReceiptCount('is_published=1',str_repeat('a',40),null);
    }
    public function test_failure_diagnostics_expose_only_allowlisted_stage_and_numeric_database_codes(): void
    {
        $error = new \PDOException('secret-password SQL bindings customer data');
        $error->errorInfo = ['HY000', 1267, 'secret-password host and query'];
        $wrapped = new \RuntimeException('private path and credentials', 0, $error);
        $this->assertSame('ELAAN_DB_ERROR stage=fresh_query sqlstate=HY000 driver=1267', \elaanReceiptFailure($wrapped, 'fresh_query'));
        $this->assertSame('ELAAN_DB_ERROR stage=marker', \elaanReceiptFailure(new \RuntimeException('ELAAN_SECRET_PASSWORD'), 'marker'));
        $this->assertSame('ELAAN_DB_ERROR stage=unknown', \elaanReceiptFailure(new \Exception('secret'), 'secret'));
        $error->errorInfo = ['secret', 'private', 'secret'];
        $this->assertSame('ELAAN_DB_ERROR stage=bootstrap', \elaanReceiptFailure($error, 'bootstrap'));
        $this->assertSame('ELAAN_SCHEMA_KEY_MISSING', \elaanReceiptFailure(new \RuntimeException('ELAAN_SCHEMA_KEY_MISSING'), 'fresh_query'));
    }

    public function test_real_cli_bootstrap_returns_a_receipt_and_sanitizes_query_failures(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'elaan-cli-db-');
        $marker = tempnam(sys_get_temp_dir(), 'elaan-cli-marker-');
        try {
            $sha = str_repeat('a', 40);
            $pdo = new \PDO('sqlite:'.$database);
            $pdo->exec('CREATE TABLE app_updates (audience TEXT,is_published INTEGER,deployment_key TEXT,created_at TEXT)');
            $pdo->prepare('INSERT INTO app_updates VALUES (?,?,?,?)')->execute(['pos', 1, $sha, '2026-10-31 12:00:00']);
            file_put_contents($marker, '1|'.$sha);
            $run = function (string $scope) use ($database, $marker, $sha) {
                $process = new \Symfony\Component\Process\Process(
                    [PHP_BINARY, '-c', php_ini_loaded_file(), base_path('scripts/lib/elaan-receipt.php')],
                    base_path(), ['LIVE_DIR'=>base_path(), 'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>$database,
                        'MARKER_FILE'=>$marker, 'TARGET_SHA'=>$sha, 'SCOPE_B64'=>base64_encode($scope)]
                );
                $process->run();
                return $process;
            };
            $ok = $run("is_published=1 AND audience='pos'");
            $this->assertSame(0, $ok->getExitCode(), $ok->getOutput().$ok->getErrorOutput());
            $this->assertSame("ELAAN_COUNT=1 MARKER_COMMIT=$sha RECEIPT_COUNT=1\n", $ok->getOutput());
            $bad = $run('private_customer_field=1');
            $this->assertSame(1, $bad->getExitCode());
            $this->assertSame("ELAAN_DB_ERROR stage=fresh_query sqlstate=HY000 driver=1\n", $bad->getOutput());
            $this->assertSame('', $bad->getErrorOutput());
        } finally {
            unlink($database);
            unlink($marker);
        }
    }

}
