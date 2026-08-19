<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FbrDayCloseReport;
use App\Models\FbrPosTransaction;
use App\Services\FbrPosPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1275 — FBR day-close reminder push: shops that traded today but have
 * not closed the day yet get a nudge in the evening (scheduled dailyAt 21:30
 * Asia/Karachi; cron on prod runs schedule:run).
 *
 * Rules:
 *  - Only active, non-internal fbrpos companies with an ACTIVE subscription.
 *  - Companies with Auto Day-Close (24h) ON are skipped — the sweep closes
 *    their days for them, a reminder would be noise.
 *  - "Traded today" is keyed by business_date (hasColumn fallback to
 *    DATE(created_at), same as the auto-close sweep) — late-night shops whose
 *    after-midnight sales belong to yesterday are handled by the business-day
 *    convention itself.
 *  - Skipped when today's FbrDayCloseReport row already exists.
 *  - Cache guard: one reminder per company per date (manual re-runs safe);
 *    the nid also collapses duplicates in the Android tray.
 */
class SendFbrDayCloseReminders extends Command
{
    protected $signature = 'fbrpos:dayclose-reminders {--dry-run : Report what would be sent without pushing}';

    protected $description = 'Evening push reminder to FBR shop admins when today has sales but no day-close yet (auto-dayclose companies skipped).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (!$dry && !FbrPosPushService::ready()) {
            $this->info('FCM not configured (or devices table missing) — nothing to do.');

            return self::SUCCESS;
        }

        $hasAutoCol = Schema::hasColumn('companies', 'pos_auto_dayclose_24h');
        $hasBizDate = Schema::hasColumn('fbr_pos_transactions', 'business_date');
        $today = today()->toDateString();

        $companies = Company::where('product_type', 'fbrpos')
            ->where('company_status', 'active')
            ->where('is_internal_account', false)
            ->whereHas('subscriptions', fn ($q) => $q->where('active', true))
            ->when($hasAutoCol, fn ($q) => $q->where(function ($w) {
                $w->where('pos_auto_dayclose_24h', false)->orWhereNull('pos_auto_dayclose_24h');
            }))
            ->get(['id', 'name']);

        $reminded = 0;

        foreach ($companies as $company) {
            $traded = FbrPosTransaction::where('company_id', $company->id)
                ->when($hasBizDate,
                    fn ($q) => $q->where('business_date', $today),
                    fn ($q) => $q->whereDate('created_at', $today))
                ->exists();
            if (!$traded) {
                continue;
            }

            $closed = FbrDayCloseReport::where('company_id', $company->id)
                ->where('report_date', $today)
                ->exists();
            if ($closed) {
                continue;
            }

            $onceKey = 'fbr_dcrem_sent:' . $company->id . ':' . $today;
            if (Cache::has($onceKey)) {
                continue;
            }

            if ($dry) {
                $this->line("[dry-run] Company {$company->id} ({$company->name}): traded today, no close — would remind.");
                $reminded++;
                continue;
            }

            $devices = FbrPosPushService::sendDayCloseReminder((int) $company->id, $today);
            Cache::put($onceKey, now()->toDateTimeString(), now()->addHours(12));
            $this->info("Company {$company->id} ({$company->name}): reminded on {$devices} device(s).");
            $reminded++;
        }

        $this->info("Done — {$reminded} compan" . ($reminded === 1 ? 'y' : 'ies') . ' reminded.');

        return self::SUCCESS;
    }
}
