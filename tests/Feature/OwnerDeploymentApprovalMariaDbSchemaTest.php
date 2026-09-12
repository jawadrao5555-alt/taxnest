<?php

namespace Tests\Feature;

use App\Support\OwnerDeploymentApprovalSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MariaDB identifier-length + idempotent create/reconcile for the owner
 * deployment approval table. SQLite PHPUnit is not evidence for this.
 *
 * Requires a disposable MySQL/MariaDB (never production). Connection is
 * registered as `odar_schema` and isolated in database taxnest_odar_test.
 *
 *   TAXNEST_REQUIRE_MARIADB_SCHEMA=1 php vendor/bin/phpunit tests/Feature/OwnerDeploymentApprovalMariaDbSchemaTest.php
 */
class OwnerDeploymentApprovalMariaDbSchemaTest extends TestCase
{
    private const CONNECTION = 'odar_schema';

    private static bool $ready = false;

    private static string $skipReason = '';

    /** @var array<string, mixed>|null */
    private static ?array $odarConfig = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$ready = false;
        self::$skipReason = '';
        self::$odarConfig = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::bootDisposableMariaDb()) {
            if (getenv('TAXNEST_REQUIRE_MARIADB_SCHEMA') === '1') {
                $this->fail(self::$skipReason !== '' ? self::$skipReason : 'MariaDB schema test connection unavailable');
            }
            $this->markTestSkipped(self::$skipReason !== '' ? self::$skipReason : 'no disposable MariaDB');
        }
        $this->ensureAdminUsers();
    }

    public function test_fresh_database_creates_short_named_indexes(): void
    {
        $this->dropOdarTable();
        $this->runCreateMigration();

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable(OwnerDeploymentApprovalSchema::TABLE));
        $prov = OwnerDeploymentApprovalSchema::indexNameOn(
            OwnerDeploymentApprovalSchema::TABLE,
            OwnerDeploymentApprovalSchema::PROVENANCE_COLUMNS,
            true,
            self::CONNECTION
        );
        $lookup = OwnerDeploymentApprovalSchema::indexNameOn(
            OwnerDeploymentApprovalSchema::TABLE,
            OwnerDeploymentApprovalSchema::REPO_LOOKUP_COLUMNS,
            false,
            self::CONNECTION
        );
        $this->assertNotNull($prov);
        $this->assertNotNull($lookup);
        $this->assertLessThanOrEqual(64, strlen((string) $prov));
        $this->assertLessThanOrEqual(64, strlen((string) $lookup));
        $this->assertSame(OwnerDeploymentApprovalSchema::PROVENANCE_UNIQUE, $prov);
        $this->assertSame(OwnerDeploymentApprovalSchema::REPO_LOOKUP, $lookup);
    }

    public function test_equivalent_legacy_index_names_are_left_unchanged(): void
    {
        $this->dropOdarTable();
        $this->createPartialTable();
        Schema::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE, function (Blueprint $table) {
            $table->unique(['provenance_receipt_hash'], 'legacy_prov_hash_uidx');
            $table->index(['repository', 'pull_request_number', 'head_sha'], 'legacy_repo_pr_sha');
        });

        $this->runCreateMigration();
        $this->runReconcileMigration();

        $this->assertSame(
            'legacy_prov_hash_uidx',
            OwnerDeploymentApprovalSchema::indexNameOn(
                OwnerDeploymentApprovalSchema::TABLE,
                OwnerDeploymentApprovalSchema::PROVENANCE_COLUMNS,
                true,
                self::CONNECTION
            )
        );
        $this->assertSame(
            'legacy_repo_pr_sha',
            OwnerDeploymentApprovalSchema::indexNameOn(
                OwnerDeploymentApprovalSchema::TABLE,
                OwnerDeploymentApprovalSchema::REPO_LOOKUP_COLUMNS,
                false,
                self::CONNECTION
            )
        );
        $names = collect(Schema::connection(self::CONNECTION)->getIndexes(OwnerDeploymentApprovalSchema::TABLE))
            ->pluck('name')
            ->all();
        $this->assertNotContains(OwnerDeploymentApprovalSchema::PROVENANCE_UNIQUE, $names);
        $this->assertNotContains(OwnerDeploymentApprovalSchema::REPO_LOOKUP, $names);
    }

    public function test_partial_table_missing_indexes_is_healed_without_dropping_rows(): void
    {
        $this->dropOdarTable();
        $this->createPartialTable();
        $adminId = (int) DB::connection(self::CONNECTION)->table('admin_users')->min('id');
        DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->insert($this->row($adminId, 'a'.str_repeat('0', 39), 'hash-one'.str_repeat('a', 56)));
        $this->assertSame(1, DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->count());

        $this->runReconcileMigration();

        $this->assertTrue(OwnerDeploymentApprovalSchema::hasEquivalentIndex(
            OwnerDeploymentApprovalSchema::TABLE,
            OwnerDeploymentApprovalSchema::PROVENANCE_COLUMNS,
            true,
            self::CONNECTION
        ));
        $this->assertTrue(OwnerDeploymentApprovalSchema::hasEquivalentIndex(
            OwnerDeploymentApprovalSchema::TABLE,
            OwnerDeploymentApprovalSchema::REPO_LOOKUP_COLUMNS,
            false,
            self::CONNECTION
        ));
        $this->assertSame(1, DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->count());
        $this->assertSame(
            OwnerDeploymentApprovalSchema::PROVENANCE_UNIQUE,
            OwnerDeploymentApprovalSchema::indexNameOn(
                OwnerDeploymentApprovalSchema::TABLE,
                OwnerDeploymentApprovalSchema::PROVENANCE_COLUMNS,
                true,
                self::CONNECTION
            )
        );
    }

    public function test_reconciliation_is_repeatable_and_does_not_drop_rows(): void
    {
        $this->dropOdarTable();
        $this->createPartialTable();
        $adminId = (int) DB::connection(self::CONNECTION)->table('admin_users')->min('id');
        DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->insert($this->row($adminId, 'b'.str_repeat('0', 39), 'hash-two'.str_repeat('b', 56)));

        $this->runReconcileMigration();
        $this->runReconcileMigration();

        $this->assertSame(1, DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->count());
        $this->assertTrue(OwnerDeploymentApprovalSchema::hasEquivalentIndex(
            OwnerDeploymentApprovalSchema::TABLE,
            OwnerDeploymentApprovalSchema::PROVENANCE_COLUMNS,
            true,
            self::CONNECTION
        ));
        $this->assertTrue(OwnerDeploymentApprovalSchema::hasEquivalentIndex(
            OwnerDeploymentApprovalSchema::TABLE,
            OwnerDeploymentApprovalSchema::REPO_LOOKUP_COLUMNS,
            false,
            self::CONNECTION
        ));
    }

    public function test_duplicate_provenance_receipt_hash_is_rejected(): void
    {
        $this->dropOdarTable();
        $this->runCreateMigration();
        $adminId = (int) DB::connection(self::CONNECTION)->table('admin_users')->min('id');
        $hash = str_repeat('c', 64);
        DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->insert($this->row($adminId, 'c'.str_repeat('0', 39), $hash));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection(self::CONNECTION)->table(OwnerDeploymentApprovalSchema::TABLE)->insert($this->row($adminId, 'd'.str_repeat('0', 39), $hash));
    }

    public function test_repository_pr_head_lookup_index_is_present(): void
    {
        $this->dropOdarTable();
        $this->runCreateMigration();
        $this->assertSame(
            OwnerDeploymentApprovalSchema::REPO_LOOKUP_COLUMNS,
            collect(Schema::connection(self::CONNECTION)->getIndexes(OwnerDeploymentApprovalSchema::TABLE))
                ->first(fn ($idx) => ($idx['name'] ?? '') === OwnerDeploymentApprovalSchema::REPO_LOOKUP)['columns'] ?? null
        );
    }

    private function runCreateMigration(): void
    {
        $this->withOdarDefault(function () {
            $migration = require database_path('migrations/2026_12_01_000000_create_owner_deployment_approval_requests.php');
            $migration->up();
        });
    }

    private function runReconcileMigration(): void
    {
        $this->withOdarDefault(function () {
            $migration = require database_path('migrations/2026_12_02_000000_reconcile_owner_deployment_approval_indexes.php');
            $migration->up();
        });
    }

    private function withOdarDefault(\Closure $fn): void
    {
        $previous = config('database.default');
        config(['database.default' => self::CONNECTION]);
        try {
            $fn();
        } finally {
            config(['database.default' => $previous]);
        }
    }

    private function dropOdarTable(): void
    {
        Schema::connection(self::CONNECTION)->dropIfExists(OwnerDeploymentApprovalSchema::TABLE);
    }

    private function ensureAdminUsers(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        if (!$schema->hasTable('admin_users')) {
            $schema->create('admin_users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role', 30)->default('admin');
                $table->timestamps();
            });
        }
        if (!DB::connection(self::CONNECTION)->table('admin_users')->exists()) {
            DB::connection(self::CONNECTION)->table('admin_users')->insert([
                'name' => 'ODAR Test Admin',
                'email' => 'odar-schema@example.test',
                'password' => 'x',
                'role' => 'super_admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createPartialTable(): void
    {
        Schema::connection(self::CONNECTION)->create(OwnerDeploymentApprovalSchema::TABLE, function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->unsignedInteger('pull_request_number');
            $table->char('head_sha', 40);
            $table->string('repository', 255);
            $table->string('status', 32);
            $table->unsignedBigInteger('requested_admin_id');
            $table->unsignedBigInteger('approved_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('dispatch_lease_id')->nullable();
            $table->timestamp('dispatch_lease_expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->char('provenance_receipt_hash', 64)->nullable();
            $table->timestamp('provenance_receipt_used_at')->nullable();
            $table->char('merge_sha', 40)->nullable();
            $table->char('owner_workflow_sha', 40)->nullable();
            $table->unsignedBigInteger('owner_workflow_run_id')->nullable();
            $table->unsignedInteger('owner_workflow_run_attempt')->nullable();
            $table->char('handoff_nonce_hash', 64)->nullable();
            $table->unsignedBigInteger('deployment_run_id')->nullable();
            $table->unsignedInteger('deployment_run_attempt')->nullable();
            $table->char('deployment_workflow_sha', 40)->nullable();
            $table->string('workflow_run_url', 500)->nullable();
            $table->string('deploy_result', 32)->nullable();
            $table->char('deployed_sha', 40)->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestamps();
        });
    }

    /** @return array<string, mixed> */
    private function row(int $adminId, string $headSha, string $hash): array
    {
        $now = now();

        return [
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'pull_request_number' => random_int(1, 999999),
            'head_sha' => $headSha,
            'repository' => 'jawadrao5555-alt/taxnest',
            'status' => 'pending',
            'requested_admin_id' => $adminId,
            'expires_at' => $now->copy()->addHour(),
            'provenance_receipt_hash' => $hash,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function bootDisposableMariaDb(): bool
    {
        if (self::$odarConfig !== null) {
            config(['database.connections.'.self::CONNECTION => self::$odarConfig]);
            DB::purge(self::CONNECTION);
            self::$ready = true;

            return true;
        }

        $host = getenv('TAXNEST_MARIADB_TEST_HOST') ?: '127.0.0.1';
        $ports = array_unique(array_filter([
            getenv('TAXNEST_MARIADB_TEST_PORT') ?: null,
            '3307',
            '3306',
        ]));
        $database = getenv('TAXNEST_MARIADB_TEST_DATABASE') ?: 'taxnest_odar_test';
        $user = getenv('TAXNEST_MARIADB_TEST_USERNAME') ?: 'taxnest_dev';
        $pass = getenv('TAXNEST_MARIADB_TEST_PASSWORD') ?: 'taxnest_local_dev_only';

        foreach ($ports as $port) {
            try {
                $admin = new \PDO(
                    "mysql:host={$host};port={$port};charset=utf8mb4",
                    'root',
                    '',
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                $admin->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $admin->exec("CREATE USER IF NOT EXISTS '{$user}'@'127.0.0.1' IDENTIFIED BY ".$admin->quote($pass));
                $admin->exec('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO \''.$user.'\'@\'127.0.0.1\'');
                $admin->exec('FLUSH PRIVILEGES');
            } catch (\Throwable $e) {
                // Root may be unavailable; try the app user against an existing DB.
            }

            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->query('SELECT 1');
            } catch (\Throwable $e) {
                self::$skipReason = 'MariaDB '.$host.':'.$port.' '.$database.' — '.$e->getMessage();
                continue;
            }

            self::$odarConfig = [
                'driver' => 'mysql',
                'host' => $host,
                'port' => (int) $port,
                'database' => $database,
                'username' => $user,
                'password' => $pass,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];
            config(['database.connections.'.self::CONNECTION => self::$odarConfig]);
            DB::purge(self::CONNECTION);
            try {
                DB::connection(self::CONNECTION)->getPdo();
            } catch (\Throwable $e) {
                self::$skipReason = $e->getMessage();
                continue;
            }
            self::$ready = true;
            self::$skipReason = '';

            return true;
        }

        return false;
    }
}
