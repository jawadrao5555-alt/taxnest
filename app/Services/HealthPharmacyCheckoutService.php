<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HealthBatchMovement;
use App\Models\HealthBill;
use App\Models\HealthCharge;
use App\Models\HealthChargeAdjustment;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthPharmacyReturn;
use App\Models\HealthPharmacyReturnItem;
use App\Models\HealthPharmacySale;
use App\Models\HealthPharmacySaleItem;
use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Pharmacy checkout — counter sales, prescription dispensing and refunds
 * (Task 1549).
 *
 * ONE settlement path. A walk-in cash sale, a patient-linked issue and a
 * partial prescription fill all land here, so branch stock, batch traceability
 * and the money can never disagree about what left the shelf.
 *
 * FBR-READY, NOT FBR-FILED. The tax split is computed and frozen at sale time
 * and the fiscal readiness of the organisation is stamped on the row. Filing
 * itself belongs to the healthcare billing module — this module must never be
 * the second place that decides what a medicine's tax was.
 */
class HealthPharmacyCheckoutService
{
    /**
     * Settle a pharmacy sale.
     *
     * Everything — allocation, stock, prescription progress and the bill — runs
     * inside ONE transaction. A prescription that cannot be fully served either
     * commits as an honest partial fill or does not commit at all; it never
     * leaves stock deducted against a bill that failed to save.
     *
     * @param array $payload {
     *   sale_type, prescription_id, patient_name, patient_mr_no, patient_phone,
     *   payment_method, paid_amount, discount_amount, tax_rate, notes,
     *   allow_expired, lines: [{ medicine_id, quantity, unit_price,
     *   discount_amount, batch_id?, prescription_item_id?, is_substitute?,
     *   substitute_for_medicine_id?, dosage_instructions? }]
     * }
     */
    public static function sell(
        int $companyId,
        array $payload,
        ?int $branchId,
        ?int $userId,
        ?Company $company = null
    ): HealthPharmacySale {
        $lines = array_values(array_filter($payload['lines'] ?? [], function ($line) {
            return (float) ($line['quantity'] ?? 0) > 0;
        }));

        if (!$lines) {
            throw ValidationException::withMessages(['lines' => [__('health.ph_sale_empty')]]);
        }

        $settings = HealthPharmacyService::settings($companyId);
        $allowExpired = (bool) ($payload['allow_expired'] ?? false) && !$settings->block_expired_dispense;

        $prescription = null;
        if (!empty($payload['prescription_id'])) {
            $prescription = HealthPrescription::withoutGlobalScopes()
                // The patient may be a registered one (OPD wrote this slip) or
                // just a name typed at the counter; the bill copies whichever
                // exists, so the registry has to be loaded to be read.
                ->with('patient:id,name,mrn,phone')
                ->where('company_id', $companyId)
                ->find((int) $payload['prescription_id']);

            if (!$prescription) {
                throw ValidationException::withMessages(['prescription_id' => [__('health.ph_rx_missing')]]);
            }
            if ($prescription->dispense_status === HealthPrescription::DISPENSE_CANCELLED) {
                throw ValidationException::withMessages(['prescription_id' => [__('health.ph_rx_cancelled')]]);
            }
            // A slip the doctor is still writing is not dispensable.
            if ($prescription->status === HealthPrescription::STATUS_DRAFT) {
                throw ValidationException::withMessages(['prescription_id' => [__('health.ph_rx_not_issued')]]);
            }
        }

        $sale = DB::transaction(function () use ($companyId, $payload, $lines, $branchId, $userId, $company, $settings, $allowExpired, $prescription) {
            $taxRate = isset($payload['tax_rate']) && $payload['tax_rate'] !== ''
                ? round((float) $payload['tax_rate'], 2)
                : (float) $settings->default_tax_rate;

            $sale = HealthPharmacySale::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'health_department_id' => $payload['health_department_id'] ?? null,
                'sale_number' => HealthPharmacyService::nextNumber($companyId, 'health_pharmacy_sales', 'sale_number', $settings->sale_prefix ?: 'PH'),
                'sale_type' => self::resolveType($payload, $prescription),
                'prescription_id' => $prescription?->id,
                'patient_id' => $prescription?->health_patient_id ?? ($payload['patient_id'] ?? null),
                'patient_name' => $payload['patient_name'] ?? ($prescription?->patient_display_name ?: null),
                'patient_mr_no' => $payload['patient_mr_no'] ?? ($prescription?->patient_display_mr ?: null),
                'patient_phone' => $payload['patient_phone']
                    ?? $prescription?->patient_phone
                    ?? $prescription?->patient?->phone,
                'tax_rate' => $taxRate,
                'payment_method' => in_array($payload['payment_method'] ?? null, HealthPharmacySale::PAYMENT_METHODS, true)
                    ? $payload['payment_method']
                    : 'cash',
                'status' => HealthPharmacySale::STATUS_COMPLETED,
                'business_date' => now()->toDateString(),
                'notes' => $payload['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $costTotal = 0.0;
            $warnings = [];

            foreach ($lines as $line) {
                $medicine = HealthMedicine::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->find((int) ($line['medicine_id'] ?? 0));

                if (!$medicine) {
                    throw ValidationException::withMessages(['lines' => [__('health.ph_medicine_missing')]]);
                }

                self::assertSaleAllowed($medicine, $prescription, $settings);

                $quantity = round((float) $line['quantity'], 3);
                $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== ''
                    ? round((float) $line['unit_price'], 2)
                    : (float) $medicine->sale_price;
                $lineDiscount = round((float) ($line['discount_amount'] ?? 0), 2);
                $lineTaxRate = $medicine->tax_rate !== null ? (float) $medicine->tax_rate : $taxRate;

                // A pinned batch is the pharmacist overriding FEFO deliberately
                // (a ward asked for a specific lot). Everything else follows the
                // expiry policy.
                $allocations = self::allocateLine($companyId, $medicine, $quantity, $branchId, $line, $allowExpired, $warnings);

                // Discount is apportioned by quantity, so a line split across
                // two lots still adds up to exactly what the customer agreed.
                $allocatedTotal = array_sum(array_map(fn ($a) => $a['quantity'], $allocations));
                $discountLeft = $lineDiscount;
                $index = 0;

                foreach ($allocations as $allocation) {
                    $index++;
                    /** @var HealthMedicineBatch $batch */
                    $batch = $allocation['batch'];
                    $chunk = $allocation['quantity'];

                    $share = $index === count($allocations)
                        ? $discountLeft
                        : round($allocatedTotal > 0 ? $lineDiscount * ($chunk / $allocatedTotal) : 0, 2);
                    $discountLeft = round($discountLeft - $share, 2);

                    $gross = round($unitPrice * $chunk, 2);
                    $net = round($gross - $share, 2);
                    $tax = round($net * $lineTaxRate / 100, 2);

                    HealthPharmacySaleItem::withoutGlobalScopes()->create([
                        'company_id' => $companyId,
                        'sale_id' => $sale->id,
                        'medicine_id' => $medicine->id,
                        'product_id' => $medicine->product_id,
                        'batch_id' => $batch->id,
                        'prescription_item_id' => $line['prescription_item_id'] ?? null,
                        'item_name' => $medicine->display_name,
                        'batch_no' => $batch->batch_no,
                        'expiry_date' => $batch->expiry_date?->toDateString(),
                        'quantity' => $chunk,
                        'unit_price' => $unitPrice,
                        'unit_cost' => (float) $batch->cost_price,
                        'discount_amount' => $share,
                        'tax_rate' => $lineTaxRate,
                        'tax_amount' => $tax,
                        'line_total' => round($net + $tax, 2),
                        'is_substitute' => (bool) ($line['is_substitute'] ?? false),
                        'substitute_for_medicine_id' => $line['substitute_for_medicine_id'] ?? null,
                        'approved_by' => !empty($line['is_substitute']) ? $userId : null,
                        'dosage_instructions' => $line['dosage_instructions'] ?? $medicine->default_dosage,
                    ]);

                    HealthPharmacyStockService::deduct(
                        $companyId,
                        $batch,
                        $chunk,
                        HealthBatchMovement::TYPE_DISPENSE,
                        ['type' => 'health_pharmacy_sale', 'id' => $sale->id, 'number' => $sale->sale_number],
                        $userId,
                        null,
                        null,
                        $unitPrice
                    );

                    $subtotal = round($subtotal + $gross, 2);
                    $discountTotal = round($discountTotal + $share, 2);
                    $taxTotal = round($taxTotal + $tax, 2);
                    $costTotal = round($costTotal + $chunk * (float) $batch->cost_price, 2);
                }

                // Prescription progress is recorded against what ACTUALLY went
                // out, not what was asked for — that is what makes the remaining
                // quantity trustworthy on the next visit.
                if (!empty($line['prescription_item_id']) && $allocatedTotal > 0) {
                    self::advancePrescriptionItem(
                        $companyId,
                        (int) $line['prescription_item_id'],
                        $allocatedTotal,
                        $prescription?->id
                    );
                }
            }

            $total = round($subtotal - $discountTotal + $taxTotal, 2);
            $paid = isset($payload['paid_amount']) && $payload['paid_amount'] !== ''
                ? round((float) $payload['paid_amount'], 2)
                : $total;

            $readiness = HealthPlatformService::fbrReadiness($company);

            $sale->fill([
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_amount' => $taxTotal,
                'total_amount' => $total,
                'cost_amount' => $costTotal,
                'paid_amount' => $paid,
                'change_amount' => max(0, round($paid - $total, 2)),
                'fbr_ready' => (bool) $readiness['configured'],
                'fbr_status' => $readiness['configured'] ? 'pending' : 'not_configured',
            ]);
            $sale->save();

            if ($prescription) {
                self::refreshPrescriptionStatus($companyId, $prescription);
            }

            // Counter feedback only — never a column (see the model property).
            $sale->dispenseWarnings = $warnings;

            return $sale;
        });

        /*
         * The books follow the counter, outside its transaction. Cost of sales
         * is posted for EVERY sale; the revenue half only for a walk-in that
         * never becomes a patient charge, because a patient-linked sale already
         * reaches the ledger through its bill.
         */
        HealthPostingService::auto('postPharmacyCogs', $sale);
        HealthPostingService::auto('postPharmacySaleRevenue', $sale);

        return $sale;
    }

    /**
     * Accept medicine back and refund it.
     *
     * `restock` is one decision for the whole document: sealed goods return to
     * their own lot, opened or damaged goods are written off as wastage. Either
     * way the money and the medicine remain attributable.
     *
     * @param array $lines [{ sale_item_id, quantity }]
     */
    public static function refund(
        int $companyId,
        HealthPharmacySale $sale,
        array $lines,
        bool $restock,
        ?string $reason,
        ?int $userId
    ): HealthPharmacyReturn {
        if ($sale->status === HealthPharmacySale::STATUS_VOID) {
            throw ValidationException::withMessages(['sale' => [__('health.ph_sale_void')]]);
        }

        $wanted = [];
        foreach ($lines as $line) {
            $quantity = round((float) ($line['quantity'] ?? 0), 3);
            if ($quantity > 0) {
                $wanted[(int) ($line['sale_item_id'] ?? 0)] = $quantity;
            }
        }

        if (!$wanted) {
            throw ValidationException::withMessages(['lines' => [__('health.ph_return_empty')]]);
        }

        $document = DB::transaction(function () use ($companyId, $sale, $wanted, $restock, $reason, $userId) {
            $document = HealthPharmacyReturn::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'return_number' => HealthPharmacyService::nextNumber($companyId, 'health_pharmacy_returns', 'return_number', HealthPharmacyService::RETURN_PREFIX),
                'restock' => $restock,
                'reason' => $reason,
                'created_by' => $userId,
            ]);

            $refund = 0.0;

            foreach ($wanted as $saleItemId => $quantity) {
                $item = HealthPharmacySaleItem::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('sale_id', $sale->id)
                    ->lockForUpdate()
                    ->find($saleItemId);

                if (!$item) {
                    throw ValidationException::withMessages(['lines' => [__('health.ph_return_line_missing')]]);
                }

                $returnable = $item->returnable_quantity;
                if ($quantity > $returnable + 0.0005) {
                    throw ValidationException::withMessages([
                        'lines' => [__('health.ph_return_too_much', ['item' => $item->item_name])],
                    ]);
                }

                // Refund the money this quantity actually carried — the line's
                // own net rate including its share of discount and tax. Never
                // the list price, or a discounted sale refunds more than it took.
                $lineRate = (float) $item->quantity > 0
                    ? round((float) $item->line_total / (float) $item->quantity, 4)
                    : 0.0;
                $lineRefund = round($lineRate * $quantity, 2);

                $item->returned_quantity = round((float) $item->returned_quantity + $quantity, 3);
                $item->save();

                HealthPharmacyReturnItem::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'return_id' => $document->id,
                    'sale_item_id' => $item->id,
                    'medicine_id' => $item->medicine_id,
                    'batch_id' => $item->batch_id,
                    'quantity' => $quantity,
                    'unit_price' => (float) $item->unit_price,
                    'refund_amount' => $lineRefund,
                    'restocked' => $restock,
                ]);

                $batch = $item->batch_id
                    ? HealthMedicineBatch::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->find($item->batch_id)
                    : null;

                if ($batch) {
                    $reference = ['type' => 'health_pharmacy_return', 'id' => $document->id, 'number' => $document->return_number];

                    if ($restock) {
                        HealthPharmacyStockService::restock(
                            $companyId,
                            $batch,
                            $quantity,
                            HealthBatchMovement::TYPE_SALE_RETURN,
                            $reference,
                            $userId,
                            $reason
                        );
                    } else {
                        // Goods came back but cannot be sold again: they must be
                        // recorded as wastage, never quietly vanish between the
                        // refund and the shelf.
                        HealthPharmacyStockService::restock(
                            $companyId,
                            $batch,
                            $quantity,
                            HealthBatchMovement::TYPE_SALE_RETURN,
                            $reference,
                            $userId,
                            $reason
                        );
                        HealthPharmacyStockService::deduct(
                            $companyId,
                            $batch->refresh(),
                            $quantity,
                            HealthBatchMovement::TYPE_WASTAGE,
                            $reference,
                            $userId,
                            'damaged'
                        );
                    }
                }

                $refund = round($refund + $lineRefund, 2);
            }

            $document->refund_amount = $refund;
            $document->save();

            $sale->refunded_amount = round((float) $sale->refunded_amount + $refund, 2);
            $sale->status = self::resolveSaleStatus($companyId, $sale);
            $sale->save();

            return $document;
        });

        // A sale billed to a patient never took money at the counter, so its
        // correction belongs on the patient's own ledger, not the drawer.
        self::correctPatientLedger($companyId, $sale->fresh(), $document, $userId);

        HealthPostingService::auto('postPharmacyReturn', $document);

        return $document;
    }

    /**
     * A returned medicine that was billed to a patient must stop being billed.
     *
     * ONE correction, and which one depends on how far the paperwork got:
     *
     *   charge still open  → it is reversed and re-raised for what the patient
     *       actually kept. Nothing reached the books yet (income posts when the
     *       bill is finalized), so the charge ledger is the whole correction.
     *   charge frozen by a finalized bill → the bill stands as printed and
     *       possibly filed. The correction is recorded against the charge for
     *       the audit trail, and the money side is a credit to the patient's
     *       receivable, posted from the return itself.
     *
     * Never both: the two paths are exclusive, or the patient is credited twice
     * for one strip of medicine.
     */
    private static function correctPatientLedger(
        int $companyId,
        HealthPharmacySale $sale,
        HealthPharmacyReturn $document,
        ?int $userId
    ): void {
        if (!$sale->patient_id || !Schema::hasTable('health_charges')) {
            return;
        }

        $charge = HealthCharge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('source_type', HealthCharge::SOURCE_PHARMACY_SALE)
            ->where('source_id', $sale->id)
            ->where('status', '!=', HealthCharge::STATUS_REVERSED)
            ->orderByDesc('id')
            ->first();

        // Not billed yet: the charge ingest raises it for what was kept.
        if (!$charge) {
            return;
        }

        $actor = $userId ? User::find($userId) : null;
        $reason = __('health.ph_return_charge_reason', ['no' => $document->return_number]);

        $bill = $charge->health_bill_id
            ? HealthBill::withoutGlobalScopes()->find($charge->health_bill_id)
            : null;

        if ($charge->isLocked() || ($bill && $bill->isFinal())) {
            HealthChargeService::adjust(
                $charge,
                HealthChargeAdjustment::KIND_CORRECTION,
                round((float) $document->refund_amount, 2),
                $reason,
                $actor
            );

            return;
        }

        $reversal = HealthChargeService::reverse($charge, $actor, $reason);
        if (!($reversal['ok'] ?? false)) {
            // It froze underneath us — the books carry the credit instead.
            return;
        }

        $saleTotal = round((float) $sale->total_amount, 2);
        $kept = $saleTotal > 0
            ? max(0.0, round($saleTotal - (float) $sale->refunded_amount, 2)) / $saleTotal
            : 0.0;

        // The whole sale came back: there is nothing left to bill anybody for.
        if ($kept <= 0) {
            return;
        }

        $gross = round((float) $charge->gross_amount * $kept, 2);
        if ($gross <= 0) {
            return;
        }

        HealthChargeService::post([
            'company_id' => $companyId,
            'branch_id' => $charge->branch_id,
            'health_department_id' => $charge->health_department_id,
            'health_patient_id' => $charge->health_patient_id,
            'health_visit_id' => $charge->health_visit_id,
            'health_admission_id' => $charge->health_admission_id,
            'charge_date' => $charge->charge_date
                ? $charge->charge_date->toDateString()
                : now()->toDateString(),
            'category' => $charge->category,
            'description' => $charge->description,
            'reference' => $charge->reference,
            'source_type' => HealthCharge::SOURCE_PHARMACY_SALE,
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
            'unit_price' => $gross,
            'quantity' => 1,
            'gross_amount' => $gross,
            'concession_amount' => round((float) $charge->concession_amount * $kept, 2),
            'tax_amount' => round((float) $charge->tax_amount * $kept, 2),
            'tax_rate' => (float) $charge->tax_rate,
            'health_tax_category_id' => $charge->health_tax_category_id,
            'dedupe_key' => 'pharmacy_sale:' . $sale->id . ':kept:' . $document->id,
            'created_by' => $userId ?: $charge->created_by,
        ], $actor);
    }

    // ═══════════════════════ Internals ═══════════════════════

    /**
     * A controlled or prescription-only medicine may not leave the counter on a
     * walk-in sale while the owner's policy requires a prescription. The gate
     * lives here, on the settlement path, so no screen can route around it.
     */
    private static function assertSaleAllowed(HealthMedicine $medicine, ?HealthPrescription $prescription, $settings): void
    {
        if (!$settings->require_prescription_for_controlled) {
            return;
        }

        $restricted = $medicine->requires_prescription || $medicine->is_controlled || $medicine->is_narcotic;
        if ($restricted && !$prescription) {
            throw ValidationException::withMessages([
                'lines' => [__('health.ph_needs_prescription', ['name' => $medicine->display_name])],
            ]);
        }
    }

    private static function allocateLine(
        int $companyId,
        HealthMedicine $medicine,
        float $quantity,
        ?int $branchId,
        array $line,
        bool $allowExpired,
        array &$warnings
    ): array {
        if (!empty($line['batch_id'])) {
            $batch = HealthMedicineBatch::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('medicine_id', $medicine->id)
                ->where('branch_id', $branchId)
                ->find((int) $line['batch_id']);

            if (!$batch) {
                throw ValidationException::withMessages(['lines' => [__('health.ph_batch_missing')]]);
            }
            if ($batch->status !== HealthMedicineBatch::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'lines' => [__('health.ph_batch_not_sellable', ['batch' => $batch->batch_no ?: __('health.ph_no_batch')])],
                ]);
            }
            if ($batch->isExpired() && !$allowExpired) {
                throw ValidationException::withMessages([
                    'lines' => [__('health.ph_batch_expired', ['batch' => $batch->batch_no ?: __('health.ph_no_batch')])],
                ]);
            }

            return [['batch' => $batch, 'quantity' => $quantity]];
        }

        $plan = HealthPharmacyStockService::plan($companyId, $medicine, $quantity, $branchId, [
            'allow_expired' => $allowExpired,
        ]);

        if ($plan['shortfall'] > 0) {
            throw ValidationException::withMessages([
                'lines' => [__('health.ph_not_enough_stock', ['name' => $medicine->display_name])],
            ]);
        }

        foreach ($plan['warnings'] as $warning) {
            $warnings[] = array_merge($warning, ['medicine' => $medicine->display_name]);
        }

        return $plan['allocations'];
    }

    private static function advancePrescriptionItem(
        int $companyId,
        int $itemId,
        float $quantity,
        ?int $prescriptionId
    ): void {
        // A line id is claimed by the request, so it is bound to the slip this
        // sale is actually filling — not merely to the company. Without the
        // second key a tampered id could spend a different patient's
        // authorisation, and both headers would then disagree with their lines.
        $item = HealthPrescriptionItem::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($prescriptionId, fn ($q) => $q->where('health_prescription_id', $prescriptionId))
            ->lockForUpdate()
            ->find($itemId);

        if (!$item || !$prescriptionId || (int) $item->health_prescription_id !== (int) $prescriptionId) {
            throw ValidationException::withMessages([
                'lines' => [__('health.ph_rx_line_mismatch')],
            ]);
        }

        // A prescription is an authorisation for a QUANTITY, not just for a
        // medicine. The guard lives here, under the row lock, so every path
        // that dispenses — the prescription screen, the counter, anything
        // added later — is held to the same limit instead of trusting each
        // caller to check it first. Throwing rolls the whole sale back, so no
        // stock leaves on a bill that was never allowed.
        if ($quantity > $item->remaining_quantity + 0.0005) {
            throw ValidationException::withMessages([
                'lines' => [__('health.ph_rx_over_dispense', ['name' => $item->medicine_name])],
            ]);
        }

        $item->dispensed_quantity = round((float) $item->dispensed_quantity + $quantity, 3);
        $item->save();
    }

    /**
     * A prescription is finished only when every live line is fully served.
     * Recomputed from the lines each time, so a cancelled line or a later top-up
     * cannot leave the header saying something the items disagree with.
     */
    public static function refreshPrescriptionStatus(int $companyId, HealthPrescription $prescription): HealthPrescription
    {
        $items = HealthPrescriptionItem::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_prescription_id', $prescription->id)
            ->get();

        $live = $items->where('is_cancelled', false);

        if ($prescription->dispense_status === HealthPrescription::DISPENSE_CANCELLED) {
            return $prescription;
        }

        $dispensedAny = $items->sum('dispensed_quantity') > 0;
        $allDone = $live->isNotEmpty() && $live->every(fn ($item) => $item->remaining_quantity <= 0);

        // Only the pharmacy's own column moves. The doctor's `status` is left
        // exactly as it was written in the consultation room.
        $prescription->dispense_status = $allDone
            ? HealthPrescription::DISPENSE_DISPENSED
            : ($dispensedAny ? HealthPrescription::DISPENSE_PARTIAL : HealthPrescription::DISPENSE_PENDING);
        $prescription->completed_at = $allDone ? ($prescription->completed_at ?? now()) : null;
        $prescription->save();

        return $prescription;
    }

    private static function resolveSaleStatus(int $companyId, HealthPharmacySale $sale): string
    {
        $items = HealthPharmacySaleItem::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sale_id', $sale->id)
            ->get();

        $sold = round((float) $items->sum('quantity'), 3);
        $returned = round((float) $items->sum('returned_quantity'), 3);

        if ($returned <= 0) {
            return HealthPharmacySale::STATUS_COMPLETED;
        }

        return $returned >= $sold - 0.0005
            ? HealthPharmacySale::STATUS_RETURNED
            : HealthPharmacySale::STATUS_PARTIALLY_RETURNED;
    }

    private static function resolveType(array $payload, ?HealthPrescription $prescription): string
    {
        if ($prescription) {
            return HealthPharmacySale::TYPE_PRESCRIPTION;
        }

        $type = $payload['sale_type'] ?? HealthPharmacySale::TYPE_COUNTER;

        return in_array($type, [HealthPharmacySale::TYPE_COUNTER, HealthPharmacySale::TYPE_PATIENT], true)
            ? $type
            : HealthPharmacySale::TYPE_COUNTER;
    }
}
