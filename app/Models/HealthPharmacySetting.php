<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-company pharmacy policy — the expiry window, what is refused outright,
 * and the counter defaults. Read through HealthPharmacyService::settings() so
 * a company that never opened the settings screen still gets the safe defaults.
 */
class HealthPharmacySetting extends Model
{
    protected $table = 'health_pharmacy_settings';

    protected $fillable = [
        'company_id',
        'near_expiry_days',
        'block_expired_dispense',
        'warn_short_dated',
        'require_prescription_for_controlled',
        'allow_negative_stock',
        'default_tax_rate',
        'low_stock_threshold',
        'sale_prefix',
    ];

    protected $casts = [
        'near_expiry_days' => 'integer',
        'block_expired_dispense' => 'boolean',
        'warn_short_dated' => 'boolean',
        'require_prescription_for_controlled' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'default_tax_rate' => 'float',
        'low_stock_threshold' => 'float',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }
}
