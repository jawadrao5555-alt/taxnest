<?php

namespace App\Services;

use App\Models\HotelCreditNote;
use App\Models\HotelFolioEntry;
use App\Support\PosPaymentBuckets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Money settlement only: never another sale, tax adjustment or fiscal document. */
class HotelCreditNoteRefundReporting
{
    public static function rows(int $companyId, string $date, ?int $branchId = null, ?int $onlyCreatedBy = null): Collection
    {
        if (!Schema::hasColumn('hotel_folio_entries', 'refund_business_date') || !Schema::hasTable('hotel_credit_notes')) {
            return collect();
        }
        return HotelFolioEntry::where('company_id', $companyId)->where('entry_type', HotelFolioEntry::TYPE_REFUND)
            ->whereDate('refund_business_date', $date)
            ->whereIn('pos_transaction_id', HotelCreditNote::where('company_id', $companyId)->select('credit_transaction_id'))
            ->when($branchId, fn ($q) => $q->where(fn ($b) => $b->where('refund_branch_id', $branchId)->orWhereNull('refund_branch_id')))
            ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))->get();
    }

    public static function buckets(int $companyId, string $date, ?int $branchId = null, ?int $onlyCreatedBy = null): array
    {
        $sums = PosPaymentBuckets::split(self::rows($companyId, $date, $branchId, $onlyCreatedBy), 'amount');
        foreach (HotelMoneySettlementReporting::outflows($companyId, $date, $branchId, $onlyCreatedBy) as $bucket => $amount) {
            $sums[$bucket] = ($sums[$bucket] ?? 0) + $amount;
        }
        return $sums;
    }

    public static function cashByDrawer(int $companyId, string $date, ?int $branchId = null, ?int $onlyCreatedBy = null): array
    {
        $cash = self::rows($companyId, $date, $branchId, $onlyCreatedBy)->where('payment_method', 'cash')
            ->groupBy(fn ($r) => (int) ($r->refund_terminal_id ?? 0))
            ->map(fn ($r) => round((float) $r->sum('amount'), 2))->all();
        foreach (HotelMoneySettlementReporting::cashByDrawer($companyId, $date, $branchId, $onlyCreatedBy) as $drawer => $amount) {
            $cash[$drawer] = ($cash[$drawer] ?? 0) + $amount;
        }
        return $cash;
    }
}
