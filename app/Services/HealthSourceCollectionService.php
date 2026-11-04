<?php

namespace App\Services;

use App\Models\{HealthAdmissionPayment, HealthBill, HealthCharge, HealthPayment, HealthPharmacySale, HealthVisit};
use Illuminate\Support\Carbon;

/** Attach cash already collected outside the central cashier to the corresponding bill once. */
final class HealthSourceCollectionService
{
    /** Called inside finalization with the bill locked. Returns new receipt rows for journal posting. */
    public static function apply(HealthBill $bill): array
    {
        $receipts = [];
        $remaining = round((float) $bill->patient_payable, 2);
        $charges = HealthCharge::withoutGlobalScopes()->where('company_id', $bill->company_id)
            ->where('health_bill_id', $bill->id)->where('health_patient_id', $bill->health_patient_id)->orderBy('id')->get();
        foreach ($charges as $charge) {
            if ($remaining <= 0) {
                break;
            }
            if ($charge->source_type === HealthCharge::SOURCE_VISIT) {
                $source = HealthVisit::withoutGlobalScopes()->where('company_id', $bill->company_id)
                    ->where('health_patient_id', $bill->health_patient_id)->where('fee_status', HealthVisit::FEE_PAID)
                    ->whereNotNull('fee_collected_at')->lockForUpdate()->find($charge->source_id);
                if ($source) {
                    self::attach($bill, $receipts, $remaining, 'visit', $source->id, (float) $source->net_fee,
                        (float) $charge->total_amount, $source->fee_collected_at, $source->payment_method, $source->fee_collected_by);
                }
            } elseif ($charge->source_type === HealthCharge::SOURCE_PHARMACY_SALE) {
                $source = HealthPharmacySale::withoutGlobalScopes()->where('company_id', $bill->company_id)
                    ->where('patient_id', $bill->health_patient_id)->where('status', '!=', HealthPharmacySale::STATUS_VOID)
                    ->lockForUpdate()->find($charge->source_id);
                if ($source) {
                    $cash = min((float) $source->total_amount, (float) $source->paid_amount) - (float) $source->refunded_amount;
                    self::attach($bill, $receipts, $remaining, 'pharmacy_sale', $source->id, $cash,
                        (float) $charge->total_amount, $source->created_at, $source->payment_method, $source->created_by);
                }
            }
        }
        foreach ($charges->whereNotNull('health_admission_id')->groupBy('health_admission_id') as $stayId => $lines) {
            $stay = \App\Models\HealthAdmission::withoutGlobalScopes()->where('company_id', $bill->company_id)
                ->where('health_patient_id', $bill->health_patient_id)->lockForUpdate()->find($stayId);
            if (!$stay || $remaining <= 0) {
                continue;
            }
            $payments = HealthAdmissionPayment::withoutGlobalScopes()->where('company_id', $bill->company_id)
                ->where('health_admission_id', $stay->id)->orderBy('id')->lockForUpdate()->get();
            $advances = $payments->where('kind', HealthAdmissionPayment::KIND_ADVANCE);
            $net = $advances->sum('amount') - $payments->where('kind', HealthAdmissionPayment::KIND_REFUND)->sum('amount');
            $used = HealthPayment::withoutGlobalScopes()->where('company_id', $bill->company_id)
                ->where('collection_source', 'ipd_advance')->whereIn('collection_source_id', $advances->modelKeys())->sum('amount');
            $budget = min((float) $lines->sum('total_amount'), round($net - $used, 2));
            foreach ($advances as $advance) {
                if ($budget <= 0 || $remaining <= 0) {
                    break;
                }
                $budget -= self::attach($bill, $receipts, $remaining, 'ipd_advance', $advance->id, $advance->amount,
                    $budget, $advance->received_at, $advance->method, $advance->received_by, $stay->id);
            }
        }
        return $receipts;
    }

    private static function attach(HealthBill $bill, array &$receipts, float &$balance, string $source, int $sourceId,
        float $collected, float $lineLimit, $receivedAt, ?string $method, ?int $actor, ?int $stay = null): float
    {
        $used = HealthPayment::withoutGlobalScopes()->where('company_id', $bill->company_id)
            ->where('collection_source', $source)->where('collection_source_id', $sourceId)->sum('amount');
        $amount = round(min($collected - $used, $lineLimit, $balance), 2);
        if ($amount <= 0) {
            return 0;
        }
        $when = Carbon::parse($receivedAt);
        $receipts[] = HealthPayment::withoutGlobalScopes()->create([
            'company_id'=>$bill->company_id, 'branch_id'=>$bill->branch_id, 'health_patient_id'=>$bill->health_patient_id,
            'health_bill_id'=>$bill->id, 'health_admission_id'=>$stay, 'receipt_no'=>HealthNumberService::receiptNumber($bill->company_id),
            'kind'=>HealthPayment::KIND_PAYMENT, 'amount'=>$amount, 'method'=>$method ?: 'cash',
            'received_at'=>$when, 'business_date'=>$when->toDateString(), 'received_by'=>$actor,
            'collection_source'=>$source, 'collection_source_id'=>$sourceId, 'reference'=>$source.' #'.$sourceId,
        ]);
        $balance = round($balance - $amount, 2);
        return $amount;
    }
}
