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

    protected $description = 'Auto-close prior POS trading days for companies that opted into midnight auto day-close (Customize POS → Local Bills). A day closes at the second midnight after it (1 full day grace).';

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
            $this->info('No companies with midnight auto day-close enabled.');
            return self::SUCCESS;
        }

        // MIDNIGHT-BASED with a 1-full-day grace (owner decision Jul 2026): a trading
        // day is auto-closed at the SECOND midnight after it — e.g. Monday's day closes
        // at Wednesday 00:00 (Pakistan time; app tz = Asia/Karachi). This is NOT the
        // old "last bill + 24h inactivity" rule; it is purely calendar/midnight based.
        // So we only sweep days whose calendar date is strictly BEFORE yesterday.
        $graceCutoff = today()->subDay()->toDateString(); // = yesterday; close days < yesterday
        $closedTotal = 0;

        foreach ($companies as $company) {
            try {
                // Prior trading days OLDER than yesterday that are not yet closed
                // (yesterday itself is still within its grace day). Include archived
                // rows so a day is still detected even if some bills were archived.
                $dates = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $company->id)
                    ->whereDate('created_at', '<', $graceCutoff)
                    ->selectRaw('DATE(created_at) as d')
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
                        'Auto-closed by system (midnight, 1-day grace)'
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
