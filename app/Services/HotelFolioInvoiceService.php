<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\Company;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTaxRule;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Support\Facades\Schema;

/**
 * Turns covered folio charges into a canonical NestPOS bill.
 *
 * Reuses PosFinalSeries / PosLocalSeries, PosTaxRule, PosTaxMath and
 * PraIntegrationService. Does not mint restaurant table/token prefixes.
 * Advances already on the folio are NOT posted as a second sale.
 */
class HotelFolioInvoiceService
{
    public function issue(HotelStay $stay, string $paymentMethod, int $userId, ?string $idempotencyKey = null): array
    {
        $company = Company::find($stay->company_id);
        if (!$company) {
            throw new HotelStayException(__('pos.hotel_company_missing'));
        }

        $allowed = ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment'];
        if (!in_array($paymentMethod, $allowed, true)) {
            throw new HotelStayException(__('pos.hotel_payment_method_invalid'));
        }

        $key = $idempotencyKey ? substr(trim($idempotencyKey), 0, 64) : '';
        if ($key !== '' && Schema::hasColumn('pos_transactions', 'offline_uuid')) {
            $existing = PosTransaction::where('company_id', $stay->company_id)
                ->where('offline_uuid', $key)
                ->first();
            if ($existing) {
                return [
                    'transaction' => $existing->load('items'),
                    'totals' => app(HotelFolioService::class)->totals($stay->fresh()),
                    'invoiced_amount' => (float) $existing->total_amount,
                ];
            }
        }

        $charges = HotelFolioEntry::where('company_id', $stay->company_id)
            ->where('stay_id', $stay->id)
            ->whereIn('entry_type', [HotelFolioEntry::TYPE_CHARGE, HotelFolioEntry::TYPE_ADJUSTMENT])
            ->whereNull('pos_transaction_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = $this->availableTowardFiscal($stay);
        $picked = [];
        $running = 0.0;
        foreach ($charges as $line) {
            $next = round($running + (float) $line->amount, 2);
            $fiscal = $this->fiscalTotalFor($company, $next, $paymentMethod);
            if ($fiscal - $available > 0.009) {
                break;
            }
            $picked[] = $line;
            $running = $next;
        }
        if ($picked === []) {
            if ($charges->isNotEmpty()) {
                throw new HotelStayException(__('pos.hotel_tax_coverage_needed'));
            }

            return [
                'transaction' => null,
                'totals' => app(HotelFolioService::class)->totals($stay),
                'invoiced_amount' => 0.0,
            ];
        }

        $user = \App\Models\User::find($userId);
        $praEnabled = (bool) $user?->praReportingEnabled($company);
        if ($praEnabled) {
            $invoiceMode = 'pra';
            $initialPraStatus = 'pending';
            $invoiceNumber = PosFinalSeries::issueNext((int) $stay->company_id);
        } else {
            $invoiceMode = 'pra';
            $initialPraStatus = null;
            $invoiceNumber = PosLocalSeries::issueNext((int) $stay->company_id);
        }

        $taxRate = (float) PosTaxRule::getRateForMethod($paymentMethod, $company);
        $pricingMode = $company->posTaxPricingMode();
        $taxInclusive = in_array($pricingMode, ['inclusive', 'inclusive_card_save'], true)
            && Schema::hasColumn('pos_transactions', 'tax_inclusive');
        $menuRate = null;
        if ($taxInclusive && $pricingMode === 'inclusive_card_save') {
            $menuRate = (float) PosTaxRule::getRateForMethod('cash', $company);
        }

        $subtotal = 0.0;
        $taxableSubtotal = 0.0;
        $itemPayload = [];
        foreach ($picked as $line) {
            $lineTotal = (float) $line->amount;
            $subtotal += $lineTotal;
            if ($lineTotal > 0) {
                $taxableSubtotal += $lineTotal;
            }
            $qty = max(0.001, (float) $line->quantity);
            $unitPrice = round($lineTotal / $qty, 2);
            $label = trim($line->description . ' (' . rtrim(rtrim(number_format($qty, 3), '0'), '.') . ' ' . PosUnitCatalog::label($line->uom) . ')');
            $itemPayload[] = [
                'line' => $line,
                'name' => $label,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        if ($taxInclusive) {
            $inc = PosTaxMath::inclusiveHeader($subtotal, $taxableSubtotal, 0.0, $taxRate, $menuRate);
            $taxAmount = $inc['tax_amount'];
            $totalAmount = $inc['total_amount'];
            $headerSubtotal = $inc['subtotal_col'];
            $exemptAmount = $inc['exempt_amount'];
        } else {
            $taxAmount = (float) round($taxableSubtotal * $taxRate / 100);
            $totalAmount = (float) round($subtotal + $taxAmount);
            $headerSubtotal = $subtotal;
            $exemptAmount = 0.0;
        }

        $branchId = null;
        if (Schema::hasColumn('pos_transactions', 'branch_id')) {
            $branchId = $stay->branch_id ?: app(BranchContextService::class)->stampBranchId();
        }

        $txnData = [
            'company_id' => (int) $stay->company_id,
            'invoice_number' => $invoiceNumber,
            'invoice_mode' => $invoiceMode,
            'customer_id' => $stay->payer_customer_id ?: $stay->guest_customer_id,
            'customer_name' => $stay->guest_name,
            'customer_phone' => $stay->guest_phone,
            'subtotal' => $headerSubtotal,
            'discount_type' => 'amount',
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'exempt_amount' => $exemptAmount,
            'total_amount' => $totalAmount,
            'payment_method' => $paymentMethod,
            'cash_received' => $paymentMethod === 'cash' ? $totalAmount : null,
            'change_due' => $paymentMethod === 'cash' ? 0 : null,
            'status' => 'completed',
            'pra_status' => $initialPraStatus,
            'submission_hash' => hash('sha256', $stay->company_id . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp),
            'created_by' => $userId,
            'notes' => __('pos.hotel_invoice_note', ['stay' => $stay->stay_number]),
        ];
        if ($branchId && Schema::hasColumn('pos_transactions', 'branch_id')) {
            $txnData['branch_id'] = $branchId;
        }
        if (Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
            $txnData['tax_inclusive'] = $taxInclusive;
        }
        if (Schema::hasColumn('pos_transactions', 'tax_menu_rate')) {
            $txnData['tax_menu_rate'] = $menuRate;
        }
        if ($idempotencyKey && Schema::hasColumn('pos_transactions', 'offline_uuid')) {
            $txnData['offline_uuid'] = substr($idempotencyKey, 0, 64);
        }

        $transaction = PosTransaction::create($txnData);

        foreach ($itemPayload as $row) {
            $line = $row['line'];
            $itemTax = $taxInclusive
                ? PosTaxMath::inclusiveLineTax((float) $row['line_total'], $taxRate, $menuRate)
                : round($row['line_total'] * $taxRate / 100, 2);
            PosTransactionItem::create([
                'transaction_id' => $transaction->id,
                'item_type' => 'product',
                'item_id' => $line->product_id,
                'item_name' => $row['name'],
                'quantity' => $row['qty'],
                'unit_price' => $row['unit_price'],
                'subtotal' => $row['line_total'],
                'is_tax_exempt' => false,
                'tax_rate' => $taxRate,
                'tax_amount' => $itemTax,
            ]);
            $line->pos_transaction_id = $transaction->id;
            $line->save();
        }

        AuditLogService::log('hotel_folio_invoiced', 'hotel_stay', $stay->id, null, [
            'stay_number' => $stay->stay_number,
            'invoice_number' => $invoiceNumber,
            'transaction_id' => $transaction->id,
            'amount' => $totalAmount,
        ], (int) $stay->company_id, $userId);

        $this->submitPra($company, $transaction, $praEnabled);

        return [
            'transaction' => $transaction->fresh('items'),
            'totals' => app(HotelFolioService::class)->totals($stay->fresh()),
            'invoiced_amount' => $running,
        ];
    }

    /**
     * Fiscal grand total for a folio charge amount using the company's
     * configured POS tax rules (same engine as storeInvoice). Folio lines
     * are treated as POS product prices: inclusive shops pay the line amount;
     * exclusive shops must also collect the configured tax before a bill issues.
     */
    public function fiscalTotalFor(Company $company, float $chargeAmount, string $paymentMethod): float
    {
        if ($chargeAmount <= 0) {
            return 0.0;
        }
        $taxRate = (float) PosTaxRule::getRateForMethod($paymentMethod, $company);
        $pricingMode = $company->posTaxPricingMode();
        $taxInclusive = in_array($pricingMode, ['inclusive', 'inclusive_card_save'], true)
            && Schema::hasColumn('pos_transactions', 'tax_inclusive');
        if ($taxInclusive) {
            $menuRate = $pricingMode === 'inclusive_card_save'
                ? (float) PosTaxRule::getRateForMethod('cash', $company)
                : null;
            $inc = PosTaxMath::inclusiveHeader($chargeAmount, $chargeAmount, 0.0, $taxRate, $menuRate);

            return (float) $inc['total_amount'];
        }

        $taxAmount = (float) round($chargeAmount * $taxRate / 100);

        return (float) round($chargeAmount + $taxAmount);
    }

    public function availableTowardFiscal(HotelStay $stay): float
    {
        $rows = HotelFolioEntry::where('company_id', $stay->company_id)
            ->where('stay_id', $stay->id)
            ->get();
        $payments = 0.0;
        $refunds = 0.0;
        $txnIds = [];
        foreach ($rows as $row) {
            if ($row->entry_type === HotelFolioEntry::TYPE_PAYMENT) {
                $payments += (float) $row->amount;
            } elseif ($row->entry_type === HotelFolioEntry::TYPE_REFUND) {
                $refunds += (float) $row->amount;
            }
            if ($row->pos_transaction_id) {
                $txnIds[(int) $row->pos_transaction_id] = true;
            }
        }
        $prior = 0.0;
        if ($txnIds !== []) {
            $prior = (float) PosTransaction::where('company_id', $stay->company_id)
                ->whereIn('id', array_keys($txnIds))
                ->sum('total_amount');
        }

        return round($payments - $refunds - $prior, 2);
    }

    private function submitPra(Company $company, PosTransaction $transaction, bool $praEnabled): void
    {
        if (!$praEnabled) {
            return;
        }
        if ($company->agentHandlesPra()) {
            $transaction->update(['pra_status' => 'pending']);

            return;
        }
        try {
            $praService = new PraIntegrationService($company);
            $praResult = $praService->sendInvoice($transaction);
            $transaction->refresh();
            if (empty($praResult['success'])) {
                $transaction->update(['pra_status' => 'offline']);
            }
        } catch (\Throwable $e) {
            $transaction->update(['pra_status' => 'offline']);
        }
    }
}
