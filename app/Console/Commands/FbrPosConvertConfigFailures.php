<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\FbrIntegrationDecisionService;
use Illuminate\Console\Command;

/**
 * Support tool (Sep 2026, optional FBR integration): turn a shop's CONFIG-ONLY
 * failed bills (never reached FBR — no POS ID / token was ever set up) back
 * into plain bills with the simple details QR. Same strict criteria as the
 * "Abhi nahi" decision card; idempotent (second run converts 0); audited.
 *
 *   php artisan fbrpos:convert-config-failures 39
 *   php artisan fbrpos:convert-config-failures 39 --dry-run
 */
class FbrPosConvertConfigFailures extends Command
{
    protected $signature = 'fbrpos:convert-config-failures {company : companies.id} {--dry-run : Only list the bills that would be converted}';

    protected $description = 'Convert an FBR POS shop\'s config-only failed bills (never submitted to FBR) into plain bills. Idempotent, audited.';

    public function handle(FbrIntegrationDecisionService $svc): int
    {
        $company = Company::find((int) $this->argument('company'));
        if (!$company) {
            $this->error('Company not found.');
            return self::FAILURE;
        }
        if (($company->product_type ?? null) !== 'fbrpos' && !$company->fbr_pos_enabled) {
            $this->error("Company #{$company->id} ({$company->name}) is not an FBR POS shop.");
            return self::FAILURE;
        }

        $rows = $svc->configOnlyFailureQuery($company->id)->orderBy('id')->get(['id', 'invoice_number', 'fbr_status', 'created_at']);
        $this->info("Company #{$company->id} {$company->name}: reporting " . ($company->fbr_reporting_enabled ? 'ON' : 'OFF')
            . ', integration ' . ($company->fbrPosIntegrationConfigured() ? 'configured' : 'NOT configured')
            . ', decision ' . ($company->fbrIntegrationDecision() ?? 'undecided'));

        if ($rows->isEmpty()) {
            $this->info('No config-only failed bills — nothing to convert.');
            return self::SUCCESS;
        }

        $this->table(['id', 'invoice', 'fbr_status', 'created'], $rows->map(fn ($r) => [
            $r->id, $r->invoice_number, $r->fbr_status, optional($r->created_at)->toDateTimeString(),
        ])->all());

        if ($this->option('dry-run')) {
            $this->warn($rows->count() . ' bill(s) would be converted (dry run — nothing changed).');
            return self::SUCCESS;
        }

        $n = $svc->convertConfigOnlyFailures($company, null, 'artisan');
        $this->info("Converted {$n} bill(s) to plain bills (fbr_status NULL).");
        return self::SUCCESS;
    }
}
