<?php

namespace App\Console\Commands;

use App\Services\PosSettingsSnapshot;
use Illuminate\Console\Command;

/**
 * Owner rule (Sep 2026): a feature update must change that feature and nothing
 * else — no shop's settings, per-branch values, staff permissions, feature
 * toggles or saved preferences may be reset by a deploy.
 *
 * Usage around a deploy:
 *
 *   php artisan pos:settings-snapshot --out=/path/to/unique-before.json
 *   ... deploy + migrate ...
 *   php artisan pos:settings-snapshot --compare=/path/to/unique-before.json
 *
 * --out refuses to overwrite an existing file unless --force is passed. Deploy
 * tooling must never --force onto the retained forensic baseline path.
 */
class PosSettingsSnapshotCommand extends Command
{
    protected $signature = 'pos:settings-snapshot
        {--out= : Write the snapshot to this JSON file}
        {--compare= : Compare the CURRENT state against this saved snapshot}
        {--company= : Limit to a single company id}
        {--allow= : Comma-separated column names whose change is expected}
        {--limit=40 : Max individual findings to print}
        {--force : Allow overwriting an existing --out file (deploy tooling must not use this on retained baselines)}';

    protected $description = 'Capture or verify a snapshot of every company setting, branch value, staff permission and saved preference, so a deploy cannot silently reset one.';

    public function handle(PosSettingsSnapshot $snapshots): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $current = $snapshots->capture($companyId);

        $rowCount = array_sum(array_map('count', $current['tables']));
        $tableList = implode(', ', array_keys($current['tables']));

        $comparePath = $this->option('compare');
        if ($comparePath === null) {
            return $this->write($snapshots, $current, $rowCount, $tableList);
        }

        if (! is_file($comparePath)) {
            $this->error("Snapshot not found: {$comparePath}");

            return self::FAILURE;
        }

        $before = json_decode((string) file_get_contents($comparePath), true);
        if (! $snapshots->isValidSnapshot($before)) {
            $this->error("Not a settings snapshot: {$comparePath}");

            return self::FAILURE;
        }

        $allow = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('allow')))));
        $diff = $snapshots->diff($before, $current, $allow);

        $this->line("Baseline : {$comparePath} (taken " . ($before['generated_at'] ?? 'unknown') . ')');
        $this->line("Now      : {$rowCount} rows across {$tableList}");
        $this->newLine();

        foreach ($diff['new_columns'] as $table => $cols) {
            $this->line("  + new columns on {$table}: " . implode(', ', $cols));
        }
        foreach ($diff['added_rows'] as $table => $n) {
            $this->line("  + {$n} new row(s) in {$table}");
        }
        foreach ($diff['removed_rows'] as $table => $n) {
            $this->warn("  - {$n} row(s) gone from {$table}");
        }
        if ($diff['allowed'] !== []) {
            $this->line('  ~ ' . count($diff['allowed']) . ' declared change(s) via --allow');
        }

        $destructive = false;
        foreach ($diff['dropped_tables'] as $table) {
            $this->error("  ✗ settings table DROPPED: {$table} — every value it held is gone");
            $destructive = true;
        }
        foreach ($diff['dropped_columns'] as $table => $cols) {
            $this->error("  ✗ settings columns DROPPED from {$table}: " . implode(', ', $cols));
            $destructive = true;
        }

        if ($diff['changed'] === [] && ! $destructive) {
            $this->newLine();
            $this->info('No existing setting was changed. Deploy is regression-clean.');

            return self::SUCCESS;
        }

        if ($diff['changed'] === []) {
            $this->newLine();
            $this->error('A settings column or table was dropped by this deploy — saved values were destroyed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->error(count($diff['changed']) . ' EXISTING setting(s) were changed by this deploy:');
        $rows = [];
        foreach (array_slice($diff['changed'], 0, max(1, (int) $this->option('limit'))) as $c) {
            $rows[] = [
                $c['table'],
                $c['company_id'] ?? '-',
                $c['column'],
                'hash:'.substr($snapshots->valueHash(is_string($c['before'] ?? null) ? $c['before'] : null), 0, 12),
                'hash:'.substr($snapshots->valueHash(is_string($c['after'] ?? null) ? $c['after'] : null), 0, 12),
            ];
        }
        $this->table(['table', 'company', 'column', 'before', 'after'], $rows);
        if (count($diff['changed']) > count($rows)) {
            $this->line('  ... and ' . (count($diff['changed']) - count($rows)) . ' more.');
        }
        $this->newLine();
        $this->line('If a change was intended, re-run with --allow=' . implode(',', array_values(array_unique(array_column($diff['changed'], 'column')))));

        return self::FAILURE;
    }

    private function write(PosSettingsSnapshot $snapshots, array $snapshot, int $rowCount, string $tableList): int
    {
        if (! $snapshots->isValidSnapshot($snapshot)) {
            $this->error('Refusing to write an invalid settings snapshot.');

            return self::FAILURE;
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Could not encode the snapshot.');

            return self::FAILURE;
        }

        $out = $this->option('out');
        if ($out === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        $dir = dirname($out);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create directory: {$dir}");

            return self::FAILURE;
        }

        if (is_file($out) && ! $this->option('force')) {
            $this->error("Refusing to overwrite existing snapshot: {$out} (pass --force only for deliberate replacement; never on a retained forensic baseline)");

            return self::FAILURE;
        }

        if (@file_put_contents($out, $json) === false) {
            $this->error("Cannot write: {$out}");

            return self::FAILURE;
        }

        $this->info("Snapshot written: {$out}");
        $this->line("  {$rowCount} rows across {$tableList}");

        return self::SUCCESS;
    }
}
