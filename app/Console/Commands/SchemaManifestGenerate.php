<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates database/schema-manifest.json — the canonical "expected schema"
 * snapshot taken from a HEALTHY database (dev/staging where every migration
 * really ran). `schema:selfheal` later compares a live (possibly drifted)
 * database against this manifest and adds whatever is missing.
 *
 * Regenerate this manifest (and commit it) whenever new migrations land:
 *   php artisan schema:manifest
 */
class SchemaManifestGenerate extends Command
{
    protected $signature = 'schema:manifest';
    protected $description = 'Snapshot the current (healthy) DB schema into database/schema-manifest.json for schema:selfheal';

    public function handle(): int
    {
        $dbName = DB::getDatabaseName();
        $driver = DB::getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $this->error("schema:manifest only supports MySQL (current driver: {$driver}).");
            return 1;
        }

        $tables = DB::select(
            'SELECT table_name AS t FROM information_schema.tables
             WHERE table_schema = ? AND table_type = "BASE TABLE" ORDER BY table_name',
            [$dbName]
        );

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'source_database' => $dbName,
            'tables' => [],
        ];

        foreach ($tables as $tRow) {
            $table = $tRow->t;

            $cols = DB::select(
                'SELECT column_name AS name, column_type AS type, is_nullable AS nullable,
                        column_default AS dflt, extra AS extra
                 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ?
                 ORDER BY ordinal_position',
                [$dbName, $table]
            );

            $columns = [];
            foreach ($cols as $c) {
                $columns[$c->name] = [
                    'type' => $c->type,
                    'nullable' => strtoupper($c->nullable) === 'YES',
                    'default' => $c->dflt,
                    'extra' => $c->extra ?? '',
                ];
            }

            // Full CREATE TABLE DDL so selfheal can recreate a table that is
            // missing entirely (strip the AUTO_INCREMENT counter — meaningless
            // on a fresh copy).
            $createRow = DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $createSql = null;
            foreach ((array) $createRow as $key => $value) {
                if (stripos($key, 'Create Table') !== false) {
                    $createSql = $value;
                }
            }
            if ($createSql) {
                $createSql = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $createSql);
            }

            $manifest['tables'][$table] = [
                'columns' => $columns,
                'create_sql' => $createSql,
            ];
        }

        $path = database_path('schema-manifest.json');
        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $tableCount = count($manifest['tables']);
        $colCount = array_sum(array_map(fn ($t) => count($t['columns']), $manifest['tables']));
        $this->info("Manifest written: {$path} ({$tableCount} tables, {$colCount} columns).");

        return 0;
    }
}
