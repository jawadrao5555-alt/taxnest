<?php

namespace App\Console\Commands;

use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\PosDayCloseReport;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AutoCloseDayPos extends Command
{
    protected $signature = 'pos:auto-dayclose';

    protected $description = 'Auto-close prior POS trading days for companies that opted into auto day-close (Customize POS → Local Bills). A day closes at 6:00 AM the NEXT morning if nobody closed it manually.';

    public function handle(PosController $pos): int
    {
        // Column may be missing on PROD until the migration lands — fail safe.
        if (! Schema::hasColumn('companies', 'pos_auto_dayclose_24h')) {
            $this->warn('Column pos_auto_dayclose_24h missing — run migrations first.');
            return self::SUCCESS;
        }

        $companies = Company::where('pos_auto_dayclose_24h', true)
            ->where('product_type', 'pos')
            ->get(['id', 'name', 'restaurant_mode']);

        if ($companies->isEmpty()) {
            $this->info('No companies with auto day-close enabled.');
            return self::SUCCESS;
        }

        // NEXT-MORNING rule (owner decision 23 Jul 2026 — replaces the older
        // "second midnight / 1-day grace" rule): if nobody closed a trading day
        // manually, it auto-closes at the company's day-close cutoff the NEXT
        // morning (Pakistan time; app tz = Asia/Karachi; default 06:00, per-company
        // via Day Close page since 30 Jul 2026). Before the cutoff yesterday stays
        // OPEN — a late-night shop (or its owner) can still close it manually;
        // from the cutoff onward everything before TODAY is swept. Command runs
        // hourly, so a missed cron tick self-heals on the next hour.
        $nowTime = now()->format('H:i');
        $closedTotal = 0;

        foreach ($companies as $company) {
            try {
                $cutoffTime = \App\Services\PosBusinessDay::cutoffFor($company->id);
                $graceCutoff = $nowTime >= $cutoffTime
                    ? today()->toDateString()            // past cutoff: close everything before today (incl. yesterday)
                    : today()->subDay()->toDateString(); // before cutoff: yesterday keeps its grace window
                // Prior un-closed trading days before the cutoff. Include archived
                // rows so a day is still detected even if some bills were archived.
                // Days are keyed by BUSINESS date (owner rule 26 Jul 2026): an
                // after-midnight bill belongs to the previous trading day, so the
                // auto-close must sweep by business_date or those bills would
                // re-open an already-closed day. Falls back to DATE(created_at)
                // until the migration lands on PROD.
                $hasBizDate = Schema::hasColumn('pos_transactions', 'business_date');
                $dates = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $company->id)
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

                // POLICY (owner decision 10 Aug 2026): if the company runs the
                // restaurant module AND there are still open orders (held /
                // preparing / ready with items), SKIP the auto-close for today
                // and emit a log warning instead of closing silently past live
                // orders. A stranded business day is far preferable to closing
                // while a table is still occupied — staff or the owner must close
                // manually once the orders are settled. The command runs hourly,
                // so the day will auto-close on the NEXT run if no orders remain.
                if ($company->restaurant_mode && \Schema::hasTable('restaurant_orders')) {
                    $openCount = \App\Models\RestaurantOrder::where('company_id', $company->id)
                        ->whereIn('status', ['held', 'preparing', 'ready'])
                        ->whereHas('items')
                        ->count();
                    if ($openCount > 0) {
                        $msg = "Company {$company->id}: auto-close SKIPPED — {$openCount} open order(s) still active. Staff must settle and close manually.";
                        $this->warn($msg);
                        Log::warning('pos:auto-dayclose skipped — open orders', [
                            'company_id' => $company->id,
                            'open_orders' => $openCount,
                            'dates_pending' => $dates->values(),
                        ]);
                        // Task 454: alert the owner by email — the log line alone
                        // reaches nobody. Throttled to ONE email per company per
                        // calendar day (the command runs hourly); if the day is
                        // still stranded tomorrow, tomorrow's run alerts again.
                        $this->sendSkipAlert($company, $openCount, $dates);
                        continue; // skip to next company
                    }
                }

                // A system-run close still records a closer — use the company admin.
                $adminId = User::where('company_id', $company->id)
                    ->whereIn('pos_role', ['pos_admin', 'company_admin'])
                    ->value('id');

                // The local-bill wash inside performDayClose follows the STANDING
                // company policy (Customize POS → Local Billing) — same as manual close.
                foreach ($dates as $date) {
                    if (PosDayCloseReport::where('company_id', $company->id)->where('report_date', $date)->exists()) {
                        continue;
                    }

                    $result = $pos->performDayClose(
                        $company->id,
                        $date,
                        $adminId,
                        'Auto-closed by system (' . $cutoffTime . ' next day)'
                    );

                    if ($result['status'] === 'created') {
                        $closedTotal++;
                        $line = "Company {$company->id}: closed {$date} → {$result['report_number']} (archived {$result['archived']}, deleted {$result['deleted']}).";
                        // Khud Final sweep surfacing (Task 165): the sweep result is
                        // already stored durably on the Z-report row (local_summary →
                        // day-close page + PDF), but the user-less auto close must
                        // also LOG what it finalized so the trail exists even if the
                        // report row is later purged. Zero-count sweeps stay quiet.
                        $sweep = $result['finalize_sweep'] ?? null;
                        if (is_array($sweep) && (($sweep['finalized'] ?? 0) > 0 || ($sweep['quota_blocked'] ?? 0) > 0 || ($sweep['offline'] ?? 0) > 0)) {
                            $line .= " Khud Final sweep: finalized {$sweep['finalized']} (PKR {$sweep['finalized_amount']}) — submitted {$sweep['submitted']}, queued {$sweep['queued']}, offline {$sweep['offline']}, quota-blocked {$sweep['quota_blocked']}, skipped {$sweep['skipped']}.";
                            Log::info('pos:auto-dayclose finalize sweep', [
                                'company_id' => $company->id,
                                'date' => $date,
                                'report_number' => $result['report_number'],
                            ] + $sweep);
                        }
                        $this->info($line);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('pos:auto-dayclose failed for company ' . $company->id . ': ' . $e->getMessage());
                $this->error("Company {$company->id}: " . $e->getMessage());
            }
        }

        $this->info("Auto day-close complete — {$closedTotal} day(s) closed.");
        return self::SUCCESS;
    }

    /**
     * Task 454: email the company admin/owner when the auto day-close is
     * skipped because restaurant orders are still open. Throttled via cache to
     * one email per company per calendar day (command runs hourly), so a day
     * still stranded on the NEXT morning's run triggers a fresh alert. Mail
     * failure never breaks the close loop — the log warning already exists.
     */
    private function sendSkipAlert(Company $company, int $openCount, $pendingDates): void
    {
        $cacheKey = 'pos_autoclose_skip_alert:' . $company->id . ':' . today()->toDateString();
        try {
            // Reserve the daily slot atomically (guards concurrent runs), but
            // RELEASE it on any failure below — only a successfully sent email
            // may consume the quota, otherwise a transient SMTP hiccup would
            // silence every retry for the rest of the day.
            if (! \Illuminate\Support\Facades\Cache::add($cacheKey, 1, now()->endOfDay())) {
                return;
            }

            // Owner = users.role 'company_admin' (pos_role is often NULL on
            // owner rows); fall back to a POS admin account if no owner email.
            $email = User::where('company_id', $company->id)
                ->where(function ($q) {
                    $q->where('role', 'company_admin')
                        ->orWhereIn('pos_role', ['pos_admin', 'company_admin']);
                })
                ->whereNotNull('email')
                ->orderByRaw("CASE WHEN role = 'company_admin' THEN 0 ELSE 1 END")
                ->value('email');
            if (! $email) {
                Log::warning('pos:auto-dayclose skip alert — no admin email', ['company_id' => $company->id]);
                \Illuminate\Support\Facades\Cache::forget($cacheKey); // retry next hour — an admin may be added
                return;
            }

            // Which tables are still occupied (dine-in orders with a table).
            $tables = \App\Models\RestaurantOrder::where('company_id', $company->id)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->whereHas('items')
                ->with('table:id,table_number')
                ->get(['id', 'table_id'])
                ->pluck('table.table_number')
                ->filter()
                ->unique()
                ->values();

            $pendingList = collect($pendingDates)->implode(', ');

            $paragraphs = [
                "Aaj ka auto day-close skip ho gaya kyunke {$openCount} order(s) abhi bhi open hain"
                    . ($tables->isNotEmpty() ? ' (tables: ' . $tables->implode(', ') . ')' : '') . '.',
                "Pending day(s): {$pendingList}.",
                'Kya karna hai: pehle sab open orders settle karein (payment le kar band karein), phir POS ke Day Close page se din khud close karein.',
                'Agar orders settle ho jayen to agla auto-close run (har ghantay) din khud band kar dega — lekin intezaar na karein, manually close karna behtar hai.',
            ];

            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                subjectLine: 'Day Close skip ho gaya — orders abhi open hain',
                companyName: $company->name ?? 'your company',
                headline: 'Auto Day-Close skip ho gaya',
                paragraphs: $paragraphs,
                ctaUrl: url('/pos/day-close'),
                ctaLabel: 'Day Close Page Kholen',
                panelName: 'PRA POS',
            ));

            if (class_exists(\App\Services\MailHealth::class)) {
                \App\Services\MailHealth::recordSuccess();
            }
            Log::info('pos:auto-dayclose skip alert emailed', [
                'company_id' => $company->id,
                'open_orders' => $openCount,
            ]);
        } catch (\Throwable $e) {
            // Free the daily slot so the next hourly run retries the alert.
            try {
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            } catch (\Throwable $ignored) {
            }
            Log::warning('pos:auto-dayclose skip alert email failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
            if (class_exists(\App\Services\MailHealth::class)) {
                \App\Services\MailHealth::recordFailure('Auto day-close skip alert', $e);
            }
        }
    }
}
