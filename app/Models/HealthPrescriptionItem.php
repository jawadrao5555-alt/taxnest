<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One medicine line.
 *
 * Structured rather than free text: the pharmacy module has to be able to read
 * a row and know WHICH medicine, at what dose, by what route, how often and for
 * how many days. A prescription stored as a paragraph can be printed but never
 * fulfilled, checked for interactions, or counted.
 */
class HealthPrescriptionItem extends Model
{
    /** Common dosage forms. Free text is still accepted — this drives the picker. */
    public const FORMS = ['tablet', 'capsule', 'syrup', 'suspension', 'injection', 'drops', 'inhaler', 'cream', 'other'];

    /** Routes of administration. */
    public const ROUTES = ['oral', 'iv', 'im', 'sc', 'topical', 'inhaled', 'rectal', 'ophthalmic', 'nasal', 'other'];

    protected $fillable = [
        'company_id',
        'health_prescription_id',
        'line_no',
        'medicine_name',
        'generic_name',
        'strength',
        'form',
        'dose',
        'route',
        'frequency',
        'duration_days',
        'quantity',
        'instructions',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'duration_days' => 'integer',
        'quantity' => 'decimal:2',
        'company_id' => 'integer',
        'health_prescription_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function prescription()
    {
        return $this->belongsTo(HealthPrescription::class, 'health_prescription_id');
    }

    public static function formLabelKey(?string $form): string
    {
        return 'health.form_' . (in_array($form, self::FORMS, true) ? $form : 'other');
    }

    public static function routeLabelKey(?string $route): string
    {
        return 'health.route_' . (in_array($route, self::ROUTES, true) ? $route : 'other');
    }

    /** "Tab. Panadol 500mg" — the line a pharmacist actually reads. */
    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->medicine_name,
            $this->strength,
        ]);

        return implode(' ', $parts);
    }
}
