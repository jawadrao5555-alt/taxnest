<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyGroupService;
use Illuminate\Console\Command;

/**
 * Rebuild business-group membership from the identity values on the accounts.
 *
 * Run once after deploying the grouping feature (so existing customers are
 * already grouped instead of only new signups), and any time an admin
 * suspects the graph has drifted.
 */
class SyncCompanyGroups extends Command
{
    protected $signature = 'groups:sync {--company= : only this company id} {--quiet-progress}';

    protected $description = 'Rebuild automatic business groups from shared CNIC / NTN / email / phone';

    public function handle(): int
    {
        if (!CompanyGroupService::enabled()) {
            $this->error('Group tables are missing — run migrations first.');

            return self::FAILURE;
        }

        if ($id = $this->option('company')) {
            $company = Company::find($id);
            if (!$company) {
                $this->error("Company {$id} not found.");

                return self::FAILURE;
            }
            $group = CompanyGroupService::syncCompany($company);
            $this->info($group
                ? "{$company->name} → {$group->code} ({$group->members()->count()} accounts)"
                : "{$company->name} → no group (no matching account)");

            return self::SUCCESS;
        }

        $quiet = (bool) $this->option('quiet-progress');
        $count = CompanyGroupService::rebuild(function ($company, $done) use ($quiet) {
            if (!$quiet && $done % 250 === 0) {
                $this->line("  … {$done} companies");
            }
        });

        $groups = \App\Models\CompanyGroup::count();
        $members = \App\Models\CompanyGroupMember::count();
        $this->info("Scanned {$count} companies → {$groups} groups covering {$members} accounts.");
        $this->line('  (memberships whose evidence no longer holds — filler values, shared accountant addresses — are dropped in the same pass)');

        return self::SUCCESS;
    }
}
