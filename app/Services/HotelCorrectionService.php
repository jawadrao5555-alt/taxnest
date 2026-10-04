<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\HotelFolioEntry;
use App\Models\HotelRoom;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Full erroneous-stay correction; retains source bills and ledger entries. */
class HotelCorrectionService
{
    public function preview(HotelStay $stay, User $actor): array
    {
        abort_unless((int) $actor->company_id === (int) $stay->company_id && $actor->isPosAdmin(), 403);
        $entries = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->orderBy('id')->get();
        $ids = $entries->where('entry_type', HotelFolioEntry::TYPE_CHARGE)->pluck('pos_transaction_id')->filter()->unique()->sort()->values();
        $bills = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)->whereIn('id', $ids)->orderBy('id')->get();
        $blocked = null;
        if (!in_array($stay->status, ['checked_in', 'checked_out'], true)) {
            $blocked = __('hotel_correction.closed');
        } elseif ($entries->contains(fn ($e) => in_array($e->entry_type, ['adjustment', 'refund', 'deposit_refund'], true))) {
            $blocked = __('hotel_correction.review');
        } elseif ($bills->count() !== $ids->count()) {
            $blocked = __('hotel_correction.review');
        }
        foreach ($bills as $bill) {
            $bill->load('items');
            if (PosReturnService::returnableReason($bill) !== null || $bill->items->isEmpty()
                || $bill->items->contains(fn ($i) => (float) $i->returned_quantity > 0)
                || HotelFolioEntry::where('company_id', $stay->company_id)->where('pos_transaction_id', $bill->id)->where('stay_id', '!=', $stay->id)->exists()) {
                $blocked = __('hotel_correction.review');
            }
        }
        // Fiscal documents are preserved while category-specific credit notes are deferred.
        // Fail closed for ambiguous/legacy modes as well as pending/submitted bills.
        if ($bills->contains(fn ($bill) => !empty($bill->pra_invoice_number)
            || !in_array($bill->pra_status, [null, 'local'], true)
            || !($bill->invoice_mode === 'local' || $bill->pra_status === 'local'
                || ($bill->pra_status === null && PosLocalSeries::isSeriesSerial($bill->invoice_number))))) {
            $blocked = __('hotel_correction.fiscal_locked');
        }
        $totals = app(HotelFolioService::class)->totals($stay);
        // An issued bill must be covered by recorded money; never invent a refund.
        if ((float) $bills->sum('total_amount') - $totals['payments'] > 0.009) {
            $blocked = __('hotel_correction.review');
        }
        $fingerprint = hash('sha256', json_encode([
            $stay->id, $stay->status, $stay->room_id, $stay->branch_id, (string) $stay->check_in_date, (string) $stay->check_out_date, $stay->updated_at?->format('Y-m-d H:i:s.u'),
            $entries->map(fn ($e) => [$e->id, $e->entry_type, $e->amount, $e->payment_method, $e->pos_transaction_id, $e->updated_at?->format('Y-m-d H:i:s.u')])->all(),
            $bills->map(fn ($b) => [$b->id, $b->status, $b->total_amount, $b->tax_amount, $b->discount_amount, $b->payment_method, $b->invoice_mode, $b->pra_status, $b->pra_invoice_number, $b->updated_at?->format('Y-m-d H:i:s.u'), $b->items->map(fn ($i) => [$i->id, $i->quantity, $i->unit_price, $i->total_amount, $i->returned_quantity])->all()])->all(),
        ], JSON_THROW_ON_ERROR));

        return compact('entries', 'bills', 'totals', 'blocked', 'fingerprint');
    }

    public function correct(HotelStay $stay, User $actor, string $fingerprint, string $reason, string $method): array
    {
        abort_unless(in_array($method, ['cash', 'card'], true), 422);
        if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 255) {
            throw new HotelStayException(__('hotel_correction.reason'));
        }
        $results = DB::transaction(function () use ($stay, $actor, $fingerprint, $reason, $method) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->lockForUpdate()->get();
            $ids = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->whereNotNull('pos_transaction_id')->pluck('pos_transaction_id');
            PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $plan = $this->preview($stay, $actor);
            if (!hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new HotelStayException(__('hotel_correction.stale'));
            }
            if ($plan['blocked']) {
                throw new HotelStayException($plan['blocked']);
            }
            $results = [];
            foreach ($plan['bills'] as $bill) {
                $result = PosReturnService::createReturn((int) $stay->company_id, (int) $bill->id, null, $method, (int) $actor->id);
                if (isset($result['error']) || empty($result['return'])) {
                    throw new HotelStayException($result['error'] ?? __('hotel_correction.review'));
                }
                $results[$bill->id] = $result;
            }
            foreach ($plan['entries']->where('entry_type', HotelFolioEntry::TYPE_CHARGE) as $charge) {
                // Preserve source entry and attach the correcting credit note.
                HotelFolioEntry::create([
                    'company_id' => $stay->company_id, 'stay_id' => $stay->id,
                    'entry_type' => HotelFolioEntry::TYPE_ADJUSTMENT, 'category' => $charge->category,
                    'description' => __('hotel_correction.title').': '.$reason,
                    'quantity' => $charge->quantity, 'uom' => $charge->uom,
                    'unit_amount' => -(float) $charge->unit_amount, 'amount' => -(float) $charge->amount,
                    'gross_amount' => -(float) ($charge->gross_amount ?? $charge->amount),
                    'discount_amount' => -(float) $charge->discount_amount,
                    'pos_transaction_id' => $charge->pos_transaction_id ? $results[$charge->pos_transaction_id]['return']->id : null,
                    'reverses_entry_id' => $charge->id, 'created_by' => $actor->id,
                    'idempotency_key' => 'hotel-correct-charge-'.$charge->id,
                ]);
            }
            $folio = app(HotelFolioService::class);
            if ($plan['totals']['payments'] > 0.009) {
                $folio->refundPayment($stay, $plan['totals']['payments'], (int) $actor->id, $method, 'hotel-correct-payment-'.$stay->id);
            }
            if ($plan['totals']['deposit_held'] > 0.009) {
                $folio->refundDeposit($stay, $plan['totals']['deposit_held'], (int) $actor->id, $method, 'hotel-correct-deposit-'.$stay->id);
            }
            if ($stay->status === 'checked_in' && $stay->room_id) {
                HotelRoom::where('company_id', $stay->company_id)->where('id', $stay->room_id)->update(['housekeeping' => HotelRoom::HK_DIRTY]);
            }
            $stay->update(['status' => HotelStay::STATUS_CANCELLED, 'cancel_reason' => $reason]);
            AuditLogService::log('hotel_stay_corrected', 'hotel_stay', $stay->id, null, [
                'reason' => $reason, 'refund_method' => $method, 'payments_reversed' => $plan['totals']['payments'],
                'deposits_reversed' => $plan['totals']['deposit_held'],
                'return_ids' => array_map(fn ($r) => $r['return']->id, array_values($results)),
            ], (int) $stay->company_id, (int) $actor->id);

            return array_values($results);
        });
        // Preserve the established credit-note queue/submission contract.
        foreach ($results as $result) {
            PosReturnService::submitToPraPostCommit($result);
        }

        return $results;
    }
}
