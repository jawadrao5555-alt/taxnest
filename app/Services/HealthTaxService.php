<?php

namespace App\Services;

use App\Models\HealthCharge;
use App\Models\HealthTaxCategory;
use Illuminate\Support\Facades\Schema;

/**
 * The regulatory classifier (Task 1551, step 3).
 *
 * Its whole job is to answer ONE question for a charge: is this money local,
 * exempt, or reported to FBR — and if reported, at what rate and under which
 * PCT code. It answers from the hospital's own configured rulebook and from
 * nowhere else.
 *
 * The rule of last resort is LOCAL at 0%. That is not laziness, it is the only
 * safe default: filing something with the regulator that the hospital never
 * agreed to file cannot be taken back, while a charge left local can be
 * reclassified the moment the accountant configures the rule — right up until
 * the bill is finalized, at which point the decision freezes for good.
 *
 * Deciding the legal treatment is explicitly the hospital's and its
 * accountant's job. This class only makes their decision consistent.
 */
class HealthTaxService
{
    /**
     * Resolve the treatment for one charge.
     *
     * Priority, highest first:
     *   1. an explicitly chosen category (the counter picked it, or a module
     *      passed one it is sure about)
     *   2. an active rule whose `applies_to` list names this charge category
     *   3. the hospital's default rule
     *   4. local at 0%
     *
     * @return array{category_id:?int,treatment:string,rate:float,pct_code:?string,source:string}
     */
    public static function resolve(int $companyId, ?string $chargeCategory, $explicitCategoryId = null): array
    {
        $fallback = [
            'category_id' => null,
            'treatment' => HealthTaxCategory::TREATMENT_LOCAL,
            'rate' => 0.0,
            'pct_code' => null,
            'source' => 'fallback',
        ];

        if (!Schema::hasTable('health_tax_categories')) {
            return $fallback;
        }

        if ($explicitCategoryId) {
            $explicit = HealthTaxCategory::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', (int) $explicitCategoryId)
                ->first();

            // An inactive rule chosen ON PURPOSE is still honoured: deactivating
            // a rule stops it being suggested, it does not retroactively deny a
            // charge somebody deliberately classified under it.
            if ($explicit) {
                return self::shape($explicit, 'explicit');
            }
        }

        $rules = self::rules($companyId);

        foreach ($rules as $rule) {
            if ($rule->covers($chargeCategory)) {
                return self::shape($rule, 'matched');
            }
        }

        foreach ($rules as $rule) {
            if ($rule->is_default) {
                return self::shape($rule, 'default');
            }
        }

        return $fallback;
    }

    /** Active rules for a company, cheapest-first ordering for a stable match. */
    public static function rules(int $companyId)
    {
        if (!Schema::hasTable('health_tax_categories')) {
            return collect();
        }

        return HealthTaxCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /** Every rule including retired ones — the settings screen needs all of them. */
    public static function allRules(int $companyId)
    {
        if (!Schema::hasTable('health_tax_categories')) {
            return collect();
        }

        return HealthTaxCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Seed a starter rulebook for a hospital that has none.
     *
     * Everything lands as LOCAL. That is the point — the hospital gets rows it
     * can edit instead of a blank screen, without the software having quietly
     * decided that anything is taxable on its behalf.
     */
    public static function seedDefaults(int $companyId, ?int $userId = null): int
    {
        if (!Schema::hasTable('health_tax_categories')) {
            return 0;
        }

        if (HealthTaxCategory::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return 0;
        }

        $seed = [
            [
                'name' => 'Hospital Services (Local)',
                'code' => 'LOCAL',
                'treatment' => HealthTaxCategory::TREATMENT_LOCAL,
                'applies_to' => [
                    HealthCharge::CAT_OPD,
                    HealthCharge::CAT_ROOM,
                    HealthCharge::CAT_NURSING,
                    HealthCharge::CAT_DOCTOR,
                    HealthCharge::CAT_SERVICE,
                    HealthCharge::CAT_MISC,
                ],
                'is_default' => true,
            ],
            [
                'name' => 'Diagnostics (Local)',
                'code' => 'DIAG',
                'treatment' => HealthTaxCategory::TREATMENT_LOCAL,
                'applies_to' => [
                    HealthCharge::CAT_LAB,
                    HealthCharge::CAT_INVESTIGATION,
                    HealthCharge::CAT_PROCEDURE,
                    HealthCharge::CAT_OPERATION,
                ],
                'is_default' => false,
            ],
            [
                'name' => 'Pharmacy Goods (Local)',
                'code' => 'PHARMA',
                'treatment' => HealthTaxCategory::TREATMENT_LOCAL,
                'applies_to' => [
                    HealthCharge::CAT_PHARMACY,
                    HealthCharge::CAT_CONSUMABLE,
                ],
                'is_default' => false,
            ],
        ];

        $made = 0;
        foreach ($seed as $row) {
            HealthTaxCategory::withoutGlobalScopes()->create(array_merge($row, [
                'company_id' => $companyId,
                'tax_rate' => 0,
                'is_active' => true,
                'created_by' => $userId,
                'notes' => null,
            ]));
            $made++;
        }

        return $made;
    }

    /** Tax on a taxable value. Only an FBR-treated charge ever carries any. */
    public static function taxFor(string $treatment, float $rate, float $netAmount): float
    {
        if ($treatment !== HealthTaxCategory::TREATMENT_FBR || $rate <= 0) {
            return 0.0;
        }

        return round($netAmount * ($rate / 100), 2);
    }

    private static function shape(HealthTaxCategory $rule, string $source): array
    {
        return [
            'category_id' => (int) $rule->id,
            'treatment' => in_array($rule->treatment, HealthTaxCategory::TREATMENTS, true)
                ? $rule->treatment
                : HealthTaxCategory::TREATMENT_LOCAL,
            'rate' => $rule->effectiveRate(),
            'pct_code' => $rule->pct_code ?: null,
            'source' => $source,
        ];
    }
}
