<?php

namespace App\Services;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Minimal immutable per-bill quota facts, never a recoverable invoice/support copy. */
final class ProspectiveQuotaRetention
{
    public static function policy(int $companyId): ?array
    {
        $sub = Subscription::where('company_id', $companyId)->where('active', true)->latest('id')->first();
        if (! $sub || !$sub->hasLifecycleSnapshot()) {
            return null;
        }
        $policy = ProspectiveSubscriptionPolicy::snapshot($sub);
        if (! $policy || ! Schema::hasTable('prospective_pos_quota_retention')) {
            self::hold();
        }

        return $policy;
    }

    private static function hold(): never
    {
        throw ValidationException::withMessages(['subscription' => 'Exact retained bill quota requires review; no allowance has been inferred from daily totals.']);
    }

    public static function monthStart(int $companyId): \Carbon\CarbonInterface
    {
        $start = now()->startOfMonth();
        $s = self::policy($companyId);
        if ($s && ! empty($s['first_paid_starts_at'])) {
            $paid = CarbonImmutable::parse($s['first_paid_starts_at'])->setTimezone(config('app.timezone'));
            if ($paid->greaterThan($start)) {
                return $paid;
            }
        }

        return $start;
    }

    public static function capture(Collection $bills, string $source, int $sourceId): void
    {
        if ($bills->isEmpty()) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Quota retention requires the locked deletion transaction.');
        }
        foreach ($bills->groupBy('company_id') as $companyId => $rows) {
            if (! self::policy((int) $companyId)) {
                continue;
            }
            foreach ($rows as $bill) {
                if (! $bill->created_at) {
                    self::hold();
                }
                if (!Schema::hasColumn('prospective_pos_quota_retention','business_date')) self::hold();
                // Match the existing day-close predicate while the original row exists.
                $businessDate = $bill->business_date ?: $bill->created_at->toDateString();
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$businessDate)) self::hold();
                $values = ['business_date'=>$businessDate, 'sold_at' => $bill->created_at->format('Y-m-d H:i:s'), 'quota_counted' => (int) ($bill->status === 'completed' && $bill->invoice_mode !== 'local' && ($bill->transaction_type ?? 'sale') !== 'return'),
                    'aggregate_final' => (int) ! ($bill->invoice_mode === 'local' && $bill->pra_status === 'local'), 'source' => $source, 'source_id' => $sourceId];
                $existing = DB::table('prospective_pos_quota_retention')->where('company_id', $companyId)->where('transaction_id', $bill->id)->lockForUpdate()->first();
                if ($existing) {
                    foreach ($values as $key => $value) {
                        if ((string) $existing->$key !== (string) $value) {
                            self::hold();
                        }
                    }

                    continue;
                }
                DB::table('prospective_pos_quota_retention')->insert($values + ['company_id' => $companyId, 'transaction_id' => $bill->id, 'captured_at' => now()]);
            }
        }
    }

    public static function deletedCount(int $companyId): int
    {
        $s = self::policy($companyId);
        if (! $s) {
            throw new \LogicException('Legacy quota must use its existing retained aggregates.');
        }
        // A new-policy company cannot acquire unexplained older-style deletion totals.
        foreach (['day-close' => 'pos_day_close_reports', 'archive-clear' => 'pos_local_series_resets'] as $source => $table) {
            if (! Schema::hasTable($table)) {
                self::hold();
            }
            $reports = DB::table($table)->where('company_id', $companyId)->where('deleted_final_count', '>', 0)->get();
            foreach ($reports as $report) {
                $q = DB::table('prospective_pos_quota_retention')->where('company_id', $companyId)->where('source', $source)->where('source_id', $report->id)->where('aggregate_final', true);
                if ($source === 'day-close') {
                    if (!Schema::hasColumn('prospective_pos_quota_retention','business_date')) self::hold();
                    $month = CarbonImmutable::parse($report->report_date)->startOfMonth();
                    $q->where('business_date','>=',$month->toDateString())->where('business_date','<',$month->addMonth()->toDateString());
                }
                if ($source === 'archive-clear') {
                    $month = CarbonImmutable::parse($report->reset_date)->startOfMonth();
                    $q->where('sold_at', '>=', $month)->where('sold_at', '<', $month->addMonth());
                }
                if ($q->count() !== (int) $report->deleted_final_count) {
                    self::hold();
                }
            }
        }
        if (!Schema::hasTable('pos_bill_deletions')) self::hold();
        if (DB::table('pos_bill_deletions as d')->where('d.company_id', $companyId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prospective_pos_quota_retention as q')->whereColumn('q.company_id', 'd.company_id')->whereColumn('q.transaction_id', 'd.transaction_id'))->exists()) {
            self::hold();
        }

        // Anti-join prevents a capture followed by a rolled-back/unexecuted delete
        // from counting both live and retained copies, even under synthetic repair.
        return DB::table('prospective_pos_quota_retention as q')->where('q.company_id', $companyId)->where('q.quota_counted', true)
            ->whereBetween('q.sold_at', [self::monthStart($companyId), now()->endOfMonth()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pos_transactions as p')->whereColumn('p.company_id', 'q.company_id')->whereColumn('p.id','q.transaction_id'))->count();
    }
}
