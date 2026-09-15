<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosServiceWorkOrder;
use App\Models\PosTaxRule;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Turns one service work order into one NestPOS sale.
 *
 * Preserves operational job_number (SAL-/LND-/…) separately from fiscal
 * invoice_number (P… / L…). Idempotent: a second call returns the linked bill.
 */
class PosServiceWorkOrderInvoiceService
{
    public function issue(
        Company $company,
        int $orderId,
        string $paymentMethod,
        int $userId,
        ?int $branchId = null,
        ?string $idempotencyKey = null
    ): PosTransaction {
        $allowed = ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment'];
        if (! in_array($paymentMethod, $allowed, true)) {
            throw new InvalidArgumentException('Invalid payment method for work-order billing.');
        }

        $profile = PosServiceWorkflowProfiles::forCompany($company);
        if (! $profile) {
            throw new InvalidArgumentException('This category does not have a complete work-order workflow.');
        }

        return DB::transaction(function () use ($company, $orderId, $paymentMethod, $userId, $branchId, $idempotencyKey, $profile) {
            $query = PosServiceWorkOrder::where('company_id', $company->id)
                ->where('category', $profile['category']);
            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
            /** @var PosServiceWorkOrder $order */
            $order = $query->lockForUpdate()->findOrFail($orderId);

            if ($order->pos_transaction_id) {
                return PosTransaction::where('company_id', $company->id)
                    ->with('items')
                    ->findOrFail($order->pos_transaction_id);
            }

            $key = $idempotencyKey ? substr(trim($idempotencyKey), 0, 64) : '';
            if ($key !== '' && Schema::hasColumn('pos_transactions', 'offline_uuid')) {
                $existing = PosTransaction::where('company_id', $company->id)
                    ->where('offline_uuid', $key)
                    ->first();
                if ($existing) {
                    $order->pos_transaction_id = $existing->id;
                    $order->save();

                    return $existing->load('items');
                }
            }

            if ($order->status === 'cancelled') {
                throw new InvalidArgumentException('Cancelled work orders cannot be billed.');
            }
            $terminal = $profile['terminal'] ?? [];
            if (! in_array($order->status, $terminal, true)) {
                throw new InvalidArgumentException('Bill only after the job reaches a terminal stage.');
            }
            if ((float) $order->total_amount <= 0) {
                throw new InvalidArgumentException('Nothing to bill on this work order.');
            }

            $user = User::find($userId);
            $praEnabled = (bool) $user?->praReportingEnabled($company);
            if ($praEnabled) {
                $invoiceMode = 'pra';
                $initialPraStatus = 'pending';
                $invoiceNumber = PosFinalSeries::issueNext((int) $company->id);
            } else {
                $invoiceMode = 'pra';
                $initialPraStatus = null;
                $invoiceNumber = PosLocalSeries::issueNext((int) $company->id);
            }

            // Never mint fiscal numbers from the operational work-order prefix.
            if (str_starts_with($invoiceNumber, $profile['prefix'].'-')
                || str_contains($invoiceNumber, $order->job_number)) {
                throw new InvalidArgumentException('Fiscal invoice number must stay separate from the work-order reference.');
            }

            $taxRate = (float) PosTaxRule::getRateForMethod($paymentMethod, $company);
            $pricingMode = $company->posTaxPricingMode();
            $taxInclusive = in_array($pricingMode, ['inclusive', 'inclusive_card_save'], true)
                && Schema::hasColumn('pos_transactions', 'tax_inclusive');
            $menuRate = null;
            if ($taxInclusive && $pricingMode === 'inclusive_card_save') {
                $menuRate = (float) PosTaxRule::getRateForMethod('cash', $company);
            }

            $lineTotal = (float) $order->total_amount;
            $qty = max(0.001, (float) $order->quantity);
            $unitPrice = round($lineTotal / $qty, 2);
            $subtotal = $lineTotal;
            $taxableSubtotal = $lineTotal > 0 ? $lineTotal : 0.0;

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

            $stampBranch = $order->branch_id;
            if ($stampBranch === null && Schema::hasColumn('pos_transactions', 'branch_id')) {
                $stampBranch = app(BranchContextService::class)->stampBranchId();
            }

            $txnData = [
                'company_id' => (int) $company->id,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
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
                'submission_hash' => hash('sha256', $company->id.'|'.$invoiceNumber.'|'.$totalAmount.'|'.now()->timestamp),
                'created_by' => $userId,
                'notes' => 'Work order '.$order->job_number,
            ];
            if ($stampBranch && Schema::hasColumn('pos_transactions', 'branch_id')) {
                $txnData['branch_id'] = $stampBranch;
            }
            if (Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
                $txnData['tax_inclusive'] = $taxInclusive;
            }
            if (Schema::hasColumn('pos_transactions', 'tax_menu_rate')) {
                $txnData['tax_menu_rate'] = $menuRate;
            }
            if ($key !== '' && Schema::hasColumn('pos_transactions', 'offline_uuid')) {
                $txnData['offline_uuid'] = $key;
            }

            $transaction = PosTransaction::create($txnData);

            $itemTax = $taxInclusive
                ? PosTaxMath::inclusiveLineTax($lineTotal, $taxRate, $menuRate)
                : round($lineTotal * $taxRate / 100, 2);

            PosTransactionItem::create([
                'transaction_id' => $transaction->id,
                'item_type' => 'service',
                'item_id' => $order->service_id,
                'item_name' => $order->title.' ('.$order->job_number.')',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $lineTotal,
                'is_tax_exempt' => false,
                'tax_rate' => $taxRate,
                'tax_amount' => $itemTax,
            ]);

            $order->pos_transaction_id = $transaction->id;
            $order->save();

            AuditLogService::log('service_work_order_invoiced', 'pos_service_work_order', $order->id, null, [
                'job_number' => $order->job_number,
                'invoice_number' => $invoiceNumber,
                'transaction_id' => $transaction->id,
                'amount' => $totalAmount,
            ], (int) $company->id, $userId);

            $this->submitPra($company, $transaction, $praEnabled);

            return $transaction->fresh('items');
        });
    }

    private function submitPra(Company $company, PosTransaction $transaction, bool $praEnabled): void
    {
        if (! $praEnabled) {
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
