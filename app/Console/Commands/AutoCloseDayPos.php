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

    protected $description = 'Auto-close prior POS trading days at each company’s chosen auto-close time after its business-day cutoff.';

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

        // NEXT-MORNING rule: the business-day cutoff and auto-close time are
        // separate company settings. Before the auto-close time yesterday stays
        // OPEN; from it onward everything before TODAY is swept. The time is
        // clamped to the cutoff as a safety net for manually edited data.
        $nowTime = now()->format('H:i');
        $closedTotal = 0;

        foreach ($companies as $company) {
            try {
                $businessCutoff = \App\Services\PosBusinessDay::cutoffFor($company->id);
                $autoCloseTime = max(\App\Services\PosBusinessDay::autoCloseTimeFor($company->id), $businessCutoff);
                $graceCutoff = $nowTime >= $autoCloseTime
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
                $dates = $this->pendingDatesFor($company, $graceCutoff, $hasBizDate);

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
                $openCount = 0;
                if ($company->restaurant_mode && \Schema::hasTable('restaurant_orders')) {
                    $openCount = \App\Models\RestaurantOrder::where('company_id', $company->id)
                        ->whereIn('status', ['held', 'preparing', 'ready'])
                        ->whereHas('items')
                        ->count();
                }

                // Task 661 (ZFC): undispatched delivery bills ALSO make the sweep
                // skip — the ZFC owner's day auto-closed while delivery orders
                // never left the shop. Checked against the LATEST pending date so
                // a fresh today-only delivery never blocks closing older days.
                // Feature/schema-gated inside the helper (zero for non-rider
                // shops); pass null so the helper loads the full Company row.
                $undispatched = 0;
                try {
                    $undispatched = $pos->undispatchedDeliverySummary((int) $company->id, null, (string) $dates->max())->count;
                } catch (\Throwable $e) {
                    $undispatched = 0; // never let the checklist break the sweep
                }

                if ($openCount > 0 || $undispatched > 0) {
                    $msg = "Company {$company->id}: auto-close SKIPPED — {$openCount} open order(s), {$undispatched} undispatched delivery bill(s). Staff must settle and close manually.";
                    $this->warn($msg);
                    Log::warning('pos:auto-dayclose skipped — open orders / undispatched deliveries', [
                        'company_id' => $company->id,
                        'open_orders' => $openCount,
                        'undispatched_deliveries' => $undispatched,
                        'dates_pending' => $dates->values(),
                    ]);
                    // Task 454: alert the owner by email — the log line alone
                    // reaches nobody. Throttled to ONE email per company per
                    // calendar day (the command runs hourly); if the day is
                    // still stranded tomorrow, tomorrow's run alerts again.
                    $this->sendSkipAlert($company, $openCount, $dates, $undispatched);
                    continue; // skip to next company
                }

                // A system-run close still records a closer — use the company admin.
                $adminId = User::where('company_id', $company->id)
                    ->whereIn('pos_role', ['pos_admin', 'company_admin'])
                    ->value('id');

                // Task 1360: the 6 AM sweep follows the SAME rule as the manual
                // close — one Z-report per BRANCH per day. A multi-branch shop
                // gets one close per branch (each with its own figures, report
                // number, opening cash and local-bill wash); a branch-less shop
                // keeps exactly its old single company-wide close.
                foreach ($this->closeScopesFor($company) as $branchId) {
                    // Dates are re-detected per scope: a branch that did not
                    // trade on some day must not get an empty Z-report for it.
                    $scopeDates = $branchId === null
                        ? $dates
                        : $this->pendingDatesFor($company, $graceCutoff, $hasBizDate, $branchId);

                    // The local-bill wash inside performDayClose follows the STANDING
                    // company policy (Customize POS → Local Billing) — same as manual close.
                    foreach ($scopeDates as $date) {
                        if (PosDayCloseReport::where('company_id', $company->id)
                            ->forBranch($branchId)
                            ->where('report_date', $date)
                            ->exists()) {
                            continue;
                        }

                        $result = $pos->performDayClose(
                            $company->id,
                            $date,
                            $adminId,
                            'Auto-closed by system (' . $cutoffTime . ' next day)',
                            null,
                            false,
                            null,
                            $branchId
                        );

                        if ($result['status'] === 'created') {
                            $closedTotal++;
                            $scopeLabel = $branchId ? " branch {$branchId}:" : '';
                            $line = "Company {$company->id}:{$scopeLabel} closed {$date} → {$result['report_number']} (archived {$result['archived']}, deleted {$result['deleted']}).";
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
                                    'branch_id' => $branchId,
                                    'date' => $date,
                                    'report_number' => $result['report_number'],
                                ] + $sweep);
                            }
                            $this->info($line);
                        }
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
     * Close scopes for one company (Task 1360): its branch ids, or [null] for a
     * branch-less shop (unchanged company-wide close). The branches table may
     * be absent on a drifted box or a lean test schema — that is simply a
     * single-branch shop, never an error. Branch::withoutGlobalScope: the CLI
     * has no bound tenant, so the company filter is explicit here.
     */
    private function closeScopesFor(Company $company): array
    {
        try {
            if (! Schema::hasTable('branches') || ! Schema::hasColumn('pos_transactions', 'branch_id')) {
                return [null];
            }
            $ids = \App\Models\Branch::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
                ->where('company_id', $company->id)
                ->when(Schema::hasColumn('branches', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->orderBy('id')
                ->pluck('id')
                ->all();

            return empty($ids) ? [null] : $ids;
        } catch (\Throwable $e) {
            return [null]; // never let branch detection break the nightly sweep
        }
    }

    /**
     * Un-closed trading dates before the cutoff, optionally narrowed to ONE
     * branch. Scope semantics mirror the day-close page (branch rows + legacy
     * un-stamped rows) so the sweep closes exactly what that branch would see.
     */
    private function pendingDatesFor(Company $company, string $graceCutoff, bool $hasBizDate, ?int $branchId = null)
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $company->id)
            ->when($branchId && Schema::hasColumn('pos_transactions', 'branch_id'),
                fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when($hasBizDate,
                fn ($q) => $q->where('business_date', '<', $graceCutoff)
                    ->selectRaw('business_date as d'),
                fn ($q) => $q->whereDate('created_at', '<', $graceCutoff)
                    ->selectRaw('DATE(created_at) as d'))
            ->groupBy('d')
            ->pluck('d');
    }

    /**
     * Task 454: email the company admin/owner when the auto day-close is
     * skipped because restaurant orders are still open. Throttled via cache to
     * one email per company per calendar day (command runs hourly), so a day
     * still stranded on the NEXT morning's run triggers a fresh alert. Mail
     * failure never breaks the close loop — the log warning already exists.
     */
    private function sendSkipAlert(Company $company, int $openCount, $pendingDates, int $undispatchedCount = 0): void
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
            $tables = $openCount > 0
                ? \App\Models\RestaurantOrder::where('company_id', $company->id)
                    ->whereIn('status', ['held', 'preparing', 'ready'])
                    ->whereHas('items')
                    ->with('table:id,table_number')
                    ->get(['id', 'table_id'])
                    ->pluck('table.table_number')
                    ->filter()
                    ->unique()
                    ->values()
                : collect();

            $pendingList = collect($pendingDates)->implode(', ');

            // Task 661: the alert states EVERY skip reason — open orders and/or
            // undispatched delivery bills (either alone can strand the day).
            $reasons = [];
            if ($openCount > 0) {
                $reasons[] = "{$openCount} order(s) abhi bhi open hain"
                    . ($tables->isNotEmpty() ? ' (tables: ' . $tables->implode(', ') . ')' : '');
            }
            if ($undispatchedCount > 0) {
                $reasons[] = "{$undispatchedCount} delivery bill(s) abhi tak kisi rider ko dispatch nahi hui";
            }

            $paragraphs = [
                'Aaj ka auto day-close skip ho gaya kyunke ' . implode(' aur ', $reasons) . '.',
                "Pending day(s): {$pendingList}.",
                'Kya karna hai: pehle sab open orders settle karein (payment le kar band karein) aur delivery bills riders ko dispatch/settle karein, phir POS ke Day Close page se din khud close karein.',
                'Agar sab kuch settle ho jaye to agla auto-close run (har ghantay) din khud band kar dega — lekin intezaar na karein, manually close karna behtar hai.',
            ];

            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                subjectLine: 'Day Close skip ho gaya — kaam abhi pending hai',
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
