<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DatabaseBackup extends Command
{
    protected $signature = 'backup:database {--download : Output SQL to stdout for download}';
    protected $description = 'Create a database backup and store in /storage/backups';

    public function handle()
    {
        $backupDir = storage_path('backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "backup_{$timestamp}.sql";
        $filepath = "{$backupDir}/{$filename}";

        $dbUrl = env('DATABASE_URL');
        if (!$dbUrl) {
            $this->error('DATABASE_URL not set');
            return 1;
        }

        $parsed = parse_url($dbUrl);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 5432;
        $dbname = ltrim($parsed['path'] ?? '', '/');
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';

        $driver = config('database.default');

        if ($driver === 'pgsql') {
            $envPass = $pass ? "PGPASSWORD=" . escapeshellarg($pass) . " " : "";
            $cmd = "{$envPass}pg_dump -h " . escapeshellarg($host) . " -p " . escapeshellarg($port) . " -U " . escapeshellarg($user) . " " . escapeshellarg($dbname) . " > " . escapeshellarg($filepath) . " 2>&1";
        } else {
            $cmd = "mysqldump -h " . escapeshellarg($host) . " -P " . escapeshellarg($port) . " -u " . escapeshellarg($user) . " -p" . escapeshellarg($pass) . " " . escapeshellarg($dbname) . " > " . escapeshellarg($filepath) . " 2>&1";
        }

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $sql = "-- TaxNest Database Backup\n-- Generated: {$timestamp}\n-- Tables: " . count($tables) . "\n\n";

            foreach ($tables as $t) {
                $tableName = $t->tablename;
                $rows = DB::select("SELECT * FROM \"{$tableName}\"");
                if (count($rows) > 0) {
                    $columns = array_keys((array)$rows[0]);
                    $sql .= "-- Table: {$tableName} (" . count($rows) . " rows)\n";
                    foreach ($rows as $row) {
                        $values = array_map(function ($v) {
                            if ($v === null) return 'NULL';
                            return "'" . addslashes($v) . "'";
                        }, array_values((array)$row));
                        $sql .= "INSERT INTO \"{$tableName}\" (\"" . implode('","', $columns) . "\") VALUES (" . implode(',', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }
            file_put_contents($filepath, $sql);
        }

        if ($this->option('download')) {
            $this->output->write(file_get_contents($filepath));
            return 0;
        }

        $size = round(filesize($filepath) / 1024, 1);
        $this->info("Backup created: {$filename} ({$size} KB)");

        $files = glob("{$backupDir}/backup_*.sql");
        if (count($files) > 7) {
            usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
            $toDelete = array_slice($files, 0, count($files) - 7);
            foreach ($toDelete as $f) unlink($f);
            $this->info('Cleaned old backups, keeping last 7');
        }

        return 0;
    }
}
