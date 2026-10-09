<?php

namespace App\Services;

use App\Models\HotelCreditNote;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\Schema;

/** Read-only stay balances. Never invent per-invoice payment allocations. */
class HotelBillingDirectory
{
    public function describe($transactions): array
    {
        $companyId = (int) app('currentCompanyId');
        $actor = auth('pos')->user();
        $links = HotelBillDirectory::stayLinks($transactions);
        $stays = HotelStay::where('company_id', $companyId)->whereIn('id', collect($links)->pluck('id'))->with('room')->get()->keyBy('id');
        $money = [];
        foreach ($stays as $stay) {
            $ids = HotelFolioEntry::where('company_id', $companyId)->where('stay_id', $stay->id)
                ->pluck('pos_transaction_id')->filter()->unique();
            $all = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $companyId)->whereIn('id', $ids)->get();
            $visible = $all->count() === $ids->count() && $all->every(fn ($b) => $b->allowedForBillingScopeOf($actor) && $b->allowedForCashierIsolationOf($actor));
            // A partial-scope viewer must not learn another cashier/stream's balance.
            $money[$stay->id] = $visible ? app(HotelDeskService::class)->summary($stay, 'cash') : null;
        }
        $notes = Schema::hasTable('hotel_credit_notes')
            ? HotelCreditNote::where('company_id', $companyId)->whereIn('stay_id', $stays->keys())->get()->keyBy('original_transaction_id')
            : collect();
        $rows = [];
        foreach ($transactions as $bill) {
            $link = $links[$bill->id] ?? null;
            $stay = $link ? $stays->get($link['id']) : null;
            if (!$stay) continue;
            $note = $notes->get($bill->id);
            $creditBill = $note ? PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $companyId)->find($note->credit_transaction_id) : null;
            $creditVisible = $creditBill && $creditBill->allowedForBillingScopeOf($actor) && $creditBill->allowedForCashierIsolationOf($actor);
            $rows[$bill->id] = ['stay' => $stay, 'money' => $money[$stay->id],
                'credit_id' => $creditVisible ? $creditBill->id : null,
                'can_credit' => !$note && HotelAccessService::canManageRooms($actor)
                    && in_array($stay->status, ['checked_in', 'checked_out'], true) && $bill->transaction_type !== 'return'
                    && $bill->pra_status === 'submitted' && $bill->pra_invoice_number && $bill->invoice_mode !== 'local'
                    && PosReturnService::returnableReason($bill, true) === null];
        }
        return ['rows' => $rows, 'paid' => collect($money)->filter()->sum('paid'),
            'due' => collect($money)->filter()->sum('balance'),
            'restricted' => collect($money)->contains(fn ($m) => $m === null)];
    }
}
