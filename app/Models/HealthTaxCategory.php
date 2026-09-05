<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the hospital's own tax rulebook (Task 1551).
 *
 * The software never decides legal tax treatment. It only records the decision
 * the hospital's accountant already made, and then applies it consistently.
 * Three outcomes exist and no fourth is representable:
 *
 *   local   internal money — a ward charge, a deposit, an in-house service.
 *           Nothing about it goes to the regulator.
 *   exempt  a real sale the hospital is not charging tax on. It appears on the
 *           bill at 0% and stays out of the FBR payload.
 *   fbr     reported. It carries a rate, an optional PCT code, and it is what
 *           the fiscalization adapter sends.
 *
 * A charge that matches no rule is LOCAL at 0%. That is deliberate: filing
 * something the hospital never agreed to file cannot be undone, while failing
 * to file something can be corrected the moment the rulebook is configured.
 */
class HealthTaxCategory extends Model
{
    public const TREATMENT_LOCAL = 'local';
    public const TREATMENT_EXEMPT = 'exempt';
    public const TREATMENT_FBR = 'fbr';

    public const TREATMENTS = [
        self::TREATMENT_LOCAL,
        self::TREATMENT_EXEMPT,
        self::TREATMENT_FBR,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'treatment',
        'tax_rate',
        'pct_code',
        'sro_reference',
        'applies_to',
        'is_default',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'applies_to' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'company_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function treatmentLabelKey(?string $treatment): string
    {
        return 'health.tax_treatment_' . (in_array($treatment, self::TREATMENTS, true) ? $treatment : self::TREATMENT_LOCAL);
    }

    /** Does this rule cover the given charge category? */
    public function covers(?string $chargeCategory): bool
    {
        if (!$chargeCategory) {
            return false;
        }

        $list = $this->applies_to;

        return is_array($list) && in_array($chargeCategory, $list, true);
    }

    /** Only an FBR rule may carry a non-zero rate onto a bill. */
    public function effectiveRate(): float
    {
        return $this->treatment === self::TREATMENT_FBR ? round((float) $this->tax_rate, 2) : 0.0;
    }
}
