<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\Company;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/** No draft transaction, serial, payment, print job or PRA request on preview. */
class HotelBillPreviewService
{
    public function preview(HotelStay $stay, User $user, array $data): array
    {
        $company = Company::findOrFail($stay->company_id);
        $rows = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->orderBy('id')->get();
        $open = $rows->whereIn('entry_type', ['charge', 'adjustment'])->whereNull('pos_transaction_id');
        $method = $data['payment_method'];
        if ($data['flow'] === 'checkout') {
            if ($stay->status !== HotelStay::STATUS_CHECKED_IN) throw new HotelStayException(__('pos.hotel_transition_blocked'));
            $quote = app(HotelDeskService::class)->summary($stay, $method);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $quote['balance'] + 0.009) throw new HotelStayException(__('pos.hotel_checkout_amount_changed'));
            $remaining = max(0, round($quote['balance'] - $amount, 2));
            if ($remaining > 0.009 && (!HotelCheckoutPolicy::allowsOutstandingCheckout($company) || empty($data['leave_balance']))) {
                throw new HotelStayException(__('pos.hotel_checkout_due_blocked', ['amount' => number_format($remaining, 2)]));
            }
            $picked = $open->all();
            $willIssue = $remaining <= 0.009 && $open->isNotEmpty();
        } else {
            if (!in_array($stay->status, [HotelStay::STATUS_CHECKED_IN, HotelStay::STATUS_CHECKED_OUT], true)) throw new HotelStayException(__('pos.hotel_transition_blocked'));
            [$picked] = app(HotelFolioInvoiceService::class)->coveredCharges($stay, $company, $open, $method);
            $willIssue = (bool) $picked;
            if (!$picked) throw new HotelStayException(__('pos.hotel_tax_coverage_needed'));
        }
        $lines = collect($picked);
        $net = (float) $lines->sum('amount');
        $discount = (float) $lines->sum('discount_amount');
        // Invoice math uses net folio amounts; discounts remain visible separately.
        $quote = HotelPricingService::quote($company, $net, 0, $method);
        $reporting = $company->pos_integration_mode !== 'standalone' && $user->praReportingEnabled($company);
        $scope = $user->posBillingScopeExplicit();
        if (($reporting && $scope === 'local') || (!$reporting && $scope === 'pra')) throw new HotelStayException(__('pos.custom_access_denied'));
        $context = [
            'company' => (int) $stay->company_id, 'stay' => (int) $stay->id, 'user' => (int) $user->id,
            'data' => $data, 'status' => $stay->status, 'room' => $stay->room_id,
            'rows' => $rows->map(fn ($r) => $r->only(['id', 'entry_type', 'amount', 'discount_amount', 'description', 'quantity', 'pos_transaction_id']))->all(),
            'quote' => $quote, 'reporting' => $reporting, 'scope' => $scope,
            'pricing' => $company->posTaxPricingMode(), 'cash_rate' => \App\Models\PosTaxRule::getRateForMethod('cash', $company),
            'available' => app(HotelFolioInvoiceService::class)->availableTowardFiscal($stay),
        ];
        $fingerprint = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
        return array_merge($quote, [
            'discount' => $discount, 'gross' => round($net + $discount, 2),
            'stay_number' => $stay->stay_number, 'guest_name' => $stay->guest_name, 'room_number' => $stay->room?->room_number,
            'lines' => $lines->map(fn ($r) => ['description' => $r->description, 'amount' => (float) $r->amount])->values()->all(),
            'paid' => round((float) $rows->where('entry_type', 'payment')->sum('amount') - (float) $rows->where('entry_type', 'refund')->sum('amount'), 2),
            'collect_now' => $data['flow'] === 'checkout' ? (float) $data['amount'] : 0,
            'remaining' => $remaining ?? 0, 'will_issue' => $willIssue,
            'reporting' => $reporting, 'fingerprint' => $fingerprint,
            'preview_token' => Crypt::encryptString(json_encode(['fingerprint' => $fingerprint, 'expires' => now()->addMinutes(10)->timestamp])),
        ]);
    }

    public function verify(HotelStay $stay, User $user, array $data, string $token): void
    {
        try { $saved = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new HotelStayException(__('hotel_preview.changed')); }
        $now = $this->preview($stay, $user, $data);
        if (($saved['expires'] ?? 0) < now()->timestamp || !hash_equals($now['fingerprint'], (string) ($saved['fingerprint'] ?? ''))) {
            throw new HotelStayException(__('hotel_preview.changed'));
        }
    }

    public function result(HotelStay $stay, ?PosTransaction $bill): array
    {
        $accepted = $bill && $bill->pra_status === 'submitted' && !empty($bill->pra_invoice_number);
        return [
            'success' => true, 'stay_status' => $stay->fresh()->status,
            'bill_id' => $bill?->id, 'invoice_number' => $bill?->invoice_number,
            'status' => !$bill ? 'no_bill' : ($accepted ? 'submitted' : ($bill->pra_status ?: 'local')),
            'fiscal_number' => $accepted ? $bill->pra_invoice_number : null,
            'qr' => $accepted ? \App\Support\QrImage::dataUri($bill->pra_invoice_number) : null,
            'receipt_url' => $bill ? route('pos.receipt', $bill->id) : null,
            'stay_url' => route('pos.hotel.stays.show', $stay->id),
        ];
    }
}
