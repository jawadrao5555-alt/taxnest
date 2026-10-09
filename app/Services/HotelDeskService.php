<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\Company;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use Illuminate\Support\Facades\DB;

class HotelDeskService
{
    public function summary(HotelStay $stay, string $method): array
    {
        $rows = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->get();
        $open = $rows->whereIn('entry_type', ['charge', 'adjustment'])->whereNull('pos_transaction_id');
        $net = (float) $open->sum('amount');
        $discount = (float) $open->sum('discount_amount');
        if ($stay->status === HotelStay::STATUS_RESERVED && $open->isEmpty()) {
            $gross = round((float) $stay->rate_amount * (int) $stay->nights, 2);
            $discount = HotelPricingService::discount($gross, $stay->discount_type ?? 'amount', (float) ($stay->discount_value ?? 0));
            $net = round($gross - $discount, 2);
        }
        $quote = HotelPricingService::quote(Company::findOrFail($stay->company_id), $net + $discount, $discount, $method);
        $available = app(HotelFolioInvoiceService::class)->availableTowardFiscal($stay);
        $quote['balance'] = max(0, round($quote['total'] - $available, 2));
        $quote['credit'] = max(0, round($available - $quote['total'], 2));
        $quote['available'] = $available;
        $quote['paid'] = round((float) $rows->where('entry_type', 'payment')->sum('amount') - (float) $rows->where('entry_type', 'refund')->sum('amount'), 2);
        $quote['deposit'] = round((float) $rows->where('entry_type', 'deposit')->sum('amount') - (float) $rows->where('entry_type', 'deposit_refund')->sum('amount'), 2);
        $priorIds = $rows->pluck('pos_transaction_id')->filter()->unique();
        $quote['total_stay'] = round($quote['total'] + (float) \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $stay->company_id)->whereIn('id', $priorIds)
            ->selectRaw("SUM(CASE WHEN transaction_type = 'return' THEN -total_amount ELSE total_amount END) AS net_total")->value('net_total'), 2);
        $quote['room_gross'] = (float) $open->where('category', 'room')->sum(fn ($r) => $r->gross_amount ?? $r->amount);
        if ($stay->status === 'reserved' && $open->isEmpty()) $quote['room_gross'] = $net + $discount;
        $quote['extras'] = (float) $open->where('category', '!=', 'room')->sum(fn ($r) => $r->gross_amount ?? $r->amount);
        return $quote;
    }

    public function checkout(HotelStay $stay, int $userId, array $data): HotelStay
    {
        return DB::transaction(function () use ($stay, $userId, $data) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if ($stay->status === HotelStay::STATUS_CHECKED_OUT) {
                return $stay; // Repeat submission must not take another payment.
            }
            if ($stay->status !== HotelStay::STATUS_CHECKED_IN) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $method = $data['payment_method'];
            $quote = $this->summary($stay, $method);
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount < 0 || $amount - $quote['balance'] > 0.009) {
                throw new HotelStayException(__('pos.hotel_checkout_amount_changed'));
            }
            $remaining = max(0, round($quote['balance'] - $amount, 2));
            if ($remaining > 0.009 && (!HotelCheckoutPolicy::allowsOutstandingCheckout(Company::find($stay->company_id)) || empty($data['leave_balance']))) {
                throw new HotelStayException(__('pos.hotel_checkout_due_blocked', ['amount' => number_format($remaining, 2)]));
            }
            $folio = app(HotelFolioService::class);
            if ($amount > 0) {
                $folio->postPayment($stay, ['amount' => $amount, 'payment_method' => $method,
                    'description' => __('pos.hotel_checkout_payment'),
                    'idempotency_key' => hash('sha256', 'checkout-pay|'.$stay->id.'|'.$data['idempotency_key']),
                ], $userId);
            }
            // Issue fully covered charges. Unpaid charges remain on the folio
            // when the existing owner policy permits outstanding checkout.
            if ($stay->hotel_money_from_folio || $remaining <= 0.009) {
                $folio->settleCoveredCharges($stay, $userId, $method, hash('sha256', 'checkout-bill|'.$stay->id.'|'.$data['idempotency_key']));
            }
            return app(HotelStayService::class)->checkOut($stay, $userId, $method);
        });
    }

    public function changeQuote(HotelStay $stay, int $userId, array $data): array
    {
        if (!$stay->isOpen()) {
            throw new HotelStayException(__('pos.hotel_transition_blocked'));
        }
        $kind = $data['kind'];
        $rate = round((float) ($data['rate_amount'] ?? $stay->rate_amount), 2);
        $nights = $kind === 'extend'
            ? HotelStayService::nights($stay->check_out_date->toDateString(), $data['check_out_date'])
            : ($stay->status === 'reserved' ? (int) $stay->nights : max(0, (int) now()->startOfDay()->diffInDays($stay->check_out_date, false)));
        $room = $kind === 'move'
            ? \App\Models\HotelRoom::where('company_id', $stay->company_id)->where('branch_id', $stay->branch_id)->find($data['room_id'])
            : $stay->room;
        if (!$room || !$room->is_active || $room->isOutOfService()) {
            throw new HotelStayException(__('pos.hotel_room_not_found'));
        }
        $discountValue = ($stay->discount_type ?? 'amount') === 'percentage' ? (float) $stay->discount_value : 0;
        // Continuing an already agreed price is not a new cashier discount.
        // Still validate access and amounts; enforce the cap on every rate change.
        $rateChanged = abs($rate - (float) $stay->rate_amount) > 0.009;
        HotelPricingService::validateRate((int) $stay->company_id, $userId, (float) $room->rate_amount, $rate, max(1, $nights), $stay->discount_type ?? 'amount', $discountValue, $rateChanged);
        if ($stay->status === 'reserved') {
            $newNights = (int) $stay->nights + ($kind === 'extend' ? $nights : 0);
            HotelPricingService::validateRate((int) $stay->company_id, $userId, (float) $room->rate_amount, $rate, $newNights, $stay->discount_type ?? 'amount', (float) $stay->discount_value, $rateChanged);
            $gross = round($newNights * $rate, 2);
            $discount = HotelPricingService::discount($gross, $stay->discount_type ?? 'amount', (float) $stay->discount_value);
            $newNet = $gross - $discount;
            $oldGross = (float) $stay->rate_amount * (int) $stay->nights;
            $oldNet = $oldGross - HotelPricingService::discount($oldGross, $stay->discount_type ?? 'amount', (float) $stay->discount_value);
            $delta = round($newNet - $oldNet, 2);
        } else {
            $gross = round($nights * $rate, 2);
            $discount = HotelPricingService::discount($gross, $stay->discount_type ?? 'amount', $discountValue);
            $delta = round($gross - $discount, 2);
            if ($kind === 'move') {
                $delta = 0.0;
                if (abs($rate - (float) $stay->rate_amount) > 0.009) {
                    foreach ($this->futureRoomLines($stay) as $part) {
                        if ($part['line']->pos_transaction_id) throw new HotelStayException(__('pos.hotel_pricing_invoiced'));
                        $gross = round($part['nights'] * $rate, 2);
                        $discount = ($stay->discount_type ?? 'amount') === 'percentage'
                            ? HotelPricingService::discount($gross, 'percentage', (float) $stay->discount_value) : $part['discount'];
                        if ($gross < $discount) throw new HotelStayException(__('pos.hotel_price_invalid'));
                        $delta += $gross - $discount - ($part['gross'] - $part['discount']);
                    }
                }
            }
            $newNet = max(0, round(app(HotelFolioService::class)->totals($stay)['uninvoiced_charges'] + $delta, 2));
        }
        $quote = HotelPricingService::quote(Company::findOrFail($stay->company_id), $newNet, 0, $data['payment_method'] ?? 'cash');
        return $quote + ['rate' => $rate, 'nights' => $nights, 'change' => round($delta, 2)];
    }

    public function changeStay(HotelStay $stay, int $userId, array $data): HotelStay
    {
        return DB::transaction(function () use ($stay, $userId, $data) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            $quote = $this->changeQuote($stay, $userId, $data);
            $oldRate = (float) $stay->rate_amount;
            $rate = $quote['rate'];
            if ($data['kind'] === 'move' && (int) $data['room_id'] === (int) $stay->room_id && abs($oldRate - $rate) <= 0.009) {
                return $stay;
            }
            if ($data['kind'] === 'move' && $stay->status === 'checked_in' && abs($oldRate - $rate) > 0.009) {
                // Only unbilled future nights may be repriced. Issued invoice
                // snapshots remain immutable; ordinary room moves keep the rate.
                foreach ($this->futureRoomLines($stay) as $part) {
                    $line = $part['line'];
                    if ($line->pos_transaction_id) throw new HotelStayException(__('pos.hotel_pricing_invoiced'));
                    $futureGross = round($part['nights'] * $rate, 2);
                    $futureDiscount = ($stay->discount_type ?? 'amount') === 'percentage'
                        ? HotelPricingService::discount($futureGross, 'percentage', (float) $stay->discount_value)
                        : $part['discount'];
                    if ($futureGross < $futureDiscount) throw new HotelStayException(__('pos.hotel_price_invalid'));
                    if ($part['nights'] < (int) $line->quantity) {
                        $future = $line->replicate(['idempotency_key']);
                        $line->update([
                            'quantity' => (int) $line->quantity - $part['nights'],
                            'gross_amount' => round((float) $line->gross_amount - $part['gross'], 2),
                            'discount_amount' => round((float) $line->discount_amount - $part['discount'], 2),
                            'amount' => round((float) $line->amount - ($part['gross'] - $part['discount']), 2),
                            'room_to_date' => $part['from'],
                        ]);
                        $future->room_from_date = $part['from'];
                        $future->quantity = $part['nights'];
                        $future->created_by = $userId;
                        $future->description = __('pos.hotel_rate_change_line');
                    } else {
                        $future = $line;
                    }
                    $future->forceFill(['gross_amount' => $futureGross, 'unit_amount' => $rate, 'discount_amount' => $futureDiscount, 'amount' => round($futureGross - $futureDiscount, 2)])->save();
                }
            }
            $stay->update(['rate_amount' => $rate]);
            $service = app(HotelStayService::class);
            $result = $data['kind'] === 'extend'
                ? $service->extend($stay, $data['check_out_date'], $userId)
                : $service->moveRoom($stay, (int) $data['room_id'], $userId);
            AuditLogService::log('hotel_rate_changed', 'hotel_stay', $stay->id, ['rate' => $oldRate], ['rate' => $rate, 'kind' => $data['kind']], (int) $stay->company_id, $userId);
            return $result;
        });
    }

    private function futureRoomLines(HotelStay $stay): array
    {
        $from = now()->startOfDay()->max($stay->check_in_date);
        $expected = max(0, (int) $from->diffInDays($stay->check_out_date, false));
        $parts = [];
        $covered = 0;
        $reversed = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id');
        $lines = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)
            ->where('room_pricing', true)->where('entry_type', 'charge')->whereNotIn('id', $reversed)->get();
        foreach ($lines as $line) {
            if (!$line->room_from_date || !$line->room_to_date || $line->room_to_date->lte($from)) continue;
            $start = $line->room_from_date->copy()->max($from);
            $nights = max(0, (int) $start->diffInDays($line->room_to_date, false));
            if (!$nights) continue;
            $covered += $nights;
            $ratio = $nights / max(1, (float) $line->quantity);
            $parts[] = ['line' => $line, 'from' => $start, 'nights' => $nights, 'gross' => round((float) $line->gross_amount * $ratio, 2), 'discount' => round((float) $line->discount_amount * $ratio, 2)];
        }
        if ($covered !== $expected) throw new HotelStayException(__('pos.hotel_pricing_invoiced'));
        return $parts;
    }

    public function changeDiscount(HotelStay $stay, int $userId, string $type, float $value): HotelStay
    {
        return DB::transaction(function () use ($stay, $userId, $type, $value) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            if (!$stay->isOpen()) {
                throw new HotelStayException(__('pos.hotel_transition_blocked'));
            }
            $lines = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->where('room_pricing', true)->where('entry_type', 'charge')->lockForUpdate()->get();
            if (HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->whereIn('reverses_entry_id', $lines->pluck('id'))->exists()) {
                throw new HotelStayException(__('pos.hotel_pricing_invoiced'));
            }
            if ($lines->contains(fn ($line) => $line->pos_transaction_id !== null)) {
                throw new HotelStayException(__('pos.hotel_pricing_invoiced'));
            }
            if ($lines->isEmpty() && $stay->status !== HotelStay::STATUS_RESERVED) {
                throw new HotelStayException(__('pos.hotel_pricing_invoiced'));
            }
            $gross = $lines->isEmpty() ? (float) $stay->rate_amount * $stay->nights : (float) $lines->sum('gross_amount');
            HotelPricingService::validateRate((int) $stay->company_id, $userId, (float) ($stay->standard_rate_amount ?? $stay->rate_amount), $gross / max(1, $stay->nights), (int) $stay->nights, $type, $value);
            $discount = HotelPricingService::discount($gross, $type, $value);
            $remaining = $discount;
            foreach ($lines as $index => $line) {
                $part = $index === $lines->count() - 1 ? $remaining : round($discount * (float) $line->gross_amount / max(0.01, $gross), 2);
                $remaining = round($remaining - $part, 2);
                $line->update(['discount_amount' => $part, 'amount' => round((float) $line->gross_amount - $part, 2)]);
            }
            $before = $stay->only(['discount_type', 'discount_value']);
            $stay->update(['discount_type' => $type, 'discount_value' => $value]);
            AuditLogService::log('hotel_discount_changed', 'hotel_stay', $stay->id, $before, $stay->only(['discount_type', 'discount_value']), (int) $stay->company_id, $userId);
            return $stay;
        });
    }
}

