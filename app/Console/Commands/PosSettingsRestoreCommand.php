<?php

namespace App\Console\Commands;

use App\Services\PosSettingsSnapshot;
use Illuminate\Console\Command;

/**
 * Idempotent, dry-run-first recovery for the PR #72 service_jobs Custom Access
 * rewrite. Reads a server-side settings baseline and restores ONLY rows proven
 * to differ by a service_jobs-only append. Never prints customer values.
 *
 *   php artisan pos:settings-restore --from=/path/to/.taxnest-settings-before.json
 *   php artisan pos:settings-restore --from=... --write
 *
 * Does NOT run against production from a Cloud Agent. Owner/CI may invoke it
 * on the live host after reviewing dry-run hashes.
 */
class PosSettingsRestoreCommand extends Command
{
    protected $signature = 'pos:settings-restore
        {--from= : Absolute path to the pre-deploy settings baseline JSON}
        {--write : Apply restores (default is dry-run)}
        {--company= : Limit planning to one company id}';

    protected $description = 'Dry-run-first restore of protected settings from a pre-deploy baseline (service_jobs-only appends).';

    public function handle(PosSettingsSnapshot $snapshots): int
    {
        $from = (string) $this->option('from');
        if ($from === '' || ! is_file($from)) {
            $this->error('Baseline file missing — pass --from=/absolute/path/to/.taxnest-settings-before.json');

            return self::FAILURE;
        }
        if (! str_starts_with($from, '/')) {
            $this->error('Baseline path must be absolute (refusing relative paths).');

            return self::FAILURE;
        }

        $before = json_decode((string) file_get_contents($from), true);
        if (! $snapshots->isValidSnapshot($before)) {
            $this->error('Not a genuine settings snapshot baseline — refusing.');

            return self::FAILURE;
        }

        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $after = $snapshots->capture($companyId);
        $plan = $snapshots->planProtectedRestore($before, $after);

        $this->line('Baseline : '.$from);
        $this->line('Mode     : '.($this->option('write') ? 'WRITE' : 'DRY-RUN'));
        $this->line('Plan ok  : '.($plan['ok'] ? 'yes' : 'no').($plan['reason'] ? ' ('.$plan['reason'].')' : ''));
        $this->line('Restore  : '.count($plan['restore']).' row(s)');
        $this->line('Refused  : '.count($plan['refused']).' finding(s)');

        foreach ($plan['restore'] as $item) {
            $this->line(sprintf(
                '  restore users#%s pos_custom_access before=%s after=%s',
                $item['row'],
                substr($item['before_hash'], 0, 12),
                substr($item['after_hash'], 0, 12)
            ));
        }
        foreach ($plan['refused'] as $item) {
            $this->warn(sprintf(
                '  refuse %s#%s %s (%s)',
                $item['table'],
                $item['row'],
                $item['column'],
                $item['reason']
            ));
        }

        if (! $plan['ok']) {
            return self::FAILURE;
        }

        if ($plan['restore'] === []) {
            $this->info('Already clean against baseline — nothing to write.');

            return self::SUCCESS;
        }

        $result = $snapshots->applyProtectedRestore($plan['restore'], dryRun: ! $this->option('write'));
        $this->line(sprintf(
            'Result   : written=%d skipped=%d refused=%d',
            $result['written'],
            $result['skipped'],
            count($result['refused'])
        ));
        foreach ($result['refused'] as $item) {
            $this->warn(sprintf('  apply-refuse users#%s (%s)', $item['row'], $item['reason']));
        }

        if ($result['refused'] !== []) {
            return self::FAILURE;
        }

        if ($this->option('write')) {
            $verify = $snapshots->capture($companyId);
            $diff = $snapshots->diff($before, $verify);
            $remaining = array_values(array_filter(
                $diff['changed'],
                fn ($c) => ($c['table'] ?? '') === 'users' && ($c['column'] ?? '') === 'pos_custom_access'
            ));
            if ($remaining !== []) {
                $this->error('Post-write verification still sees '.count($remaining).' pos_custom_access change(s).');

                return self::FAILURE;
            }
            $this->info('Verified: service_jobs-only rows match the baseline again.');
        } else {
            $this->info('Dry-run only — no rows were written. Re-run with --write to apply.');
        }

        return self::SUCCESS;
    }
}
