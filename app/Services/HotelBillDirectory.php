<?php

namespace App\Services;

use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use Illuminate\Support\Facades\Schema;

/** Batch, read-only links between visible invoices and their original stays. */
class HotelBillDirectory
{
    public static function stayLinks($transactions): array
    {
        $actor = auth('pos')->user();
        if (!HotelAccessService::canFrontDesk($actor) || !Schema::hasTable('hotel_folio_entries')) return [];
        $companyId = (int) app('currentCompanyId');
        $branchId = app(BranchContextService::class)->getActiveBranchId();
        $visible = $transactions->filter(fn ($bill) => (int) $bill->company_id === $companyId
            && $bill->allowedForBillingScopeOf($actor) && $bill->allowedForCashierIsolationOf($actor));
        $rows = HotelFolioEntry::where('company_id', $companyId)->whereIn('pos_transaction_id', $visible->pluck('id'))
            ->whereIn('entry_type', [HotelFolioEntry::TYPE_CHARGE, 'adjustment'])->get();
        $stays = HotelStay::where('company_id', $companyId)->whereIn('id', $rows->pluck('stay_id'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get()->keyBy('id');
        $links = [];
        foreach ($rows->groupBy('pos_transaction_id') as $id => $entries) {
            $ids = $entries->pluck('stay_id')->unique();
            if ($ids->count() === 1 && ($stay = $stays->get($ids->first()))) {
                $links[$id] = ['id' => $stay->id, 'number' => $stay->stay_number, 'status' => $stay->status];
            }
        }
        return $links;
    }
}
