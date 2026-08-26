<?php

namespace App\Console\Commands;

use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\FbrDayCloseReport;
use App\Models\FbrPosTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Task 676 — FBR twin of pos:auto-dayclose (PRA AutoCloseDayPos): auto-close
 * prior FBR POS trading days for companies that ticked the auto day-close
 * checkbox on the Day Close page. Same shared company flag + cutoff columns;
 * ZFC-style protection: a day with undispatched delivery bills is SKIPPED
 * (with a throttled owner email) — never closed silently past live deliveries.
 */
class AutoCloseDayFbrPos extends Command
{
    protected $signature = 'fbrpos:auto-dayclose';

    protected $description = 'Auto-close prior FBR POS trading days for companies that opted into auto day-close (Day Close page checkbox). A day closes at the company cutoff the NEXT morning if nobody closed it manually.';

    public function handle(FbrPosController $fbr): int
    {
        // Column may be missing on PROD until the migration lands — fail safe.
        if (! Schema::hasColumn('companies', 'pos_auto_dayclose_24h')) {
            $this->warn('Column pos_auto_dayclose_24h missing — run migrations first.');
            return self::SUCCESS;
        }

        $companies = Company::where('pos_auto_dayclose_24h', true)
            ->where('product_type', 'fbrpos')
            ->get(['id', 'name']);

        if ($companies->isEmpty()) {
            $this->info('No FBR companies with auto day-close enabled.');
            return self::SUCCESS;
        }

        // NEXT-MORNING rule (same as PRA): past the company's independent
        // auto-close time, everything before TODAY is swept; before it,
        // yesterday keeps its grace window. Never run before the business-day
        // cutoff, even if data was manually edited to an earlier value.
        $nowTime = now()->format('H:i');
        $closedTotal = 0;

        foreach ($companies as $company) {
            try {
                $businessCutoff = \App\Services\PosBusinessDay::cutoffFor($company->id);
                $autoCloseTime = max(\App\Services\PosBusinessDay::autoCloseTimeFor($company->id), $businessCutoff);
                $graceCutoff = $nowTime >= $autoCloseTime
                    ? today()->toDateString()
                    : today()->subDay()->toDateString();

                // Prior un-closed trading days keyed by BUSINESS date (falls
                // back to DATE(created_at) until the migration lands on PROD).
                $hasBizDate = Schema::hasColumn('fbr_pos_transactions', 'business_date');
                $dates = FbrPosTransaction::where('company_id', $company->id)
                    ->when($hasBizDate,
                        fn ($q) => $q->where('business_date', '<', $graceCutoff)
                            ->selectRaw('business_date as d'),
                        fn ($q) => $q->whereDate('created_at', '<', $graceCutoff)
                            ->selectRaw('DATE(created_at) as d'))
                    ->groupBy('d')
                    ->pluck('d');

                if ($dates->isEmpty()) {
                    continue;
                }

                // ZFC protection (Task 676 — FBR mirror of PRA Task 661):
                // undispatched delivery bills make the sweep SKIP the company —
                // the day must not close while delivery orders never left the
                // shop. Checked against the LATEST pending date so a fresh
                // today-only delivery never blocks closing older days.
                // Feature/schema-gated inside the helper (zero for non-rider shops).
                $undispatched = 0;
                try {
                    $undispatched = $fbr->undispatchedDeliverySummary((int) $company->id, null, (string) $dates->max())->count;
                } catch (\Throwable $e) {
                    $undispatched = 0; // never let the checklist break the sweep
                }

                if ($undispatched > 0) {
                    $msg = "Company {$company->id}: FBR auto-close SKIPPED — {$undispatched} undispatched delivery bill(s). Staff must settle and close manually.";
                    $this->warn($msg);
                    Log::warning('fbrpos:auto-dayclose skipped — undispatched deliveries', [
                        'company_id' => $company->id,
                        'undispatched_deliveries' => $undispatched,
                        'dates_pending' => $dates->values(),
                    ]);
                    // Owner email — the log line alone reaches nobody. Throttled
                    // to ONE email per company per calendar day.
                    $this->sendSkipAlert($company, $dates, $undispatched);
                    continue;
                }

                // A system-run close still records a closer — use the company admin.
                $adminId = User::where('company_id', $company->id)
                    ->whereIn('pos_role', ['pos_admin', 'company_admin'])
                    ->value('id');

                // Khud Final sweep inside performDayClose follows the STANDING
                // company policy — the auto-close never passes an override.
                foreach ($dates as $date) {
                    if (FbrDayCloseReport::where('company_id', $company->id)->where('report_date', $date)->exists()) {
                        continue;
                    }

                    $report = $fbr->performDayClose(
                        $company->id,
                        (string) $date,
                        $adminId,
                        'Auto-closed by system (' . $autoCloseTime . ' next day)',
                        null,
                        true // prior stranded days may close empty (mirror of closeAllPriorDays)
                    );

                    if ($report) {
                        $closedTotal++;
                        $this->info("Company {$company->id}: closed {$date} → {$report->report_number}.");
                    }
                }
            } catch (\Throwable $e) {
                Log::error('fbrpos:auto-dayclose failed for company ' . $company->id . ': ' . $e->getMessage());
                $this->error("Company {$company->id}: " . $e->getMessage());
            }
        }

        $this->info("FBR auto day-close complete — {$closedTotal} day(s) closed.");
        return self::SUCCESS;
    }

    /**
     * Email the company admin/owner when the FBR auto day-close is skipped
     * because delivery bills are still undispatched (mirror of the PRA
     * skip-alert). Throttled via cache to one email per company per calendar
     * day; mail failure never breaks the close loop.
     */
    private function sendSkipAlert(Company $company, $pendingDates, int $undispatchedCount): void
    {
        $cacheKey = 'fbrpos_autoclose_skip_alert:' . $company->id . ':' . today()->toDateString();
        try {
            // Reserve the daily slot atomically; RELEASE it on any failure so a
            // transient SMTP hiccup does not silence retries for the whole day.
            if (! \Illuminate\Support\Facades\Cache::add($cacheKey, 1, now()->endOfDay())) {
                return;
            }

            $email = User::where('company_id', $company->id)
                ->where(function ($q) {
                    $q->where('role', 'company_admin')
                        ->orWhereIn('pos_role', ['pos_admin', 'company_admin']);
                })
                ->whereNotNull('email')
                ->orderByRaw("CASE WHEN role = 'company_admin' THEN 0 ELSE 1 END")
                ->value('email');
            if (! $email) {
                Log::warning('fbrpos:auto-dayclose skip alert — no admin email', ['company_id' => $company->id]);
                \Illuminate\Support\Facades\Cache::forget($cacheKey); // retry next hour
                return;
            }

            $pendingList = collect($pendingDates)->implode(', ');

            $paragraphs = [
                "Aaj ka auto day-close skip ho gaya kyunke {$undispatchedCount} delivery bill(s) abhi tak kisi rider ko dispatch nahi hui.",
                "Pending day(s): {$pendingList}.",
                'Kya karna hai: pehle delivery bills riders ko dispatch/settle karein, phir POS ke Day Close page se din khud close karein.',
                'Agar sab kuch settle ho jaye to agla auto-close run (har ghantay) din khud band kar dega — lekin intezaar na karein, manually close karna behtar hai.',
            ];

            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                subjectLine: 'Day Close skip ho gaya — kaam abhi pending hai',
                companyName: $company->name ?? 'your company',
                headline: 'Auto Day-Close skip ho gaya',
                paragraphs: $paragraphs,
                ctaUrl: url('/fbr-pos/day-close'),
                ctaLabel: 'Day Close Page Kholen',
                panelName: 'FBR POS',
            ));

            if (class_exists(\App\Services\MailHealth::class)) {
                \App\Services\MailHealth::recordSuccess();
            }
            Log::info('fbrpos:auto-dayclose skip alert emailed', [
                'company_id' => $company->id,
                'undispatched_deliveries' => $undispatchedCount,
            ]);
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            } catch (\Throwable $ignored) {
            }
            Log::warning('fbrpos:auto-dayclose skip alert email failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
            if (class_exists(\App\Services\MailHealth::class)) {
                \App\Services\MailHealth::recordFailure('FBR auto day-close skip alert', $e);
            }
        }
    }
}
