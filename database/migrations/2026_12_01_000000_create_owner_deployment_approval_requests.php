<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner deployment-approval relay table.
 *
 * Production on b879f29e created this table, then failed while adding Laravel's
 * auto-named composite index (identifier longer than MySQL's 64-char limit).
 * The empty table and its foreign keys were left behind; the migration stayed
 * Pending. Schema::create() would then fail with "table already exists".
 *
 * up() creates the table when missing, or non-destructively completes the
 * known empty partial table. It never drops the table or existing columns.
 * Unexpected columns or any existing rows fail closed.
 */
return new class extends Migration
{
    private const TABLE = 'owner_deployment_approval_requests';

    private const COMPOSITE_INDEX = 'odpr_repo_pr_sha_idx';

    private const PROVENANCE_UNIQUE = 'odpr_prov_receipt_hash_uidx';

    /** @var list<string> */
    private const EXPECTED_COLUMNS = [
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

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createFresh();

            return;
        }

        $this->completeExistingEmptyTable();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function createFresh(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $this->defineColumns($table);
            $table->index(['repository', 'pull_request_number', 'head_sha'], self::COMPOSITE_INDEX);
        });
    }

    private function defineColumns(Blueprint $table): void
    {
        $table->uuid('request_id')->primary();
        $table->unsignedInteger('pull_request_number');
        $table->char('head_sha', 40);
        $table->string('repository', 255);
        $table->string('status', 32)->index();
        $table->foreignId('requested_admin_id')->constrained('admin_users')->restrictOnDelete();
        $table->foreignId('approved_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
        $table->timestamp('approved_at')->nullable();
        $table->timestamp('expires_at')->index();
        $table->uuid('dispatch_lease_id')->nullable()->unique();
        $table->timestamp('dispatch_lease_expires_at')->nullable();
        $table->timestamp('claimed_at')->nullable();
        $table->char('provenance_receipt_hash', 64)->nullable()->unique(self::PROVENANCE_UNIQUE);
        $table->timestamp('provenance_receipt_used_at')->nullable();
        $table->char('merge_sha', 40)->nullable()->index();
        $table->char('owner_workflow_sha', 40)->nullable();
        $table->unsignedBigInteger('owner_workflow_run_id')->nullable();
        $table->unsignedInteger('owner_workflow_run_attempt')->nullable();
        $table->char('handoff_nonce_hash', 64)->nullable();
        $table->unsignedBigInteger('deployment_run_id')->nullable()->unique();
        $table->unsignedInteger('deployment_run_attempt')->nullable();
        $table->char('deployment_workflow_sha', 40)->nullable();
        $table->string('workflow_run_url', 500)->nullable();
        $table->string('deploy_result', 32)->nullable();
        $table->char('deployed_sha', 40)->nullable();
        $table->text('failure_summary')->nullable();
        $table->timestamps();
    }

    private function completeExistingEmptyTable(): void
    {
        $count = (int) DB::table(self::TABLE)->count();
        if ($count > 0) {
            throw new RuntimeException(
                self::TABLE.' already has '.$count.' row(s); refusing non-destructive recovery of a non-empty table'
            );
        }

        if (! Schema::hasColumn(self::TABLE, 'request_id')) {
            throw new RuntimeException(
                self::TABLE.' exists without request_id; refusing recovery of an incompatible schema'
            );
        }

        $existing = Schema::getColumnListing(self::TABLE);
        $unexpected = array_values(array_diff($existing, self::EXPECTED_COLUMNS));
        if ($unexpected !== []) {
            throw new RuntimeException(
                self::TABLE.' has unexpected columns ('.implode(', ', $unexpected).'); refusing recovery of an incompatible schema'
            );
        }

        $this->addMissingColumns();
        $this->ensureRequiredIndexes();
        $this->ensureRequiredForeignKeys();
    }

    private function addMissingColumns(): void
    {
        $missing = false;
        foreach (self::EXPECTED_COLUMNS as $column) {
            if ($column !== 'request_id' && ! Schema::hasColumn(self::TABLE, $column)) {
                $missing = true;
                break;
            }
        }
        if (! $missing) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'pull_request_number')) {
                $table->unsignedInteger('pull_request_number');
            }
            if (! Schema::hasColumn(self::TABLE, 'head_sha')) {
                $table->char('head_sha', 40);
            }
            if (! Schema::hasColumn(self::TABLE, 'repository')) {
                $table->string('repository', 255);
            }
            if (! Schema::hasColumn(self::TABLE, 'status')) {
                $table->string('status', 32);
            }
            if (! Schema::hasColumn(self::TABLE, 'requested_admin_id')) {
                $table->unsignedBigInteger('requested_admin_id');
            }
            if (! Schema::hasColumn(self::TABLE, 'approved_admin_id')) {
                $table->unsignedBigInteger('approved_admin_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'expires_at')) {
                $table->timestamp('expires_at');
            }
            if (! Schema::hasColumn(self::TABLE, 'dispatch_lease_id')) {
                $table->uuid('dispatch_lease_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'dispatch_lease_expires_at')) {
                $table->timestamp('dispatch_lease_expires_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'provenance_receipt_hash')) {
                $table->char('provenance_receipt_hash', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'provenance_receipt_used_at')) {
                $table->timestamp('provenance_receipt_used_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'merge_sha')) {
                $table->char('merge_sha', 40)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'owner_workflow_sha')) {
                $table->char('owner_workflow_sha', 40)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'owner_workflow_run_id')) {
                $table->unsignedBigInteger('owner_workflow_run_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'owner_workflow_run_attempt')) {
                $table->unsignedInteger('owner_workflow_run_attempt')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'handoff_nonce_hash')) {
                $table->char('handoff_nonce_hash', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'deployment_run_id')) {
                $table->unsignedBigInteger('deployment_run_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'deployment_run_attempt')) {
                $table->unsignedInteger('deployment_run_attempt')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'deployment_workflow_sha')) {
                $table->char('deployment_workflow_sha', 40)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'workflow_run_url')) {
                $table->string('workflow_run_url', 500)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'deploy_result')) {
                $table->string('deploy_result', 32)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'deployed_sha')) {
                $table->char('deployed_sha', 40)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'failure_summary')) {
                $table->text('failure_summary')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureRequiredIndexes(): void
    {
        $this->ensureIndex(['status'], 'owner_deployment_approval_requests_status_index');
        $this->ensureIndex(['expires_at'], 'owner_deployment_approval_requests_expires_at_index');
        $this->ensureUnique(['dispatch_lease_id'], 'owner_deployment_approval_requests_dispatch_lease_id_unique');
        $this->ensureUnique(['provenance_receipt_hash'], self::PROVENANCE_UNIQUE);
        $this->ensureIndex(['merge_sha'], 'owner_deployment_approval_requests_merge_sha_index');
        $this->ensureUnique(['deployment_run_id'], 'owner_deployment_approval_requests_deployment_run_id_unique');
        $this->ensureIndex(['repository', 'pull_request_number', 'head_sha'], self::COMPOSITE_INDEX);
    }

    /** @param list<string> $columns */
    private function ensureIndex(array $columns, string $name): void
    {
        if (Schema::hasIndex(self::TABLE, $name) || Schema::hasIndex(self::TABLE, $columns)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }

    /** @param list<string> $columns */
    private function ensureUnique(array $columns, string $name): void
    {
        if (Schema::hasIndex(self::TABLE, $name) || Schema::hasIndex(self::TABLE, $columns, 'unique')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name) {
            $table->unique($columns, $name);
        });
    }

    private function ensureRequiredForeignKeys(): void
    {
        if (! Schema::hasTable('admin_users')) {
            throw new RuntimeException(
                'admin_users is missing; refusing '.self::TABLE.' foreign-key recovery'
            );
        }

        $this->ensureForeignKey('requested_admin_id', 'restrict');
        $this->ensureForeignKey('approved_admin_id', 'set null');
    }

    private function ensureForeignKey(string $column, string $onDelete): void
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreign) {
            if (($foreign['columns'] ?? []) === [$column]
                && ($foreign['foreign_table'] ?? '') === 'admin_users') {
                return;
            }
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($column, $onDelete) {
            $fk = $table->foreign($column)->references('id')->on('admin_users');
            if ($onDelete === 'set null') {
                $fk->nullOnDelete();
            } else {
                $fk->restrictOnDelete();
            }
        });
    }
};
