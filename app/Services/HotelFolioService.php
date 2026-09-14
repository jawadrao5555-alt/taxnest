<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosProduct;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HotelFolioService
{
    public function __construct(private HotelFolioInvoiceService $invoices)
    {
    }

    /**
     * @return array{
     *   charges:float, payments:float, deposits:float, deposit_refunds:float,
     *   refunds:float, invoiced:float, outstanding:float, charge_outstanding:float,
     *   deposit_held:float, uninvoiced_charges:float
     * }
     */
    public function totals(HotelStay $stay): array
    {
        $rows = HotelFolioEntry::where('company_id', $stay->company_id)
            ->where('stay_id', $stay->id)
            ->get();

        $charges = 0.0;
        $payments = 0.0;
        $deposits = 0.0;
        $depositRefunds = 0.0;
        $refunds = 0.0;
        $invoiced = 0.0;
        foreach ($rows as $row) {
            $amt = (float) $row->amount;
            switch ($row->entry_type) {
                case HotelFolioEntry::TYPE_CHARGE:
                    $charges += $amt;
                    if ($row->pos_transaction_id) {
                        $invoiced += $amt;
                    }
                    break;
                case HotelFolioEntry::TYPE_ADJUSTMENT:
                    $charges += $amt;
                    if ($row->pos_transaction_id) {
                        $invoiced += $amt;
                    }
                    break;
                case HotelFolioEntry::TYPE_PAYMENT:
                    $payments += $amt;
                    break;
                case HotelFolioEntry::TYPE_DEPOSIT:
                    $deposits += $amt;
                    break;
                case HotelFolioEntry::TYPE_REFUND:
                    $refunds += $amt;
                    break;
                case HotelFolioEntry::TYPE_DEPOSIT_REFUND:
                    $depositRefunds += $amt;
                    break;
            }
        }

        $collectedTowardCharges = round($payments - $refunds, 2);
        $chargeOutstanding = round($charges - $collectedTowardCharges, 2);
        $uninvoiced = round($charges - $invoiced, 2);
        $fiscalOutstanding = max(0, $chargeOutstanding);
        $company = \App\Models\Company::find($stay->company_id);
        if ($company && $uninvoiced > 0) {
            $available = $this->invoices->availableTowardFiscal($stay);
            $fiscalForOpen = $this->invoices->fiscalTotalFor($company, $uninvoiced, 'cash');
            $fiscalOutstanding = max(0, round($fiscalForOpen - max(0, $available), 2));
        }

        return [
            'charges' => round($charges, 2),
            'payments' => round($payments, 2),
            'deposits' => round($deposits, 2),
            'deposit_refunds' => round($depositRefunds, 2),
            'refunds' => round($refunds, 2),
            'invoiced' => round($invoiced, 2),
            'outstanding' => $fiscalOutstanding,
            'charge_outstanding' => max(0, $chargeOutstanding),
            'deposit_held' => round($deposits - $depositRefunds, 2),
            'uninvoiced_charges' => $uninvoiced,
        ];
    }

    /**
     * Charge-side outstanding per stay (advances apply, deposits ignored).
     *
     * @param  list<int>  $stayIds
     * @return array<int,float>
     */
    public function chargeDuesForStayIds(int $companyId, array $stayIds): array
    {
        $stayIds = array_values(array_unique(array_map('intval', $stayIds)));
        if ($stayIds === []) {
            return [];
        }
        $out = array_fill_keys($stayIds, 0.0);
        $rows = HotelFolioEntry::where('company_id', $companyId)
            ->whereIn('stay_id', $stayIds)
            ->get();
        foreach ($rows as $row) {
            $amt = (float) $row->amount;
            $id = (int) $row->stay_id;
            if (!array_key_exists($id, $out)) {
                continue;
            }
            switch ($row->entry_type) {
                case HotelFolioEntry::TYPE_CHARGE:
                case HotelFolioEntry::TYPE_ADJUSTMENT:
                    $out[$id] += $amt;
                    break;
                case HotelFolioEntry::TYPE_PAYMENT:
                    $out[$id] -= $amt;
                    break;
                case HotelFolioEntry::TYPE_REFUND:
                    $out[$id] += $amt;
                    break;
            }
        }
        foreach ($out as $id => $value) {
            $out[$id] = max(0, round($value, 2));
        }

        return $out;
    }

    public function postCharge(HotelStay $stay, array $data, int $userId): HotelFolioEntry
    {
        return $this->post($stay, array_merge($data, [
            'entry_type' => HotelFolioEntry::TYPE_CHARGE,
            'is_deposit' => false,
        ]), $userId);
    }

    public function postPayment(HotelStay $stay, array $data, int $userId): HotelFolioEntry
    {
        return $this->post($stay, array_merge($data, [
            'entry_type' => HotelFolioEntry::TYPE_PAYMENT,
            'category' => $data['category'] ?? 'other',
            'is_deposit' => false,
        ]), $userId);
    }

    public function postDeposit(HotelStay $stay, array $data, int $userId): HotelFolioEntry
    {
        return $this->post($stay, array_merge($data, [
            'entry_type' => HotelFolioEntry::TYPE_DEPOSIT,
            'category' => 'other',
            'is_deposit' => true,
        ]), $userId);
    }

    public function refundPayment(HotelStay $stay, float $amount, int $userId, ?string $method = null, ?string $idempotencyKey = null): HotelFolioEntry
    {
        $totals = $this->totals($stay);
        $refundable = round($totals['payments'] - $totals['refunds'], 2);
        if ($amount <= 0 || $amount - $refundable > 0.009) {
            throw new HotelStayException(__('pos.hotel_refund_exceeds'));
        }

        return $this->post($stay, [
            'entry_type' => HotelFolioEntry::TYPE_REFUND,
            'category' => 'other',
            'description' => __('pos.hotel_payment_refund'),
            'quantity' => 1,
            'uom' => 'NOS',
            'unit_amount' => $amount,
            'amount' => $amount,
            'payment_method' => $method,
            'is_deposit' => false,
            'idempotency_key' => $idempotencyKey,
        ], $userId);
    }

    public function refundDeposit(HotelStay $stay, float $amount, int $userId, ?string $method = null, ?string $idempotencyKey = null): HotelFolioEntry
    {
        $totals = $this->totals($stay);
        if ($amount <= 0 || $amount - $totals['deposit_held'] > 0.009) {
            throw new HotelStayException(__('pos.hotel_deposit_refund_exceeds'));
        }

        return $this->post($stay, [
            'entry_type' => HotelFolioEntry::TYPE_DEPOSIT_REFUND,
            'category' => 'other',
            'description' => __('pos.hotel_deposit_refund'),
            'quantity' => 1,
            'uom' => 'NOS',
            'unit_amount' => $amount,
            'amount' => $amount,
            'payment_method' => $method,
            'is_deposit' => true,
            'idempotency_key' => $idempotencyKey,
        ], $userId);
    }

    public function reverseCharge(HotelStay $stay, int $entryId, int $userId, ?string $idempotencyKey = null): HotelFolioEntry
    {
        $original = HotelFolioEntry::where('company_id', $stay->company_id)
            ->where('stay_id', $stay->id)
            ->where('id', $entryId)
            ->first();
        if (!$original || $original->entry_type !== HotelFolioEntry::TYPE_CHARGE) {
            throw new HotelStayException(__('pos.hotel_charge_not_found'));
        }
        if ($original->pos_transaction_id) {
            throw new HotelStayException(__('pos.hotel_cannot_erase_invoiced'));
        }

        return $this->post($stay, [
            'entry_type' => HotelFolioEntry::TYPE_ADJUSTMENT,
            'category' => $original->category,
            'description' => __('pos.hotel_charge_reversed', ['desc' => $original->description]),
            'quantity' => $original->quantity,
            'uom' => $original->uom,
            'unit_amount' => -1 * (float) $original->unit_amount,
            'amount' => -1 * (float) $original->amount,
            'reverses_entry_id' => $original->id,
            'idempotency_key' => $idempotencyKey,
        ], $userId);
    }

    /**
     * Issue a fiscal invoice for uninvoiced charges that are already covered
     * by folio payments (advances apply here — they are not a second sale).
     * Unpaid charges stay on the folio. Deposits never count as revenue.
     *
     * @return array{transaction:?\App\Models\PosTransaction, totals:array, invoiced_amount:float}
     */
    public function settleCoveredCharges(HotelStay $stay, int $userId, string $paymentMethod, ?string $idempotencyKey = null): array
    {
        return DB::transaction(function () use ($stay, $userId, $paymentMethod, $idempotencyKey) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);

            return $this->invoices->issue($stay, $paymentMethod, $userId, $idempotencyKey);
        });
    }

    private function post(HotelStay $stay, array $data, int $userId): HotelFolioEntry
    {
        return DB::transaction(function () use ($stay, $data, $userId) {
            $stay = HotelStay::where('company_id', $stay->company_id)->lockForUpdate()->findOrFail($stay->id);
            $key = trim((string) ($data['idempotency_key'] ?? ''));
            if ($key !== '') {
                $key = substr($key, 0, 64);
                $existing = HotelFolioEntry::where('company_id', $stay->company_id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            } else {
                $key = null;
            }

            $qty = (float) ($data['quantity'] ?? 1);
            $uom = PosUnitCatalog::normalize($data['uom'] ?? 'NOS') ?: 'NOS';
            if (!PosUnitCatalog::isValid($uom)) {
                $uom = 'NOS';
            }
            if (!PosUnitCatalog::quantityAllowed($uom, $qty)) {
                throw new HotelStayException(__('pos.hotel_qty_whole_only', ['uom' => $uom]));
            }
            $unit = round((float) ($data['unit_amount'] ?? $data['amount'] ?? 0), 2);
            $amount = array_key_exists('amount', $data)
                ? round((float) $data['amount'], 2)
                : round($qty * $unit, 2);
            if ($amount == 0.0 && ($data['entry_type'] ?? '') === HotelFolioEntry::TYPE_CHARGE) {
                throw new HotelStayException(__('pos.hotel_amount_required'));
            }

            $productId = isset($data['product_id']) ? (int) $data['product_id'] : null;
            if ($productId && Schema::hasTable('pos_products')) {
                $product = PosProduct::where('company_id', $stay->company_id)->where('id', $productId)->first();
                $productId = $product?->id;
                if ($product && empty($data['description'])) {
                    $data['description'] = $product->name;
                }
                if ($product && empty($data['uom']) && $product->uom) {
                    $uom = $product->uom;
                }
            }

            try {
                $entry = HotelFolioEntry::create([
                    'company_id' => $stay->company_id,
                    'stay_id' => $stay->id,
                    'entry_type' => $data['entry_type'],
                    'category' => $data['category'] ?? 'extra',
                    'description' => trim((string) ($data['description'] ?? 'Folio entry')),
                    'quantity' => $qty,
                    'uom' => $uom,
                    'unit_amount' => $unit,
                    'amount' => $amount,
                    'product_id' => $productId,
                    'payment_method' => $data['payment_method'] ?? null,
                    'is_deposit' => (bool) ($data['is_deposit'] ?? false),
                    'reverses_entry_id' => $data['reverses_entry_id'] ?? null,
                    'idempotency_key' => $key,
                    'created_by' => $userId ?: null,
                ]);
            } catch (QueryException $e) {
                if ($key) {
                    $existing = HotelFolioEntry::where('company_id', $stay->company_id)
                        ->where('idempotency_key', $key)
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                throw $e;
            }

            AuditLogService::log('hotel_folio_entry', 'hotel_folio_entry', $entry->id, null, [
                'stay_id' => $stay->id,
                'type' => $entry->entry_type,
                'amount' => $entry->amount,
            ], (int) $stay->company_id, $userId);

            return $entry;
        });
    }
}
