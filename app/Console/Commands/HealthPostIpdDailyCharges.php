<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\HealthAdmission;
use App\Services\HealthIpdBillingService;
use App\Services\HealthModuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Post the bed-day and nursing charges for every patient still on a ward.
 *
 * Runs once a night. Every charge it writes carries a dedupe key of
 * category + admission + date + bed, so a second run — a retry, a manual press
 * of the same button on the stay screen, or a cron that fires twice after a
 * clock change — updates nothing and bills nothing twice.
 *
 * It walks companies one at a time and swallows a per-company failure on
 * purpose: one hospital with a broken row must not stop every other hospital's
 * ward from being billed for the night.
 *
 * NOTE FOR LIVE: a scheduled entry only fires where a `schedule:run` cron
 * exists on the server. Without it this command never runs and the bed-days
 * silently stop — which is why the stay screen also carries a manual button.
 */
class HealthPostIpdDailyCharges extends Command
{
    protected $signature = 'health:ipd-daily-charges
                            {--company= : Limit the run to one company id}
                            {--date= : Post up to this date instead of today}';

    protected $description = 'Post daily room and nursing charges for open inpatient admissions';

    public function handle(): int
    {
        if (!Schema::hasTable('health_admissions')) {
            $this->warn('Inpatient tables are not migrated yet — nothing to do.');

            return self::SUCCESS;
        }

        $upTo = $this->option('date') ?: null;

        $companyIds = HealthAdmission::withoutGlobalScopes()
            ->whereIn('status', HealthAdmission::OPEN_STATUSES)
            ->when($this->option('company'), fn ($q) => $q->where('company_id', (int) $this->option('company')))
            ->distinct()
            ->pluck('company_id');

        $companies = 0;
        $stays = 0;
        $charges = 0;

        foreach ($companyIds as $companyId) {
            $company = Company::withoutGlobalScopes()->find($companyId);
            if (!$company) {
                continue;
            }

            // A hospital that has switched the inpatient module OFF is not
            // billed for beds it is no longer using the system to manage.
            if (!HealthModuleService::isEnabled($company, 'ipd')) {
                continue;
            }

            $companies++;

            $admissions = HealthAdmission::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('status', HealthAdmission::OPEN_STATUSES)
                ->whereNotNull('admitted_at')
                ->get();

            foreach ($admissions as $admission) {
                try {
                    $posted = HealthIpdBillingService::postDailyCharges($admission, null, $upTo);
                    $stays++;
                    $charges += $posted;
                } catch (\Throwable $e) {
                    // Never let one stay abort the night's run for everybody.
                    $this->error("Admission {$admission->id}: {$e->getMessage()}");
                    report($e);
                }
            }
        }

        $this->info("Posted {$charges} charge line(s) across {$stays} stay(s) in {$companies} organisation(s).");

        return self::SUCCESS;
    }
}
