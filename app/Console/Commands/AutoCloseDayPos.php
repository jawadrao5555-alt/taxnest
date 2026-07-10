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

    protected $description = 'Auto-close prior POS trading days for companies that opted into 24h auto day-close (Customize POS → Local Bills).';

    public function handle(PosController $pos): int
    {
        // Column may be missing on PROD until the migration lands — fail safe.
        if (! Schema::hasColumn('companies', 'pos_auto_dayclose_24h')) {
            $this->warn('Column pos_auto_dayclose_24h missing — run migrations first.');
            return self::SUCCESS;
        }

        $companies = Company::where('pos_auto_dayclose_24h', true)
            ->where('product_type', 'pos')
            ->get(['id', 'pos_auto_purge_local_on_dayclose']);

        if ($companies->isEmpty()) {
            $this->info('No companies with 24h auto day-close enabled.');
            return self::SUCCESS;
        }

        $today  = today()->toDateString();
        $cutoff = now()->subDay()->toDateTimeString(); // 24h of inactivity
        $closedTotal = 0;

        foreach ($companies as $company) {
            try {
                // Prior trading days (before today) whose LAST bill is >= 24h old and
                // that are not yet closed. Include archived rows so a day is still
                // detected even if some of its bills were archived earlier.
                $dates = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $company->id)
                    ->whereDate('created_at', '<', $today)
                    ->selectRaw('DATE(created_at) as d')
                    ->groupBy('d')
                    ->havingRaw('MAX(created_at) <= ?', [$cutoff])
                    ->pluck('d');

                if ($dates->isEmpty()) {
                    continue;
                }

                // A system-run close still records a closer — use the company admin.
                $adminId = User::where('company_id', $company->id)
                    ->whereIn('pos_role', ['pos_admin', 'company_admin'])
                    ->value('id');

                // Purge/archive follows the SAME company policy as a manual day-close
                // (Customize POS → "Day-close par local bills archive").
                $purge = (bool) ($company->pos_auto_purge_local_on_dayclose ?? false);

                foreach ($dates as $date) {
                    if (PosDayCloseReport::where('company_id', $company->id)->where('report_date', $date)->exists()) {
                        continue;
                    }

                    $result = $pos->performDayClose(
                        $company->id,
                        $date,
                        $adminId,
                        $purge,
                        'Auto-closed by system (24h inactivity)'
                    );

                    if ($result['status'] === 'created') {
                        $closedTotal++;
                        $this->info("Company {$company->id}: closed {$date} → {$result['report_number']} (archived {$result['archived']}).");
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
