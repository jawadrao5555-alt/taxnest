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
            ->get(['id']);

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
                        $this->info("Company {$company->id}: closed {$date} → {$result['report_number']} (archived {$result['archived']}, deleted {$result['deleted']}).");
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
}
