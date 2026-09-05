<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A medicine in the pharmacy catalogue (Task 1549).
 *
 * A medicine OWNS a shared `products` row (product_id). That row is what the
 * platform's inventory, purchase-order and fiscal services already understand,
 * so nothing here re-implements stock. Everything medicine-specific — strength,
 * form, pack conversion, controlled-sale flags, substitutes — lives on this row.
 */
class HealthMedicine extends Model
{
    /** Dosage forms a pharmacy actually stocks. */
    public const FORMS = [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'infusion',
        'drops', 'ointment', 'cream', 'inhaler', 'suppository', 'sachet',
        'patch', 'device', 'other',
    ];

    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'generic_name',
        'strength',
        'form',
        'manufacturer',
        'category',
        'code',
        'barcode',
        'unit_uom',
        'pack_uom',
        'pack_size',
        'purchase_price',
        'sale_price',
        'tax_rate',
        'hs_code',
        'uom_code',
        'requires_prescription',
        'is_controlled',
        'is_narcotic',
        'is_refrigerated',
        'reorder_level',
        'max_level',
        'default_dosage',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'pack_size' => 'float',
        'purchase_price' => 'float',
        'sale_price' => 'float',
        'tax_rate' => 'float',
        'reorder_level' => 'float',
        'max_level' => 'float',
        'requires_prescription' => 'boolean',
        'is_controlled' => 'boolean',
        'is_narcotic' => 'boolean',
        'is_refrigerated' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public static function isForm(?string $form): bool
    {
        return in_array($form, self::FORMS, true);
    }

    public static function formLabelKey(?string $form): string
    {
        return 'health.form_' . (self::isForm($form) ? $form : 'other');
    }

    /** "Panadol 500mg Tablet" — one label the whole module prints. */
    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->name,
            $this->strength,
        ]);

        return trim(implode(' ', $parts));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batches()
    {
        return $this->hasMany(HealthMedicineBatch::class, 'medicine_id');
    }

    public function substitutes()
    {
        return $this->belongsToMany(
            self::class,
            'health_medicine_substitutes',
            'medicine_id',
            'substitute_id'
        )->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
