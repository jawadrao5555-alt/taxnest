<?php

namespace App\Services;

use App\Models\HealthCharge;
use App\Models\HealthChargeAdjustment;
use App\Models\HealthTaxCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The unified charge ledger (Task 1551, step 1).
 *
 * Every healthcare module posts money here and nowhere else. OPD fees, pharmacy
 * sales, laboratory work, room and nursing days, operations and hand-posted
 * services all become the same shape of row, carrying the branch, department,
 * patient, encounter, admission and the person responsible.
 *
 * Three rules this class exists to enforce:
 *
 *  1. NOTHING IS EDITED. A posted charge is only ever reversed or adjusted, and
 *     both the original and the decision survive. `reverse()` writes a status
 *     and an adjustment row; it never deletes.
 *  2. POSTING TWICE IS HARMLESS. Every module charge carries a deterministic
 *     `dedupe_key`, so a retry, a crash mid-request or a double-clicked button
 *     cannot charge a patient twice — the unique index refuses the second write
 *     and the caller is handed the row that already exists.
 *  3. THE TAX DECISION IS STAMPED AT POST TIME. It is not recomputed when the
 *     bill prints, because the rulebook may have changed by then and a printed
 *     receipt must stay reproducible.
 */
class HealthChargeService
{
    /**
     * Post one charge onto the ledger.
     *
     * `dedupe_key` is what makes this safe to call from a retrying job. When the
     * key already exists the EXISTING row is returned untouched — deliberately
     * not updated, because "already charged" is the outcome the caller wanted
     * and quietly rewriting a posted charge is the one thing a ledger must not
     * do.
     */
    public static function post(array $data, $actor = null): ?HealthCharge
    {
        if (!Schema::hasTable('health_charges')) {
            return null;
        }

        $companyId = (int) ($data['company_id'] ?? 0);
        $patientId = (int) ($data['health_patient_id'] ?? 0);
        if ($companyId <= 0 || $patientId <= 0) {
            return null;
        }

        $dedupeKey = $data['dedupe_key'] ?? null;
        if ($dedupeKey) {
            $existing = HealthCharge::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('dedupe_key', $dedupeKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $category = in_array(($data['category'] ?? null), HealthCharge::CATEGORIES, true)
            ? $data['category']
            : HealthCharge::CAT_SERVICE;

        $quantity = round((float) ($data['quantity'] ?? 1), 3);
        if ($quantity <= 0) {
            $quantity = 1;
        }
        $unitPrice = round((float) ($data['unit_price'] ?? 0), 2);

        // Gross may be handed in directly (a pharmacy sale already agreed its
        // own total with the customer) — re-deriving it from unit x qty would
        // shift the number the patient was shown by a rounding paisa.
        $gross = array_key_exists('gross_amount', $data)
            ? round((float) $data['gross_amount'], 2)
            : round($unitPrice * $quantity, 2);

        $concession = round((float) ($data['concession_amount'] ?? 0), 2);
        if ($concession < 0) {
            $concession = 0;
        }
        if ($concession > $gross) {
            $concession = $gross;
        }
        $net = round($gross - $concession, 2);

        // ── The regulatory decision, taken once, here ──
        $tax = HealthTaxService::resolve($companyId, $category, $data['health_tax_category_id'] ?? null);
        $treatment = $tax['treatment'];
        $rate = $tax['rate'];

        // A caller may hand in tax it already charged (the pharmacy sale did its
        // own maths at the counter). Honour it rather than re-deriving, but only
        // when the treatment actually reports — a local charge carries no tax by
        // definition, whatever the caller thinks.
        if (array_key_exists('tax_amount', $data) && $treatment === HealthTaxCategory::TREATMENT_FBR) {
            $taxAmount = round((float) $data['tax_amount'], 2);
            if (($data['tax_rate'] ?? null) !== null) {
                $rate = round((float) $data['tax_rate'], 2);
            }
        } else {
            $taxAmount = HealthTaxService::taxFor($treatment, $rate, $net);
        }

        $row = [
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'] ?? null,
            'health_department_id' => $data['health_department_id'] ?? null,
            'health_patient_id' => $patientId,
            'health_visit_id' => $data['health_visit_id'] ?? null,
            'health_admission_id' => $data['health_admission_id'] ?? null,
            'charge_date' => $data['charge_date'] ?? now()->toDateString(),
            'category' => $category,
            'description' => mb_substr((string) ($data['description'] ?? '-'), 0, 300),
            'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 120) : null,
            'source_type' => $data['source_type'] ?? HealthCharge::SOURCE_MANUAL,
            'source_id' => $data['source_id'] ?? null,
            'source_reference' => isset($data['source_reference'])
                ? mb_substr((string) $data['source_reference'], 0, 120)
                : null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'gross_amount' => $gross,
            'concession_amount' => $concession,
            'concession_reason' => isset($data['concession_reason'])
                ? mb_substr((string) $data['concession_reason'], 0, 300)
                : null,
            'concession_approved_by' => $concession > 0 ? ($data['concession_approved_by'] ?? ($actor->id ?? null)) : null,
            'net_amount' => $net,
            'health_tax_category_id' => $tax['category_id'],
            'tax_treatment' => $treatment,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'total_amount' => round($net + $taxAmount, 2),
            'pct_code' => $tax['pct_code'],
            'status' => HealthCharge::STATUS_POSTED,
            'dedupe_key' => $dedupeKey,
            'created_by' => $data['created_by'] ?? ($actor->id ?? null),
        ];

        $run = function () use ($companyId, $row) {
            $row['charge_no'] = HealthNumberService::chargeNumber($companyId);

            return HealthCharge::withoutGlobalScopes()->create($row);
        };

        try {
            return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
        } catch (\Throwable $e) {
            // The unique index doing its job under a race: another request beat
            // us to the same dedupe key. That is success, not failure — hand
            // back the row that won.
            if ($dedupeKey) {
                $existing = HealthCharge::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('dedupe_key', $dedupeKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            Log::warning('health.charge.post_failed', [
                'company_id' => $companyId,
                'dedupe_key' => $dedupeKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reverse a charge that should never have been posted.
     *
     * Refused once the charge sits on a finalized bill. That is not a technical
     * limitation — a bill that was printed, possibly paid and possibly filed
     * with the regulator cannot be un-happened by editing the ledger behind it.
     * The correction path there is a refund or a credit, both of which leave the
     * original visible.
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function reverse(HealthCharge $charge, $actor = null, string $reason = ''): array
    {
        if ($charge->status === HealthCharge::STATUS_REVERSED) {
            return ['ok' => true, 'reason' => 'already_reversed'];
        }

        if ($charge->isLocked()) {
            return ['ok' => false, 'reason' => 'locked_by_final_bill'];
        }

        if ($charge->health_bill_id) {
            $bill = \App\Models\HealthBill::withoutGlobalScopes()->find($charge->health_bill_id);
            if ($bill && $bill->isFinal()) {
                return ['ok' => false, 'reason' => 'locked_by_final_bill'];
            }
        }

        DB::transaction(function () use ($charge, $actor, $reason) {
            $charge->forceFill([
                'status' => HealthCharge::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actor->id ?? null,
                'reversal_reason' => $reason ? mb_substr($reason, 0, 300) : null,
                // A reversed charge stops being a candidate for any bill, and
                // releases the draft bill it was sitting on.
                'health_bill_id' => null,
                'billed_at' => null,
            ])->save();

            self::adjust($charge, HealthChargeAdjustment::KIND_REVERSAL, (float) $charge->total_amount, $reason, $actor);
        });

        return ['ok' => true];
    }

    /**
     * Grant or change a concession on an unbilled charge.
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function applyConcession(HealthCharge $charge, float $amount, string $reason, $actor = null): array
    {
        // Same ordering as reclassify(): a billed charge fails both tests, and
        // the lock is the reason worth telling the counter about.
        if ($charge->isLocked()) {
            return ['ok' => false, 'reason' => 'locked_by_final_bill'];
        }
        if ($charge->status !== HealthCharge::STATUS_POSTED) {
            return ['ok' => false, 'reason' => 'not_posted'];
        }

        $amount = round(max(0, $amount), 2);
        $gross = round((float) $charge->gross_amount, 2);
        if ($amount > $gross) {
            return ['ok' => false, 'reason' => 'exceeds_gross'];
        }

        $before = round((float) $charge->concession_amount, 2);
        $net = round($gross - $amount, 2);
        $taxAmount = HealthTaxService::taxFor($charge->tax_treatment, (float) $charge->tax_rate, $net);

        DB::transaction(function () use ($charge, $amount, $net, $taxAmount, $reason, $actor, $before) {
            $charge->forceFill([
                'concession_amount' => $amount,
                'concession_reason' => $reason ? mb_substr($reason, 0, 300) : null,
                'concession_approved_by' => $actor->id ?? null,
                'net_amount' => $net,
                'tax_amount' => $taxAmount,
                'total_amount' => round($net + $taxAmount, 2),
            ])->save();

            self::adjust(
                $charge,
                HealthChargeAdjustment::KIND_CONCESSION,
                round($amount - $before, 2),
                $reason,
                $actor,
                number_format($before, 2, '.', ''),
                number_format($amount, 2, '.', '')
            );
        });

        return ['ok' => true];
    }

    /**
     * Move a charge onto a different tax rule.
     *
     * This is the ONE operation that changes what the regulator is told without
     * changing any money, so it is the one that most needs a witness. It is
     * refused outright once the charge is locked by a finalized bill, and it
     * always writes a reclassify adjustment naming the treatment before and
     * after. That pair of rules is what "cannot silently switch treatment"
     * actually means in code.
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function reclassify(HealthCharge $charge, $taxCategoryId, $actor = null, string $reason = ''): array
    {
        // Locked is checked FIRST on purpose. A billed charge fails both tests,
        // and "this sits on a finalized bill" tells the counter what actually
        // happened; "not posted" would send them looking for a ledger fault.
        if ($charge->isLocked()) {
            return ['ok' => false, 'reason' => 'locked_by_final_bill'];
        }
        if ($charge->status !== HealthCharge::STATUS_POSTED) {
            return ['ok' => false, 'reason' => 'not_posted'];
        }

        $resolved = HealthTaxService::resolve((int) $charge->company_id, $charge->category, $taxCategoryId);
        $fromTreatment = (string) $charge->tax_treatment;
        $fromRate = round((float) $charge->tax_rate, 2);

        if ($fromTreatment === $resolved['treatment']
            && $fromRate === $resolved['rate']
            && (int) $charge->health_tax_category_id === (int) ($resolved['category_id'] ?? 0)) {
            return ['ok' => true, 'reason' => 'unchanged'];
        }

        $net = round((float) $charge->net_amount, 2);
        $taxAmount = HealthTaxService::taxFor($resolved['treatment'], $resolved['rate'], $net);

        DB::transaction(function () use ($charge, $resolved, $net, $taxAmount, $actor, $reason, $fromTreatment, $fromRate) {
            $charge->forceFill([
                'health_tax_category_id' => $resolved['category_id'],
                'tax_treatment' => $resolved['treatment'],
                'tax_rate' => $resolved['rate'],
                'tax_amount' => $taxAmount,
                'total_amount' => round($net + $taxAmount, 2),
                'pct_code' => $resolved['pct_code'],
            ])->save();

            self::adjust(
                $charge,
                HealthChargeAdjustment::KIND_RECLASSIFY,
                0,
                $reason,
                $actor,
                $fromTreatment . ' @ ' . number_format($fromRate, 2, '.', '') . '%',
                $resolved['treatment'] . ' @ ' . number_format($resolved['rate'], 2, '.', '') . '%'
            );
        });

        return ['ok' => true];
    }

    /** Append one immutable decision row against a charge. */
    public static function adjust(
        HealthCharge $charge,
        string $kind,
        float $amount = 0,
        string $reason = '',
        $actor = null,
        ?string $from = null,
        ?string $to = null
    ): ?HealthChargeAdjustment {
        if (!Schema::hasTable('health_charge_adjustments')) {
            return null;
        }

        return HealthChargeAdjustment::withoutGlobalScopes()->create([
            'company_id' => $charge->company_id,
            'health_charge_id' => $charge->id,
            'kind' => in_array($kind, HealthChargeAdjustment::KINDS, true)
                ? $kind
                : HealthChargeAdjustment::KIND_CORRECTION,
            'amount' => round($amount, 2),
            'from_value' => $from ? mb_substr($from, 0, 120) : null,
            'to_value' => $to ? mb_substr($to, 0, 120) : null,
            'reason' => $reason ? mb_substr($reason, 0, 300) : null,
            'approved_by' => $actor->id ?? null,
            'created_by' => $actor->id ?? null,
            // Frozen: the staff member may leave, the decision stays attributed.
            'actor_name' => $actor->name ?? null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Everything a patient owes that no bill has claimed yet.
     *
     * @param  array{department_id?:int|null,admission_id?:int|null,category?:string|null,from?:string|null,to?:string|null}  $filters
     */
    public static function unbilled(int $companyId, int $patientId, array $filters = [])
    {
        if (!Schema::hasTable('health_charges')) {
            return collect();
        }

        $q = HealthCharge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_patient_id', $patientId)
            ->unbilled();

        if (!empty($filters['department_id'])) {
            $q->where('health_department_id', (int) $filters['department_id']);
        }
        if (!empty($filters['admission_id'])) {
            $q->where('health_admission_id', (int) $filters['admission_id']);
        }
        if (!empty($filters['visit_id'])) {
            $q->where('health_visit_id', (int) $filters['visit_id']);
        }
        if (!empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }
        if (!empty($filters['from'])) {
            $q->whereDate('charge_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate('charge_date', '<=', $filters['to']);
        }

        return $q->orderBy('charge_date')->orderBy('id')->get();
    }

    /**
     * Totals for a set of charges, split by regulatory treatment.
     *
     * The split is returned separately from the grand total on purpose: a bill
     * has to be able to SHOW which money is local, which is exempt and which is
     * reported, and a screen that only ever sees one merged figure cannot.
     *
     * @param  iterable<HealthCharge|\App\Models\HealthBillLine>  $rows
     */
    public static function totals(iterable $rows): array
    {
        $out = [
            'gross' => 0.0,
            'concession' => 0.0,
            'net' => 0.0,
            'tax' => 0.0,
            'total' => 0.0,
            'count' => 0,
            'by_treatment' => [
                HealthTaxCategory::TREATMENT_LOCAL => 0.0,
                HealthTaxCategory::TREATMENT_EXEMPT => 0.0,
                HealthTaxCategory::TREATMENT_FBR => 0.0,
            ],
        ];

        foreach ($rows as $r) {
            $out['gross'] += (float) $r->gross_amount;
            $out['concession'] += (float) $r->concession_amount;
            $out['net'] += (float) $r->net_amount;
            $out['tax'] += (float) $r->tax_amount;
            $out['total'] += (float) $r->total_amount;
            $out['count']++;

            $t = in_array($r->tax_treatment, HealthTaxCategory::TREATMENTS, true)
                ? $r->tax_treatment
                : HealthTaxCategory::TREATMENT_LOCAL;
            $out['by_treatment'][$t] += (float) $r->total_amount;
        }

        foreach (['gross', 'concession', 'net', 'tax', 'total'] as $k) {
            $out[$k] = round($out[$k], 2);
        }
        foreach ($out['by_treatment'] as $k => $v) {
            $out['by_treatment'][$k] = round($v, 2);
        }

        return $out;
    }
}
