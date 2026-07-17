<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PROD SCHEMA-DRIFT SELF-HEAL.
 *
 * The owner's cPanel production DB repeatedly ends up missing columns that
 * exist in dev — migrations recorded as "Ran" without actually applying
 * (squashed history, partial failures). Result: admin/billing pages 500 one
 * at a time until a hand-written patch migration lands.
 *
 * This command compares the live database against the committed
 * database/schema-manifest.json (a snapshot of the HEALTHY dev schema,
 * regenerated via `php artisan schema:manifest`) and:
 *   - ADDs any missing column with the exact dev type/nullability/default;
 *   - CREATEs any missing table from its full dev DDL;
 *   - NEVER modifies, drops, or renames anything that already exists.
 *
 * Idempotent and safe to run repeatedly:
 *   php artisan schema:selfheal --dry-run   (report only)
 *   php artisan schema:selfheal             (apply)
 */
class SchemaSelfHeal extends Command
{
    protected $signature = 'schema:selfheal {--dry-run : Only report what would be added} {--table= : Limit to one table}';
    protected $description = 'Add columns/tables missing on the live DB compared to database/schema-manifest.json (add-only, idempotent)';

    public function handle(): int
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $this->warn("schema:selfheal only supports MySQL (current driver: {$driver}); nothing done.");
            return 0;
        }

        $path = database_path('schema-manifest.json');
        if (!is_file($path)) {
            $this->error('database/schema-manifest.json not found. Run `php artisan schema:manifest` on a healthy DB first.');
            return 1;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest) || empty($manifest['tables'])) {
            $this->error('schema-manifest.json is unreadable or empty.');
            return 1;
        }

        $dry = (bool) $this->option('dry-run');
        $only = $this->option('table');

        $addedColumns = 0;
        $createdTables = 0;
        $failures = 0;

        foreach ($manifest['tables'] as $table => $spec) {
            if ($only && $table !== $only) {
                continue;
            }

            if (!Schema::hasTable($table)) {
                $createSql = $spec['create_sql'] ?? null;
                if (!$createSql) {
                    $this->warn("[{$table}] table missing and manifest has no DDL — skipped.");
                    continue;
                }
                $createSql = preg_replace('/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $createSql, 1);
                $this->line("[{$table}] MISSING TABLE — " . ($dry ? 'would create' : 'creating'));
                if (!$dry) {
                    try {
                        DB::statement($createSql);
                        $createdTables++;
                    } catch (\Throwable $e) {
                        $failures++;
                        $this->error("[{$table}] CREATE failed: " . $e->getMessage());
                        Log::error("schema:selfheal create {$table} failed", ['error' => $e->getMessage()]);
                    }
                } else {
                    $createdTables++;
                }
                continue;
            }

            $liveCols = array_map('strtolower', Schema::getColumnListing($table));
            $prevExisting = null; // last manifest column confirmed present, for AFTER placement

            foreach (($spec['columns'] ?? []) as $col => $def) {
                if (in_array(strtolower($col), $liveCols, true)) {
                    $prevExisting = $col;
                    continue;
                }

                $extra = strtolower($def['extra'] ?? '');
                // auto_increment PKs and generated columns can't be bolted on
                // afterwards — and if they're missing the table needs a real
                // migration, not a patch.
                if (str_contains($extra, 'auto_increment') || str_contains($extra, 'generated')) {
                    $this->warn("[{$table}.{$col}] skipped (auto_increment/generated — needs a real migration).");
                    continue;
                }

                $sql = $this->buildAddColumnSql($table, $col, $def, $prevExisting);
                $this->line("[{$table}] MISSING COLUMN {$col} — " . ($dry ? 'would run' : 'running') . ": {$sql}");

                if (!$dry) {
                    try {
                        DB::statement($sql);
                        $addedColumns++;
                        $liveCols[] = strtolower($col);
                        $prevExisting = $col;
                    } catch (\Throwable $e) {
                        $failures++;
                        $this->error("[{$table}.{$col}] ADD failed: " . $e->getMessage());
                        Log::error("schema:selfheal add {$table}.{$col} failed", ['error' => $e->getMessage()]);
                    }
                } else {
                    $addedColumns++;
                    $prevExisting = $col;
                }
            }
        }

        $mode = $dry ? 'DRY-RUN' : 'APPLIED';
        $this->info("schema:selfheal {$mode}: {$addedColumns} column(s), {$createdTables} table(s)" . ($failures ? ", {$failures} FAILURE(S) — see log" : '') . '.');

        // Always exit 0: a partial heal must never abort a deploy's migrate run.
        return 0;
    }

    private function buildAddColumnSql(string $table, string $col, array $def, ?string $after): string
    {
        $type = $def['type'];
        $nullable = (bool) ($def['nullable'] ?? true);
        $default = $def['default'];
        $extra = $def['extra'] ?? '';

        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type}";

        // TEXT/BLOB/JSON/GEOMETRY types cannot carry a DEFAULT literal in MySQL.
        $noDefaultType = (bool) preg_match('/^(tiny|medium|long)?(text|blob)|^json|^geometry/i', $type);

        if ($nullable) {
            $sql .= ' NULL';
            if ($default !== null && !$noDefaultType) {
                $sql .= ' DEFAULT ' . $this->quoteDefault($type, $default, $extra);
            }
        } else {
            if ($default !== null && !$noDefaultType) {
                $sql .= ' NOT NULL DEFAULT ' . $this->quoteDefault($type, $default, $extra);
            } else {
                // NOT NULL without a default can't be added to a non-empty
                // table under strict mode — add as NULL-able instead of
                // failing; the app treats missing-as-null anyway.
                $sql .= ' NULL';
            }
        }

        if (stripos($extra, 'on update CURRENT_TIMESTAMP') !== false) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if ($after) {
            $sql .= " AFTER `{$after}`";
        }

        return $sql;
    }

    private function quoteDefault(string $type, string $default, string $extra): string
    {
        if (preg_match('/^CURRENT_TIMESTAMP(\(\d*\))?$/i', $default)) {
            return $default;
        }
        // MySQL 8 expression defaults (uuid(), (json_array()), …)
        if (stripos($extra, 'DEFAULT_GENERATED') !== false) {
            return str_starts_with($default, '(') ? $default : "({$default})";
        }
        $isNumericType = (bool) preg_match('/^(tiny|small|medium|big)?int|^decimal|^float|^double|^bit/i', $type);
        if ($isNumericType && is_numeric($default)) {
            return $default;
        }
        return "'" . str_replace("'", "''", $default) . "'";
    }
}
