<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use App\Models\User;

/** Read-only review plan. This does not issue, submit, refund or cancel anything. */
class HotelCreditNotePolicy
{
    public function review(HotelStay $stay, User $actor, int $billId, ?array $quantities = null): array
    {
        abort_unless((int) $actor->company_id === (int) $stay->company_id && $actor->isPosAdmin(), 403);
        $stay = HotelStay::where('company_id', $actor->company_id)->findOrFail($stay->id);
        abort_unless(in_array($stay->status, ['checked_in', 'checked_out'], true), 422);
        $charges = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)
            ->where('entry_type', HotelFolioEntry::TYPE_CHARGE)->where('pos_transaction_id', $billId)->get();
        abort_if($charges->isEmpty(), 404);
        abort_if(HotelFolioEntry::where('company_id', $stay->company_id)->where('pos_transaction_id', $billId)
            ->where('stay_id', '!=', $stay->id)->exists(), 422);
        $bill = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
            ->with('items')->findOrFail($billId);
        if (PosReturnService::returnableReason($bill) !== null) {
            throw new HotelStayException('This bill requires review before a credit note.');
        }
        if ($bill->pra_status !== 'submitted' || empty($bill->pra_invoice_number)
            || $bill->invoice_mode === 'local' || empty($bill->invoice_number)) {
            throw new HotelStayException('Review requires a confirmed original fiscal invoice.');
        }
        $pending = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
            ->where('parent_transaction_id', $bill->id)->where('transaction_type', 'return')
            ->where(fn ($q) => $q->whereNull('pra_status')->orWhere('pra_status', '!=', 'submitted')->orWhereNull('pra_invoice_number')->orWhere('pra_invoice_number', ''))->exists();
        if ($pending) {
            throw new HotelStayException('Resolve the existing credit note before another adjustment.');
        }
        // Keys are original item IDs, values are quantities, not cash amounts.
        $selected = [];
        if ($quantities !== null) {
            foreach ($quantities as $id => $value) {
                if (!ctype_digit((string) $id) || !is_numeric($value) || !is_finite((float) $value)
                    || (float) $value <= 0 || abs((float) $value - round((float) $value, 3)) > 0.0000001
                    || !$bill->items->contains(fn ($item) => (int) $item->id === (int) $id)) {
                    throw new HotelStayException('Select valid original lines and quantities.');
                }
                $selected[(int) $id] = (float) $value;
            }
        }
        $lines = [];
        $full = true;
        foreach ($bill->items as $item) {
            $remaining = round((float) $item->quantity - (float) $item->returned_quantity, 3);
            $qty = $quantities === null ? $remaining : ($selected[(int) $item->id] ?? 0);
            if ($qty > $remaining + 0.0000001 || $remaining < 0) {
                throw new HotelStayException('Adjustment exceeds the remaining original quantity.');
            }
            if ($qty < $remaining) {
                $full = false;
            }
            if ($qty <= 0) {
                continue;
            }
            $ratio = $qty / max((float) $item->quantity, 0.001);
            $lines[] = [
                'item_id' => (int) $item->id, 'name' => $item->item_name, 'quantity' => $qty,
                'remaining_quantity' => $remaining, 'original_tax_rate' => (float) $item->tax_rate,
                'original_subtotal_share' => round((float) $item->subtotal * $ratio, 2),
                'original_tax_share' => round((float) $item->tax_amount * $ratio, 2),
                'original_item_discount_share' => round((float) $item->item_discount_amount * $ratio, 2),
            ];
        }
        if ($lines === []) {
            throw new HotelStayException('No remaining quantity selected.');
        }
        $mappingComplete = $bill->items->every(fn ($item) => $item->hotel_folio_entry_id
            && $charges->contains(fn ($entry) => (int) $entry->id === (int) $item->hotel_folio_entry_id))
            && $bill->items->pluck('hotel_folio_entry_id')->unique()->count() === $bill->items->count()
            && $charges->count() === $bill->items->count();
        return [
            'fingerprint' => hash('sha256', json_encode([$bill->getAttributes(), $bill->items->toArray(), $charges->toArray(), $lines], JSON_THROW_ON_ERROR)),
            'folio_mapping_complete' => $mappingComplete,
            'kind' => $full ? 'full_remaining' : 'partial', 'bill_id' => (int) $bill->id,
            'original_usin' => $bill->invoice_number, 'original_fiscal_number' => $bill->pra_invoice_number,
            'lines' => $lines, 'tax_inclusive' => (bool) $bill->tax_inclusive,
            'estimated_total' => round(array_sum(array_column($lines, 'original_subtotal_share')) + ($bill->tax_inclusive ? 0 : array_sum(array_column($lines, 'original_tax_share'))), 0),
            'original_bill_discount' => (float) $bill->discount_amount,
            // Shares are inputs for reconciliation, not an authorized credit total or refund.
            'issuance_enabled' => (bool) config('hotel_credit_notes.enabled', false) && $mappingComplete && (float) $bill->discount_amount === 0.0, 'refund_amount' => null, 'stay_action' => 'unchanged',
        ];
    }
}
