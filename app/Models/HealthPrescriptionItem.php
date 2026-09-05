<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One medicine line, written by the doctor and fulfilled by the pharmacy.
 *
 * Structured rather than free text: the pharmacy has to read a row and know
 * WHICH medicine, at what dose, by what route, how often and how many to hand
 * over. A prescription stored as a paragraph can be printed but never
 * fulfilled, substituted, or counted.
 *
 * `medicine_id` links the line to our own catalogue when the pharmacy stocks
 * it. It stays nullable on purpose — a doctor may prescribe something we do not
 * carry, and the name snapshot is still what the counter reads.
 *
 * `dispensed_quantity` only ever grows, so the remaining quantity is a derived
 * number no screen recomputes differently. That is what makes a partial fill
 * honest across visits.
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
        'medicine_id',
        'medicine_name',
        'generic_name',
        'strength',
        'form',
        'dose',
        'route',
        'frequency',
        'duration_days',
        'duration',
        'quantity',
        'dispensed_quantity',
        'instructions',
        'is_cancelled',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'duration_days' => 'integer',
        'quantity' => 'float',
        'dispensed_quantity' => 'float',
        'is_cancelled' => 'boolean',
        'company_id' => 'integer',
        'health_prescription_id' => 'integer',
        'medicine_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function prescription()
    {
        return $this->belongsTo(HealthPrescription::class, 'health_prescription_id');
    }

    public function medicine()
    {
        return $this->belongsTo(HealthMedicine::class, 'medicine_id');
    }

    public static function formLabelKey(?string $form): string
    {
        return 'health.form_' . (in_array($form, self::FORMS, true) ? $form : 'other');
    }

    public static function routeLabelKey(?string $route): string
    {
        return 'health.route_' . (in_array($route, self::ROUTES, true) ? $route : 'other');
    }

    /** "Panadol 500mg" — the line a pharmacist actually reads. */
    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->medicine_name,
            $this->strength,
        ]);

        return implode(' ', $parts);
    }

    /**
     * A doctor writes "7 days" as a number of days; the counter often types it
     * as free text on a slip from outside. Either is shown, never both.
     */
    public function getDurationLabelAttribute(): string
    {
        if (trim((string) $this->duration) !== '') {
            return (string) $this->duration;
        }

        return $this->duration_days
            ? __('health.rx_days_short', ['count' => (int) $this->duration_days])
            : '';
    }

    /** Never negative: an over-dispense (substitute, replacement) reads as 0 left. */
    public function getRemainingQuantityAttribute(): float
    {
        if ($this->is_cancelled) {
            return 0.0;
        }

        return max(0, round((float) $this->quantity - (float) $this->dispensed_quantity, 3));
    }
}
