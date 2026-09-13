<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-deployment approval table: MariaDB-safe create + index reconcile.
 *
 * Default Laravel index names on this table exceed MariaDB's 64-character
 * identifier limit. Detection is by indexed columns (not name) so an already
 * working unique/lookup index is never duplicated or dropped.
 */
final class OwnerDeploymentApprovalSchema
{
    public const TABLE = 'owner_deployment_approval_requests';

    public const PROVENANCE_UNIQUE = 'odpr_prov_receipt_hash_uidx';

    public const REPO_LOOKUP = 'odpr_repo_pr_sha_idx';

    /** @var list<string> */
    public const PROVENANCE_COLUMNS = ['provenance_receipt_hash'];

    /** @var list<string> */
    public const REPO_LOOKUP_COLUMNS = ['repository', 'pull_request_number', 'head_sha'];

    public static function ensureTable(?string $connection = null): void
    {
        $schema = self::schema($connection);
        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function (Blueprint $table) {
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
                $table->index(self::REPO_LOOKUP_COLUMNS, self::REPO_LOOKUP);
            });

            return;
        }

        self::ensureIndexes($connection);
    }

    public static function ensureIndexes(?string $connection = null): void
    {
        $schema = self::schema($connection);
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        if (!self::hasEquivalentIndex(self::TABLE, self::PROVENANCE_COLUMNS, true, $connection)) {
            try {
                $schema->table(self::TABLE, function (Blueprint $table) {
                    $table->unique(self::PROVENANCE_COLUMNS, self::PROVENANCE_UNIQUE);
                });
            } catch (\Throwable $e) {
                if (!self::hasEquivalentIndex(self::TABLE, self::PROVENANCE_COLUMNS, true, $connection)) {
                    throw $e;
                }
            }
        }

        if (!self::hasEquivalentIndex(self::TABLE, self::REPO_LOOKUP_COLUMNS, false, $connection)) {
            try {
                $schema->table(self::TABLE, function (Blueprint $table) {
                    $table->index(self::REPO_LOOKUP_COLUMNS, self::REPO_LOOKUP);
                });
            } catch (\Throwable $e) {
                if (!self::hasEquivalentIndex(self::TABLE, self::REPO_LOOKUP_COLUMNS, false, $connection)) {
                    throw $e;
                }
            }
        }
    }

    /** @param list<string> $columns */
    public static function hasEquivalentIndex(string $table, array $columns, bool $unique, ?string $connection = null): bool
    {
        return self::indexNameOn($table, $columns, $unique, $connection) !== null;
    }

    /** @param list<string> $columns */
    public static function indexNameOn(string $table, array $columns, bool $unique, ?string $connection = null): ?string
    {
        $schema = self::schema($connection);
        if (!$schema->hasTable($table)) {
            return null;
        }

        $want = array_values($columns);
        foreach ($schema->getIndexes($table) as $index) {
            $have = array_values($index['columns'] ?? []);
            if ($have === $want && (bool) ($index['unique'] ?? false) === $unique) {
                return (string) ($index['name'] ?? '');
            }
        }

        return null;
    }

    private static function schema(?string $connection)
    {
        return $connection ? Schema::connection($connection) : Schema::getFacadeRoot();
    }
}
