<?php

namespace App\Console\Commands;

use App\Services\PosBusinessDay;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 499 (PRA) + Task 509 (FBR mirror) — one-off (but idempotent & safe to
 * re-run) backfill for POS bills that were queued offline between midnight and
 * the company's day-close cutoff and synced next morning BEFORE the sync-path
 * re-stamp fix. The creating hook stamped business_date from the SYNC moment,
 * so those bills sit in the wrong trading day on day-close / Z-report /
 * dashboard history. Covers BOTH products: pos_transactions (day-close table
 * pos_day_close_reports) and fbr_pos_transactions (fbr_day_close_reports) —
 * the two products close their days independently.
 *
 * Rules (identical for both tables):
 *  - Recomputes the trading day HISTORICALLY: yesterday counts only if its
 *    day-close report did not yet exist at the original sale moment
 *    (PosBusinessDay::forMoment/forMomentFbr check "closed NOW", which is
 *    wrong for old rows — yesterday has almost always been closed since).
 *  - A closed day never reopens: if the corrected target day has since been
 *    day-closed (its Z-report is final), the row is SKIPPED, mirroring the
 *    controller sync-time rule.
 *  - Only business_date is written (DB::table update — no updated_at bump);
 *    created_at, serials and PRA/FBR fields are never touched.
 */
class PosBackfillBusinessDate extends Command
{
    protected $signature = 'pos:backfill-business-date {--dry-run : Report what would change without writing}';

    protected $description = 'Re-stamp pos_transactions + fbr_pos_transactions business_date for old late-night offline bills stuck in the wrong trading day (Tasks 499/509)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $tz = config('app.timezone');
        $ranAny = false;

        foreach ([
            ['pos_transactions', 'pos_day_close_reports', 'PRA POS'],
            ['fbr_pos_transactions', 'fbr_day_close_reports', 'FBR POS'],
        ] as [$txnTable, $reportTable, $label]) {
            if (!Schema::hasColumn($txnTable, 'business_date')) {
                $this->warn("{$label}: {$txnTable}.business_date column missing — skipped.");
                continue;
            }
            $ranAny = true;
            $this->info("== {$label} ({$txnTable}) ==");
            $this->repairTable($txnTable, $reportTable, $dry, $tz);
        }

        return $ranAny ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Scan one transactions table and repair wrong-day business_date stamps,
     * keyed against that product's own day-close reports table.
     */
    protected function repairTable(string $txnTable, string $reportTable, bool $dry, string $tz): void
    {
        $scanned = 0;
        $repaired = 0;
        $skippedClosed = 0;
        $perCompany = [];

        // Candidates: only rows whose wall-clock time is before noon can ever
        // belong to the previous trading day (cutoff is validated < 12:00).
        DB::table($txnTable)
            ->whereNotNull('business_date')
            ->whereNotNull('created_at')
            ->whereRaw("TIME(created_at) < '12:00:00'")
            ->orderBy('id')
            ->select(['id', 'company_id', 'created_at', 'business_date'])
            ->chunk(500, function ($rows) use (&$scanned, &$repaired, &$skippedClosed, &$perCompany, $dry, $tz, $txnTable, $reportTable) {
                foreach ($rows as $row) {
                    $scanned++;
                    $at = Carbon::parse($row->created_at, $tz);
                    if ($at->format('H:i') >= PosBusinessDay::cutoffFor((int) $row->company_id)) {
                        continue; // at/after cutoff — calendar date is always right
                    }

                    // Historical trading day: yesterday, unless yesterday's
                    // day-close report already existed at the sale moment.
                    $yesterday = $at->copy()->subDay()->toDateString();
                    $closedAtSaleMoment = DB::table($reportTable)
                        ->where('company_id', $row->company_id)
                        ->where('report_date', $yesterday)
                        ->where('created_at', '<=', $at)
                        ->exists();
                    $expected = $closedAtSaleMoment ? $at->toDateString() : $yesterday;

                    $current = substr((string) $row->business_date, 0, 10);
                    if ($current === $expected) {
                        continue;
                    }

                    // A closed day never reopens (its Z-report is final):
                    // never move a bill INTO an already-closed trading day.
                    $targetClosedNow = DB::table($reportTable)
                        ->where('company_id', $row->company_id)
                        ->where('report_date', $expected)
                        ->exists();
                    if ($targetClosedNow) {
                        $skippedClosed++;
                        continue;
                    }

                    $this->line(($dry ? '[dry] ' : '') . "{$txnTable} #{$row->id} (company {$row->company_id}, {$row->created_at}): {$current} -> {$expected}");
                    if (!$dry) {
                        // Raw update: only business_date, no updated_at bump.
                        DB::table($txnTable)->where('id', $row->id)->update(['business_date' => $expected]);
                    }
                    $repaired++;
                    $perCompany[$row->company_id] = ($perCompany[$row->company_id] ?? 0) + 1;
                }
            });

        $this->info("Scanned pre-noon rows: {$scanned}");
        $this->info(($dry ? 'Would repair: ' : 'Repaired: ') . $repaired);
        $this->info("Skipped (target day already closed): {$skippedClosed}");
        foreach ($perCompany as $cid => $n) {
            $this->line("  company {$cid}: {$n}");
        }
    }
}
