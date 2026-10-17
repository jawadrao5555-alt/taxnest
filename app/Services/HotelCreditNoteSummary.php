<?php

namespace App\Services;

use App\Models\HotelCreditNote;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\Schema;

/** Read-only document evidence and money settlement; never fiscal transport. */
class HotelCreditNoteSummary
{
    public function forCredit(PosTransaction $credit): ?array
    {
        if ($credit->transaction_type !== 'return' || !Schema::hasTable('hotel_credit_notes')) return null;
        $note = HotelCreditNote::where('company_id', $credit->company_id)
            ->where('credit_transaction_id', $credit->id)->first();
        if (!$note) return null;
        $viewer = auth('pos')->user();
        if ($viewer && ((int) $viewer->company_id !== (int) $credit->company_id
            || !$credit->allowedForBillingScopeOf($viewer) || !$credit->allowedForCashierIsolationOf($viewer))) return null;
        $stay = HotelStay::where('company_id', $credit->company_id)->find($note->stay_id);
        if (!$stay) return null;
        $accepted = $credit->pra_status === 'submitted' && trim((string) $credit->pra_invoice_number) !== '';
        $status = $accepted ? 'accepted' : match ($credit->pra_status) {
            'pending', 'offline' => 'pending', 'failed' => 'failed', default => 'review',
        };
        $refunded = round((float) HotelFolioEntry::where('company_id', $credit->company_id)
            ->where('stay_id', $stay->id)->where('pos_transaction_id', $credit->id)
            ->where('entry_type', HotelFolioEntry::TYPE_REFUND)->sum('amount'), 2);
        $remaining = max(0, round((float) $credit->total_amount - $refunded, 2));
        $guestFundsVisible = true;
        if ($viewer) {
            $ids = HotelFolioEntry::where('company_id', $credit->company_id)->where('stay_id', $stay->id)
                ->pluck('pos_transaction_id')->filter()->unique();
            $bills = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $credit->company_id)
                ->whereIn('id', $ids)->get();
            $guestFundsVisible = $bills->count() === $ids->count()
                && $bills->every(fn ($bill) => $bill->allowedForBillingScopeOf($viewer) && $bill->allowedForCashierIsolationOf($viewer));
        }
        return ['status' => $status, 'fiscal_number' => $accepted ? $credit->pra_invoice_number : null,
            'reason' => $note->reason, 'created_by' => $note->created_by,
            'refunded' => $refunded, 'remaining' => $remaining,
            // An unpaid credit does not entitle the guest to money never paid.
            'refundable' => !$guestFundsVisible ? null : ($accepted ? min($remaining, max(0, app(HotelFolioInvoiceService::class)->availableTowardFiscal($stay))) : 0.0)];
    }
}
