<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\HealthChartOfAccountsService;
use App\Services\HealthModuleService;
use App\Services\HealthOnboardingImportService;
use App\Support\NestErps;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pre-launch readiness check for a healthcare pilot organisation.
 *
 * The point of this command is that a runbook nobody can EXECUTE is a runbook
 * nobody has verified. Everything here is read-only and safe to run on the live
 * server at any hour: it opens no transaction, writes no row, and touches no
 * regulator. What it reports is the set of conditions a hospital needs before
 * its first real patient walks in — the ones whose absence is silent on a bare
 * host (no scheduler, no queue worker, an un-migrated column, an unseeded chart
 * of accounts) and therefore only shows up as "the system is broken" a week in.
 *
 * Usage:
 *   php artisan health:pilot-readiness                 # platform-wide checks
 *   php artisan health:pilot-readiness --company=42    # plus that hospital's own
 */
class HealthPilotReadiness extends Command
{
    protected $signature = 'health:pilot-readiness
                            {--company= : The pilot organisation id to check in addition to the platform}';

    protected $description = 'Read-only pre-launch check for a healthcare pilot: schema, modules, accounts, queue, scheduler, storage and language readiness.';

    /** @var array<int, array{area:string,check:string,state:string,detail:string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $this->line('');
        $this->info(NestErps::LABEL . ' — healthcare pilot readiness');
        $this->line('');

        $this->checkSchema();
        $this->checkBackgroundWork();
        $this->checkStorage();
        $this->checkLanguages();

        $companyId = $this->option('company');
        if ($companyId !== null && $companyId !== '') {
            $this->checkCompany((int) $companyId);
        } else {
            $this->note('pilot', 'organisation checks', 'skipped', 'pass --company=<id> to check the pilot hospital itself');
        }

        $this->table(['Area', 'Check', 'State', 'Detail'], array_map(
            fn (array $r) => [$r['area'], $r['check'], $this->paint($r['state']), $r['detail']],
            $this->rows
        ));

        if ($this->failed) {
            $this->line('');
            $this->error('NOT READY — resolve every FAIL above before the pilot takes real patients.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Ready. Warnings above are decisions to confirm, not blockers.');

        return self::SUCCESS;
    }

    /* ───────────────────────────── checks ─────────────────────────────────── */

    /**
     * What the panel IS, regardless of which modules a hospital bought:
     * patients, the department and doctor directory, the charge catalogue, the
     * bill and the money paid against it, and the owner's audit trail.
     */
    private const CORE_TABLES = [
        'health_patients', 'health_departments', 'health_department_user',
        'health_doctors', 'health_procedures', 'health_staff_profiles',
        'health_charges', 'health_charge_adjustments', 'health_bills', 'health_bill_lines',
        'health_payments', 'health_cashier_shifts', 'health_number_sequences',
        'health_doctor_shares', 'health_doctor_share_rules', 'health_doctor_settlements',
        'health_audit_runs', 'health_audit_findings', 'health_audit_events', 'health_audit_notes',
    ];

    /**
     * Per module. Every one of these is checked on a deployment: a hospital can
     * switch a module on at any time, and finding out that its tables never
     * shipped on the morning it does so is exactly the discovery this command
     * exists to prevent.
     *
     * `lab` is absent on purpose — it owns no tables of its own; lab work is
     * ordered and billed through the shared charge and visit tables above.
     */
    private const MODULE_TABLES = [
        'opd' => [
            'health_appointments', 'health_visits', 'health_visit_attachments',
            'health_prescriptions', 'health_prescription_items', 'health_doctor_slots',
        ],
        'pharmacy' => [
            'health_medicines', 'health_medicine_batches', 'health_medicine_substitutes',
            'health_batch_movements', 'health_pharmacy_sales', 'health_pharmacy_sale_items',
            'health_pharmacy_returns', 'health_pharmacy_return_items',
            'health_pharmacy_settings', 'health_supplier_payments',
        ],
        'ipd' => [
            'health_wards', 'health_rooms', 'health_beds',
            'health_admissions', 'health_admission_charges', 'health_admission_payments',
            'health_admission_events', 'health_operations', 'health_operation_theatres',
            'health_operation_team', 'health_operation_consumables',
        ],
        'accounts' => [
            'health_accounts', 'health_journals', 'health_journal_lines',
            'health_fiscal_periods', 'health_accounting_settings', 'health_account_reconciliations',
            'health_bank_accounts', 'health_fund_transfers',
            'health_expenses', 'health_expense_categories',
        ],
        'hr' => [
            'health_attendance_punches', 'health_attendance_days', 'health_attendance_corrections',
            'health_attendance_locks', 'health_shifts', 'health_roster_entries',
            'health_leave_requests', 'health_leave_types', 'health_holidays', 'health_hr_policies',
        ],
    ];

    private function checkSchema(): void
    {
        /*
         * A deploy that landed while its migration did not is the quietest
         * production failure there is: most of the panel works, and one screen
         * 500s the first time somebody opens it — usually the pharmacist, mid
         * queue. So the list is checked per module and NOTHING is skipped for
         * being absent; an absent table is the finding.
         */
        $missing = array_values(array_filter(self::CORE_TABLES, fn ($t) => !Schema::hasTable($t)));

        $this->note(
            'schema',
            'core healthcare tables',
            $missing ? 'fail' : 'ok',
            $missing ? 'missing: ' . implode(', ', $missing) : count(self::CORE_TABLES) . ' present'
        );

        foreach (self::MODULE_TABLES as $module => $tables) {
            $gone = array_values(array_filter($tables, fn ($t) => !Schema::hasTable($t)));

            $this->note(
                'schema',
                $module . ' module tables',
                $gone ? 'fail' : 'ok',
                $gone ? 'missing: ' . implode(', ', $gone) : count($tables) . ' present'
            );
        }

        // Supplier opening balances arrived with the onboarding importer and
        // are guarded by hasColumn everywhere, so their absence degrades
        // quietly rather than breaking — a warning, not a failure.
        $this->note(
            'schema',
            'supplier opening balance',
            Schema::hasColumn('suppliers', 'opening_balance') ? 'ok' : 'warn',
            Schema::hasColumn('suppliers', 'opening_balance')
                ? 'importer can carry forward what the hospital already owes'
                : 'opening supplier balances will import as zero until the migration runs'
        );

        try {
            $pending = collect(DB::table('migrations')->pluck('migration'))->count();
            $this->note('schema', 'migrations ledger', 'ok', $pending . ' applied');
        } catch (Throwable $e) {
            $this->note('schema', 'migrations ledger', 'fail', 'unreadable: ' . $e->getMessage());
        }
    }

    private function checkBackgroundWork(): void
    {
        // On a bare host neither of these exists by default, and neither fails
        // loudly. Bulk imports simply never finish and reminders never send.
        $driver = (string) config('queue.default');
        $this->note(
            'background',
            'queue driver',
            $driver === 'sync' ? 'warn' : 'ok',
            $driver === 'sync'
                ? 'sync: long imports and bulk work will block the web request'
                : $driver
        );

        if (Schema::hasTable('jobs')) {
            $waiting = (int) DB::table('jobs')->count();
            $this->note(
                'background',
                'queue backlog',
                $waiting > 500 ? 'warn' : 'ok',
                $waiting . ' waiting'
            );
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = (int) DB::table('failed_jobs')->count();
            $this->note(
                'background',
                'failed jobs',
                $failedJobs > 0 ? 'warn' : 'ok',
                $failedJobs . ' recorded — clear or explain each before go-live'
            );
        }

        /*
         * Both of these read a TIMESTAMP the scheduler itself wrote. Checking
         * that a command class exists proves nothing — the class ships with the
         * code and is present on a host with no cron at all, which is exactly
         * the failure that has to be caught: a ward that quietly stops accruing
         * bed-days while every screen looks fine and the discharge bill is short.
         */
        $cronAt = $this->stampAt(SystemSetting::get('scheduler_last_heartbeat'));

        if ($cronAt === null) {
            $this->note('background', 'scheduler cron', 'fail', 'no schedule:run has ever fired on this host — install the cron');
        } elseif ($cronAt->lt(now()->subHour())) {
            $this->note('background', 'scheduler cron', 'fail', 'last fired ' . $cronAt->diffForHumans() . ' — the cron has stopped');
        } else {
            $this->note('background', 'scheduler cron', 'ok', 'last fired ' . $cronAt->diffForHumans());
        }

        $bedDaysAt = $this->stampAt(SystemSetting::get('health_ipd_daily_charges_last_run'));

        if ($bedDaysAt === null) {
            $this->note(
                'background',
                'scheduled bed-day charges',
                'fail',
                $cronAt === null
                    ? 'never posted, because no scheduler runs here'
                    : 'never posted — the nightly entry has not fired yet'
            );
        } elseif ($bedDaysAt->lt(now()->subHours(48))) {
            $this->note('background', 'scheduled bed-day charges', 'fail', 'last posted ' . $bedDaysAt->diffForHumans() . ' — wards are not accruing');
        } else {
            $this->note('background', 'scheduled bed-day charges', 'ok', 'last posted ' . $bedDaysAt->diffForHumans());
        }
    }

    private function checkStorage(): void
    {
        // Asked, not tried. This command's contract is that it is safe to run
        // on a live hospital at any hour, so it must not create a file — not
        // even one it deletes again. The nearest existing ancestor of the
        // import workspace is what actually decides whether an upload can land.
        try {
            $path = Storage::disk('local')->path(HealthOnboardingImportService::DISK_DIRECTORY);

            $probe = $path;
            while ($probe !== '' && $probe !== DIRECTORY_SEPARATOR && !file_exists($probe)) {
                $parent = dirname($probe);
                if ($parent === $probe) {
                    break;
                }
                $probe = $parent;
            }

            $writable = is_dir($probe) && is_writable($probe);

            $this->note(
                'storage',
                'setup import workspace',
                $writable ? 'ok' : 'fail',
                $writable
                    ? ($probe === $path ? 'writable' : 'writable (will be created on first upload)')
                    : 'not writable: ' . $probe
            );
        } catch (Throwable $e) {
            $this->note('storage', 'setup import workspace', 'fail', $e->getMessage());
        }
    }

    private function checkLanguages(): void
    {
        $sets = [];
        foreach (['en', 'rur', 'ur'] as $locale) {
            $path = base_path("lang/{$locale}/health.php");
            $sets[$locale] = is_file($path) ? array_keys(require $path) : null;
        }

        if (in_array(null, $sets, true)) {
            $this->note('language', 'three-way key set', 'fail', 'a healthcare language file is missing');

            return;
        }

        $drift = [];
        foreach (['rur', 'ur'] as $locale) {
            $missing = count(array_diff($sets['en'], $sets[$locale]));
            $extra = count(array_diff($sets[$locale], $sets['en']));
            if ($missing || $extra) {
                $drift[] = "{$locale}: -{$missing} +{$extra}";
            }
        }

        $this->note(
            'language',
            'three-way key set',
            $drift ? 'fail' : 'ok',
            $drift ? implode(', ', $drift) : count($sets['en']) . ' keys in all three'
        );
    }

    private function checkCompany(int $companyId): void
    {
        $company = Company::withoutGlobalScopes()->find($companyId);

        if (!$company) {
            $this->note('pilot', 'organisation', 'fail', "no company with id {$companyId}");

            return;
        }

        $this->note('pilot', 'organisation', 'ok', $company->name);

        $this->note(
            'pilot',
            'product line',
            NestErps::isProductType($company->product_type) ? 'ok' : 'fail',
            (string) $company->product_type
        );

        $this->note(
            'pilot',
            'approval state',
            /*
             * BOTH columns, not either. Different panels read different ones: a
             * company left "pending" fails the approval middleware on every
             * write — including the setup import the pilot starts with —
             * however active the other column says it is, and an "approved"
             * company that is not active is locked out just the same. "Either
             * will do" was a readiness check that could pass a hospital which
             * cannot save a single row.
             */
            ($company->status === 'approved' && $company->company_status === 'active') ? 'ok' : 'fail',
            'status=' . $company->status . ', company_status=' . $company->company_status
                . (($company->status === 'approved' && $company->company_status === 'active')
                    ? ''
                    : ' — needs approved + active')
        );

        HealthModuleService::forget();
        $modules = HealthModuleService::enabled($company);
        $this->note(
            'pilot',
            'modules switched on',
            $modules ? 'ok' : 'fail',
            $modules ? implode(', ', $modules) : 'none — every screen will refuse'
        );

        $owner = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('health_role', 'health_owner')
            ->where('is_active', true)
            ->count();
        $this->note(
            'pilot',
            'active owner login',
            $owner > 0 ? 'ok' : 'fail',
            $owner . ' owner account(s) — the importer and module switches are owner-only'
        );

        $staff = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->count();
        $this->note('pilot', 'active staff logins', $staff > 1 ? 'ok' : 'warn', (string) $staff);

        /*
         * These read a table each, so each one has to say something when the
         * table is not there. Skipping the check on a missing table turns the
         * worst case — the migration never ran — into the quietest possible
         * output: no row at all, and a green summary underneath it.
         */
        if ($this->tableIsThere('chart of accounts', 'health_accounts')) {
            $accounts = (int) DB::table('health_accounts')->where('company_id', $company->id)->count();
            $this->note(
                'pilot',
                'chart of accounts',
                $accounts > 0 ? 'ok' : 'warn',
                $accounts > 0
                    ? $accounts . ' accounts seeded'
                    : 'not seeded — the first posting will have nowhere to land'
            );
        }

        if ($this->tableIsThere('departments', 'health_departments')) {
            $departments = (int) DB::table('health_departments')->where('company_id', $company->id)->count();
            $this->note(
                'pilot',
                'departments',
                $departments > 0 ? 'ok' : 'warn',
                $departments . ' — import them before the first OPD queue'
            );
        }

        if ($this->tableIsThere('active doctors', 'health_doctors')) {
            $doctors = (int) DB::table('health_doctors')->where('company_id', $company->id)->where('is_active', true)->count();
            $this->note('pilot', 'active doctors', $doctors > 0 ? 'ok' : 'warn', (string) $doctors);
        }

        if ($this->tableIsThere('medicine catalogue', 'health_medicines')) {
            $medicines = (int) DB::table('health_medicines')->where('company_id', $company->id)->count();
            $this->note('pilot', 'medicine catalogue', $medicines > 0 ? 'ok' : 'warn', (string) $medicines . ' items');
        }

        HealthChartOfAccountsService::flush();
        HealthModuleService::forget();
    }

    /* ───────────────────────────── plumbing ───────────────────────────────── */

    /** True when the table is there; otherwise it FAILS the named check and says why. */
    private function tableIsThere(string $check, string $table): bool
    {
        if (Schema::hasTable($table)) {
            return true;
        }

        $this->note('pilot', $check, 'fail', 'the ' . $table . ' table is not migrated on this host');

        return false;
    }

    /** A stored heartbeat string as a time, or null when it is absent or unreadable. */
    private function stampAt(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function note(string $area, string $check, string $state, string $detail): void
    {
        if ($state === 'fail') {
            $this->failed = true;
        }

        $this->rows[] = compact('area', 'check', 'state', 'detail');
    }

    private function paint(string $state): string
    {
        return match ($state) {
            'ok' => '<fg=green>OK</>',
            'warn' => '<fg=yellow>WARN</>',
            'fail' => '<fg=red>FAIL</>',
            default => strtoupper($state),
        };
    }
}
