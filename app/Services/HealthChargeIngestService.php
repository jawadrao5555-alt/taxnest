<?php

namespace App\Services;

use App\Models\HealthAdmissionCharge;
use App\Models\HealthCharge;
use App\Models\HealthOperation;
use App\Models\HealthPharmacySale;
use App\Models\HealthVisit;
use Illuminate\Support\Facades\Schema;

/**
 * Feeding the unified ledger from every module (Task 1551, step 1).
 *
 * The other healthcare modules each already record their own money — the OPD
 * fee on the visit, the pharmacy total on the sale, the stay's lines on the
 * admission. This class pulls all of it onto the one ledger the billing engine
 * reads.
 *
 * PULL, not push, and the reason matters. Editing every module's write path to
 * also post a charge would mean touching OPD, pharmacy, IPD and theatre code
 * that is already live and already correct, and any one of those edits failing
 * mid-transaction would leave a module unable to record its own work because
 * billing was unhappy. Scanning instead means:
 *
 *  - the modules stay untouched and cannot be broken by billing,
 *  - a charge missed during an outage is picked up on the next run,
 *  - the whole thing is idempotent by construction, because every charge is
 *    posted under a deterministic dedupe key.
 *
 * A module that does not exist yet (laboratory) is simply skipped — the table
 * guard means it starts contributing the day its tables land, with no change
 * here beyond the block that reads it.
 */
class HealthChargeIngestService
{
    /**
     * Bring one patient's ledger up to date across every module.
     *
     * @return array{posted:int,scanned:int}
     */
    public static function syncPatient(int $companyId, int $patientId, $actor = null): array
    {
        $posted = 0;
        $scanned = 0;

        foreach ([
            self::ingestVisits($companyId, $patientId, $actor),
            self::ingestPharmacySales($companyId, $patientId, $actor),
            self::ingestAdmissionCharges($companyId, $patientId, $actor),
            self::ingestOperations($companyId, $patientId, $actor),
        ] as $result) {
            $posted += $result['posted'];
            $scanned += $result['scanned'];
        }

        return ['posted' => $posted, 'scanned' => $scanned];
    }

    /**
     * OPD consultation fees.
     *
     * Only COMPLETED visits, and only ones with money on them. A waiting or
     * in-consultation visit has not happened yet, and a waived fee is a
     * decision to charge nothing — putting a zero line on the ledger would make
     * the patient's statement longer without making it truer.
     */
    private static function ingestVisits(int $companyId, int $patientId, $actor = null): array
    {
        if (!Schema::hasTable('health_visits')) {
            return ['posted' => 0, 'scanned' => 0];
        }

        $visits = HealthVisit::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_patient_id', $patientId)
            ->where('status', HealthVisit::STATUS_COMPLETED)
            ->where('fee_amount', '>', 0)
            ->orderBy('id')
            ->get();

        $posted = 0;
        foreach ($visits as $visit) {
            $charge = HealthChargeService::post([
                'company_id' => $companyId,
                'branch_id' => $visit->branch_id,
                'health_department_id' => $visit->health_department_id,
                'health_patient_id' => $patientId,
                'health_visit_id' => $visit->id,
                'charge_date' => $visit->visit_date ? $visit->visit_date->toDateString() : now()->toDateString(),
                'category' => HealthCharge::CAT_OPD,
                'description' => __('health.led_desc_consultation'),
                'reference' => $visit->visit_no,
                'source_type' => HealthCharge::SOURCE_VISIT,
                'source_id' => $visit->id,
                'source_reference' => $visit->visit_no,
                'unit_price' => (float) $visit->fee_amount,
                'quantity' => 1,
                'gross_amount' => (float) $visit->fee_amount,
                'concession_amount' => (float) $visit->concession_amount,
                'concession_reason' => $visit->concession_reason,
                'dedupe_key' => 'visit:' . $visit->id . ':fee',
                'created_by' => $actor->id ?? $visit->opened_by,
            ], $actor);

            if ($charge && $charge->wasRecentlyCreated) {
                $posted++;
            }
        }

        return ['posted' => $posted, 'scanned' => $visits->count()];
    }

    /**
     * Pharmacy counter sales made out to this patient.
     *
     * The sale already agreed its subtotal, discount and tax with the customer
     * at the counter, so those numbers are carried across verbatim rather than
     * recomputed. Re-deriving them here would move the total on a receipt the
     * patient is already holding.
     *
     * Voided sales never reach the ledger. A returned or partially returned one
     * does — the money was genuinely taken, and the return is its own event; a
     * sale that vanished from the ledger because it was later returned would
     * leave the day's cash unexplainable.
     */
    private static function ingestPharmacySales(int $companyId, int $patientId, $actor = null): array
    {
        if (!Schema::hasTable('health_pharmacy_sales')) {
            return ['posted' => 0, 'scanned' => 0];
        }

        $sales = HealthPharmacySale::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('patient_id', $patientId)
            ->where('status', '!=', HealthPharmacySale::STATUS_VOID)
            ->where('total_amount', '>', 0)
            ->orderBy('id')
            ->get();

        $posted = 0;
        foreach ($sales as $sale) {
            // The sale's own tax split is honoured only where the hospital's
            // rulebook says pharmacy reports. Otherwise the ledger records the
            // full amount as local money — which is what the resolver decides,
            // not this loop.
            $charge = HealthChargeService::post([
                'company_id' => $companyId,
                'branch_id' => $sale->branch_id,
                'health_department_id' => $sale->health_department_id,
                'health_patient_id' => $patientId,
                'charge_date' => $sale->business_date
                    ? $sale->business_date->toDateString()
                    : ($sale->created_at ? $sale->created_at->toDateString() : now()->toDateString()),
                'category' => HealthCharge::CAT_PHARMACY,
                'description' => __('health.led_desc_pharmacy', ['no' => $sale->sale_number]),
                'reference' => $sale->sale_number,
                'source_type' => HealthCharge::SOURCE_PHARMACY_SALE,
                'source_id' => $sale->id,
                'source_reference' => $sale->sale_number,
                'unit_price' => (float) $sale->subtotal,
                'quantity' => 1,
                'gross_amount' => (float) $sale->subtotal,
                'concession_amount' => (float) $sale->discount_amount,
                'tax_amount' => (float) $sale->tax_amount,
                'tax_rate' => (float) $sale->tax_rate,
                'dedupe_key' => 'pharmacy_sale:' . $sale->id,
                'created_by' => $actor->id ?? $sale->created_by,
            ], $actor);

            if ($charge && $charge->wasRecentlyCreated) {
                $posted++;
            }
        }

        return ['posted' => $posted, 'scanned' => $sales->count()];
    }

    /**
     * The inpatient stay's own lines — room-days, nursing, services, medicines.
     *
     * The IPD ledger keeps producing these; this simply mirrors each posted line
     * onto the unified ledger so a discharge bill and an OPD receipt come out of
     * the same engine. A line reversed on the IPD side is reversed here too, so
     * the two never disagree about what the patient owes.
     */
    private static function ingestAdmissionCharges(int $companyId, int $patientId, $actor = null): array
    {
        if (!Schema::hasTable('health_admission_charges')) {
            return ['posted' => 0, 'scanned' => 0];
        }

        $rows = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_patient_id', $patientId)
            ->orderBy('id')
            ->get();

        $posted = 0;
        foreach ($rows as $row) {
            $dedupe = 'admission_charge:' . $row->id;

            if ($row->status === HealthAdmissionCharge::STATUS_REVERSED) {
                self::mirrorReversal($companyId, $dedupe, $row->reversal_reason, $actor);
                continue;
            }

            $charge = HealthChargeService::post([
                'company_id' => $companyId,
                'branch_id' => $row->branch_id,
                'health_patient_id' => $patientId,
                'health_admission_id' => $row->health_admission_id,
                'charge_date' => $row->charge_date
                    ? (is_string($row->charge_date) ? $row->charge_date : $row->charge_date->toDateString())
                    : now()->toDateString(),
                'category' => self::mapAdmissionCategory($row->category),
                'description' => $row->description,
                'reference' => $row->reference,
                'source_type' => HealthCharge::SOURCE_ADMISSION_CHARGE,
                'source_id' => $row->id,
                'source_reference' => $row->reference,
                'unit_price' => (float) $row->unit_price,
                'quantity' => (float) $row->quantity,
                'gross_amount' => (float) $row->gross_amount,
                'concession_amount' => (float) $row->concession_amount,
                'concession_reason' => $row->concession_reason,
                'dedupe_key' => $dedupe,
                'created_by' => $actor->id ?? $row->created_by,
            ], $actor);

            if ($charge && $charge->wasRecentlyCreated) {
                $posted++;
            }
        }

        return ['posted' => $posted, 'scanned' => $rows->count()];
    }

    /**
     * Theatre work that is NOT already on an admission.
     *
     * An operation performed during a stay posts its fee onto the admission
     * ledger, and that line is mirrored by ingestAdmissionCharges() above.
     * Ingesting the operation as well would charge the patient twice for one
     * procedure, so only day-case theatre work with no admission behind it is
     * picked up here.
     */
    private static function ingestOperations(int $companyId, int $patientId, $actor = null): array
    {
        if (!Schema::hasTable('health_operations')) {
            return ['posted' => 0, 'scanned' => 0];
        }

        $ops = HealthOperation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_patient_id', $patientId)
            ->where('status', HealthOperation::STATUS_COMPLETED)
            ->whereNull('health_admission_id')
            ->where('price', '>', 0)
            ->orderBy('id')
            ->get();

        $posted = 0;
        foreach ($ops as $op) {
            $charge = HealthChargeService::post([
                'company_id' => $companyId,
                'branch_id' => $op->branch_id,
                'health_department_id' => $op->health_department_id,
                'health_patient_id' => $patientId,
                'charge_date' => $op->actual_end
                    ? $op->actual_end->toDateString()
                    : ($op->scheduled_start ? $op->scheduled_start->toDateString() : now()->toDateString()),
                'category' => HealthCharge::CAT_OPERATION,
                'description' => $op->title ?: __('health.led_desc_operation'),
                'reference' => $op->operation_no,
                'source_type' => HealthCharge::SOURCE_OPERATION,
                'source_id' => $op->id,
                'source_reference' => $op->operation_no,
                'unit_price' => (float) $op->price,
                'quantity' => 1,
                'gross_amount' => (float) $op->price,
                'concession_amount' => (float) $op->concession_amount,
                'concession_reason' => $op->concession_reason,
                'dedupe_key' => 'operation:' . $op->id,
                'created_by' => $actor->id ?? $op->created_by,
            ], $actor);

            if ($charge && $charge->wasRecentlyCreated) {
                $posted++;
            }
        }

        return ['posted' => $posted, 'scanned' => $ops->count()];
    }

    /**
     * A module reversed its line, so the mirror must be reversed too.
     *
     * Refused once the mirror sits on a finalized bill — the ledger cannot walk
     * back money that has already been billed and possibly filed, and
     * HealthChargeService::reverse() enforces that for us. The bill's own
     * refund path is the correction there.
     */
    private static function mirrorReversal(int $companyId, string $dedupeKey, ?string $reason, $actor = null): void
    {
        $mirror = HealthCharge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if (!$mirror || $mirror->status === HealthCharge::STATUS_REVERSED) {
            return;
        }

        HealthChargeService::reverse($mirror, $actor, $reason ?: __('health.led_reversed_upstream'));
    }

    /**
     * The IPD ledger's categories onto the unified ones.
     *
     * 'medicine' becomes 'pharmacy' so a medicine given on the ward and a strip
     * sold at the pharmacy counter land in the same bucket — a hospital asking
     * "what did we bill in medicines this month" means both.
     */
    private static function mapAdmissionCategory(?string $category): string
    {
        $map = [
            'room' => HealthCharge::CAT_ROOM,
            'nursing' => HealthCharge::CAT_NURSING,
            'service' => HealthCharge::CAT_SERVICE,
            'medicine' => HealthCharge::CAT_PHARMACY,
            'consumable' => HealthCharge::CAT_CONSUMABLE,
            'procedure' => HealthCharge::CAT_PROCEDURE,
            'doctor' => HealthCharge::CAT_DOCTOR,
            'investigation' => HealthCharge::CAT_INVESTIGATION,
            'misc' => HealthCharge::CAT_MISC,
        ];

        return $map[$category] ?? HealthCharge::CAT_SERVICE;
    }
}
