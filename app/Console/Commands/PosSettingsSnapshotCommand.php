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
 *   php artisan pos:settings-snapshot --out=storage/app/settings-before.json
 *   ... deploy + migrate ...
 *   php artisan pos:settings-snapshot --compare=storage/app/settings-before.json
 *
 * Exit code 1 means at least one EXISTING row's EXISTING setting changed.
 * Intentional changes are declared with --allow=column1,column2 so the deploy
 * still passes while every undeclared change stays fatal.
 */
class PosSettingsSnapshotCommand extends Command
{
    protected $signature = 'pos:settings-snapshot
        {--out= : Write the snapshot to this JSON file}
        {--compare= : Compare the CURRENT state against this saved snapshot}
        {--company= : Limit to a single company id}
        {--allow= : Comma-separated column names whose change is expected}
        {--limit=40 : Max individual findings to print}';

    protected $description = 'Capture or verify a snapshot of every company setting, branch value, staff permission and saved preference, so a deploy cannot silently reset one.';

    public function handle(PosSettingsSnapshot $snapshots): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $current = $snapshots->capture($companyId);

        $rowCount = array_sum(array_map('count', $current['tables']));
        $tableList = implode(', ', array_keys($current['tables']));

        $comparePath = $this->option('compare');
        if ($comparePath === null) {
            return $this->write($current, $rowCount, $tableList);
        }

        if (! is_file($comparePath)) {
            $this->error("Snapshot not found: {$comparePath}");

            return self::FAILURE;
        }

        $before = json_decode((string) file_get_contents($comparePath), true);
        if (! is_array($before) || ! isset($before['tables'])) {
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

        // A dropped settings column or table destroys every shop's value in it.
        // That is data loss, not a diff — it is fatal on its own, and --allow
        // cannot wave it through.
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
                $this->short($c['before']),
                $this->short($c['after']),
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

    private function write(array $snapshot, int $rowCount, string $tableList): int
    {
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
        if (@file_put_contents($out, $json) === false) {
            $this->error("Cannot write: {$out}");

            return self::FAILURE;
        }

        $this->info("Snapshot written: {$out}");
        $this->line("  {$rowCount} rows across {$tableList}");

        return self::SUCCESS;
    }

    private function short(?string $v): string
    {
        if ($v === null) {
            return '(null)';
        }
        if ($v === '') {
            return "''";
        }

        return mb_strlen($v) > 48 ? mb_substr($v, 0, 45) . '...' : $v;
    }
}
