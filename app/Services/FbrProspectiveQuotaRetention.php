<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Exact future-only quota facts for deleted FBR local provisionals; no fiscal action. */
final class FbrProspectiveQuotaRetention
{
    private static function required(int $companyId): bool
    {
        if (! ProspectiveQuotaRetention::policy($companyId)) {
            return false;
        }
        if (! Schema::hasTable('prospective_fbr_quota_retention')) {
            self::hold();
        }

        return true;
    }

    private static function hold(): never
    {
        throw ValidationException::withMessages(['subscription' => 'Exact retained FBR bill quota requires review; no allowance was reconstructed.']);
    }

    public static function capture(Collection $rows, string $source): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('FBR quota retention requires the locked deletion transaction.');
        }
        if (! in_array($source, ['single-delete', 'day-close'], true)) {
            throw new \LogicException('Unsupported FBR quota deletion source.');
        }
        foreach ($rows->groupBy('company_id') as $companyId => $bills) {
            if (! self::required((int) $companyId)) {
                continue;
            }
            foreach ($bills as $bill) {
                // This helper is only for the existing authorized provisional delete paths.
                if (! $bill->created_at || $bill->invoice_mode !== 'local' || $bill->fbr_status !== 'local' || $bill->fbr_invoice_number) {
                    self::hold();
                }
                $values = ['sold_at' => $bill->created_at->format('Y-m-d H:i:s'),
                    'quota_counted' => (int) ($bill->status === 'completed' && $bill->transaction_type === 'sale'), 'source' => $source];
                $prior = DB::table('prospective_fbr_quota_retention')->where('company_id', $companyId)->where('transaction_id', $bill->id)->lockForUpdate()->first();
                if ($prior) {
                    foreach ($values as $key => $value) {
                        if ((string) $prior->$key !== (string) $value) {
                            self::hold();
                        }
                    }

                    continue;
                }
                DB::table('prospective_fbr_quota_retention')->insert($values + ['company_id' => $companyId, 'transaction_id' => $bill->id, 'captured_at' => now()]);
            }
        }
    }

    public static function assertCapacity(int $companyId, ?array $context): void
    {
        if (!$context || $context['limit'] < 0) return;
        if (DB::transactionLevel() < 1) throw new \LogicException('FBR capacity requires the locked write transaction.');
        $live = DB::table('fbr_pos_transactions')->where('company_id', $companyId)
            ->where('status', 'completed')->where('transaction_type', 'sale')
            ->whereBetween('created_at', [$context['from'], now()->endOfMonth()])
            ->orderBy('id')->lockForUpdate()->get(['id'])->count();
        if ($live + self::deletedCount($companyId, true, $context['from']) >= $context['limit']) {
            ProspectiveQuotaGuard::deny();
        }
    }

    public static function deletedCount(int $companyId, bool $currentRead = false, ?\Carbon\CarbonInterface $from = null): int
    {
        if ($currentRead && DB::transactionLevel() < 1) throw new \LogicException('Current FBR quota reads require the locked write transaction.');
        if (!$currentRead && !self::required($companyId)) return 0;
        if (!Schema::hasTable('prospective_fbr_quota_retention')) self::hold();
        $from ??= ProspectiveQuotaRetention::monthStart($companyId);
        $query = DB::table('prospective_fbr_quota_retention as q')->where('q.company_id', $companyId)->where('q.quota_counted', true)
            ->whereBetween('q.sold_at', [$from, now()->endOfMonth()]);
        if ($currentRead) {
            // Explicit current row reads: a nested anti-join may retain an old
            // REPEATABLE READ snapshot even when the outer rows are locked.
            $liveIds = DB::table('fbr_pos_transactions')->where('company_id', $companyId)
                ->whereBetween('created_at', [$from, now()->endOfMonth()])
                ->orderBy('id')->lockForUpdate()->pluck('id');
            return $query->whereNotIn('q.transaction_id', $liveIds)->orderBy('q.id')->lockForUpdate()->get(['q.id'])->count();
        }
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fbr_pos_transactions as b')->whereColumn('b.company_id', 'q.company_id')->whereColumn('b.id', 'q.transaction_id'))->count();
    }
}
