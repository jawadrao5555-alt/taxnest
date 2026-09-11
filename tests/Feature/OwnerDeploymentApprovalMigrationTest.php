<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OwnerDeploymentApprovalMigrationTest extends TestCase
{
    private const TABLE = 'owner_deployment_approval_requests';

    private function migration(): object
    {
        $path = base_path('database/migrations/2026_12_01_000000_create_owner_deployment_approval_requests.php');

        return eval('?>'.file_get_contents($path));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
        });
        DB::table('admin_users')->insert(['id' => 1]);
    }

    public function test_fresh_database_creates_the_intended_schema(): void
    {
        $this->migration()->up();

        $this->assertSame([], array_values(array_diff(
            $this->expectedColumns(),
            Schema::getColumnListing(self::TABLE)
        )));
        $this->assertTrue(Schema::hasIndex(self::TABLE, 'odpr_repo_pr_sha_idx'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, 'odpr_prov_receipt_hash_uidx'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['status']));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['dispatch_lease_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['deployment_run_id'], 'unique'));
        $this->assertNotSame([], Schema::getForeignKeys(self::TABLE));
    }

    public function test_known_empty_partial_table_is_completed_without_dropping(): void
    {
        $this->createProductionPartialTable();
        $this->assertSame(0, (int) DB::table(self::TABLE)->count());
        $this->assertFalse(Schema::hasIndex(self::TABLE, 'odpr_repo_pr_sha_idx'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(0, (int) DB::table(self::TABLE)->count());
        $this->assertSame([], array_values(array_diff(
            $this->expectedColumns(),
            Schema::getColumnListing(self::TABLE)
        )));
        $this->assertTrue(Schema::hasIndex(self::TABLE, 'odpr_repo_pr_sha_idx'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, 'odpr_prov_receipt_hash_uidx'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['status']));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['expires_at']));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['dispatch_lease_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['merge_sha']));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['deployment_run_id'], 'unique'));
        $this->assertTrue($this->hasForeignKey('requested_admin_id'));
        $this->assertTrue($this->hasForeignKey('approved_admin_id'));
    }

    public function test_missing_column_on_empty_table_is_added(): void
    {
        $this->createProductionPartialTable();
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn('workflow_run_url');
        });
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'workflow_run_url'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'workflow_run_url'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, 'odpr_repo_pr_sha_idx'));
    }

    public function test_unexpected_columns_fail_closed(): void
    {
        $this->createProductionPartialTable();
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('legacy_unexpected_col')->nullable();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected columns');
        $this->migration()->up();
    }

    public function test_non_empty_table_fails_closed_without_dropping_rows(): void
    {
        $this->createProductionPartialTable();
        DB::table(self::TABLE)->insert([
            'request_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'pull_request_number' => 48,
            'head_sha' => str_repeat('a', 40),
            'repository' => 'jawadrao5555-alt/taxnest',
            'status' => 'pending',
            'requested_admin_id' => 1,
            'expires_at' => '2026-09-11 12:00:00',
        ]);

        try {
            $this->migration()->up();
            $this->fail('Non-empty table must fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has 1 row', $e->getMessage());
        }

        $this->assertSame(1, (int) DB::table(self::TABLE)->count());
        $this->assertFalse(Schema::hasIndex(self::TABLE, 'odpr_repo_pr_sha_idx'));
    }

    /** Reproduce the known production leftover: all columns, PK, two FKs, no secondary indexes. */
    private function createProductionPartialTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->unsignedInteger('pull_request_number');
            $table->char('head_sha', 40);
            $table->string('repository', 255);
            $table->string('status', 32);
            $table->foreignId('requested_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('approved_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
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

    /** @return list<string> */
    private function expectedColumns(): array
    {
        return [
            'request_id',
            'pull_request_number',
            'head_sha',
            'repository',
            'status',
            'requested_admin_id',
            'approved_admin_id',
            'approved_at',
            'expires_at',
            'dispatch_lease_id',
            'dispatch_lease_expires_at',
            'claimed_at',
            'provenance_receipt_hash',
            'provenance_receipt_used_at',
            'merge_sha',
            'owner_workflow_sha',
            'owner_workflow_run_id',
            'owner_workflow_run_attempt',
            'handoff_nonce_hash',
            'deployment_run_id',
            'deployment_run_attempt',
            'deployment_workflow_sha',
            'workflow_run_url',
            'deploy_result',
            'deployed_sha',
            'failure_summary',
            'created_at',
            'updated_at',
        ];
    }

    private function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreign) {
            if (($foreign['columns'] ?? []) === [$column]
                && ($foreign['foreign_table'] ?? '') === 'admin_users') {
                return true;
            }
        }

        return false;
    }
}
