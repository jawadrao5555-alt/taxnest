<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrPosPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Task 1275 — FBR fail-queue threshold push: "N bills FBR ko report nahi
 * huay". The highest-value FBR-specific alert — unreported bills piling up
 * silently is the #1 way a shop drifts out of compliance.
 *
 * Rules:
 *  - Only active, non-internal fbrpos companies with an ACTIVE subscription
 *    and FBR reporting ON (reporting-off shops have nothing to report).
 *  - Count uses the Fail Queue PAGE predicate (fbr_status IN failed/pending/
 *    config_error + invoice_mode fbr-or-NULL) — what the admin will actually
 *    see when they open /fbr-pos/fail-queue. `pending` rows younger than
 *    30 minutes are excluded: the sale screen's auto-retry usually clears
 *    fresh ones, and alerting on a transient spike would cry wolf.
 *  - Threshold: 5+ stuck bills.
 *  - Throttle: at most one alert per company per 6 hours (cache flag), plus
 *    the per-day nid on the push itself collapses same-day notifications in
 *    the Android tray.
 *  - Runs every 30 min via schedule (cron on prod runs schedule:run).
 */
class SendFbrFailQueueAlerts extends Command
{
    protected $signature = 'fbrpos:fail-queue-alerts {--dry-run : Report what would be sent without pushing}';

    protected $description = 'Push an alert to FBR shop admins when 5+ bills are stuck unreported in the fail queue (throttled to one per company per 6h).';

    private const THRESHOLD = 5;

    private const REALERT_HOURS = 6;

    /** `pending` younger than this is normal in-flight traffic, not "stuck". */
    private const PENDING_MIN_AGE_MINUTES = 30;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (!$dry && !FbrPosPushService::ready()) {
            $this->info('FCM not configured (or devices table missing) — nothing to do.');

            return self::SUCCESS;
        }

        $companies = Company::where('product_type', 'fbrpos')
            ->where('company_status', 'active')
            ->where('is_internal_account', false)
            ->where('fbr_reporting_enabled', true)
            ->whereHas('subscriptions', fn ($q) => $q->where('active', true))
            ->get(['id', 'name']);

        $alerted = 0;
        $pendingCutoff = now()->subMinutes(self::PENDING_MIN_AGE_MINUTES);

        foreach ($companies as $company) {
            $count = FbrPosTransaction::where('company_id', $company->id)
                ->where(function ($q) use ($pendingCutoff) {
                    $q->whereIn('fbr_status', ['failed', 'config_error'])
                        ->orWhere(function ($p) use ($pendingCutoff) {
                            $p->where('fbr_status', 'pending')
                                ->where('created_at', '<=', $pendingCutoff);
                        });
                })
                ->where(function ($q) {
                    $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
                })
                ->count();

            if ($count < self::THRESHOLD) {
                continue;
            }

            $throttleKey = 'fbr_failq_alerted:' . $company->id;
            if (Cache::has($throttleKey)) {
                continue; // already alerted within the re-alert window
            }

            if ($dry) {
                $this->line("[dry-run] Company {$company->id} ({$company->name}): {$count} stuck bills — would push.");
                $alerted++;
                continue;
            }

            $devices = FbrPosPushService::sendFailQueueAlert((int) $company->id, $count);
            // Throttle even when 0 devices reached — re-running every 30 min
            // against a device-less company is pointless noise either way.
            Cache::put($throttleKey, now()->toDateTimeString(), now()->addHours(self::REALERT_HOURS));
            $this->info("Company {$company->id} ({$company->name}): {$count} stuck bills — pushed to {$devices} device(s).");
            $alerted++;
        }

        $this->info("Done — {$alerted} compan" . ($alerted === 1 ? 'y' : 'ies') . ' alerted.');

        return self::SUCCESS;
    }
}
