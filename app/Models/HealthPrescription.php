<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ONE prescription row, shared by the consultation room and the pharmacy.
 *
 * Two states live here on purpose, and they are not the same thing:
 *
 *   `status`          the doctor's state — draft until it is issued. Written by
 *                     OPD, never by the pharmacy.
 *   `dispense_status` the pharmacy's state — pending, partial, dispensed or
 *                     cancelled. Written by the pharmacy, never by OPD.
 *
 * Collapsing them into one column would mean a doctor's slip taken to an
 * outside chemist reads as "dispensed" here, or a pharmacy fill silently
 * rewriting the doctor's record. Both are wrong, so both columns stay.
 *
 * The patient may arrive either way: a registered patient (health_patient_id)
 * for an OPD prescription, or a name snapshot typed at the counter for a slip
 * written elsewhere. `patient_display_name` reads whichever exists.
 */
class HealthPrescription extends Model
{
    /* ── Doctor's state (OPD owns these) ─────────────────────────────────── */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED];

    /* ── Pharmacy fulfilment state (this module owns these) ──────────────── */
    public const DISPENSE_PENDING = 'pending';
    public const DISPENSE_PARTIAL = 'partial';
    public const DISPENSE_DISPENSED = 'dispensed';
    public const DISPENSE_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_visit_id',
        'health_patient_id',
        'health_doctor_id',
        'health_department_id',
        'prescription_no',
        'status',
        'general_instructions',
        'valid_until',
        'issued_at',
        'created_by',

        // Counter intake snapshot + pharmacy fulfilment.
        'patient_name',
        'patient_mr_no',
        'patient_phone',
        'patient_age',
        'patient_gender',
        'doctor_name',
        'prescribed_on',
        'dispense_status',
        'completed_at',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'issued_at' => 'datetime',
        'prescribed_on' => 'date',
        'completed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_visit_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_department_id' => 'integer',
        'created_by' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function items()
    {
        return $this->hasMany(HealthPrescriptionItem::class, 'health_prescription_id')->orderBy('line_no');
    }

    public function sales()
    {
        return $this->hasMany(HealthPharmacySale::class, 'prescription_id');
    }

    public function visit()
    {
        return $this->belongsTo(HealthVisit::class, 'health_visit_id');
    }

    public function patient()
    {
        return $this->belongsTo(HealthPatient::class, 'health_patient_id');
    }

    public function doctor()
    {
        return $this->belongsTo(HealthDoctor::class, 'health_doctor_id');
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /** Still owed medicine — the pharmacy queue is exactly this set. */
    public function isOpen(): bool
    {
        return in_array($this->dispense_status, [self::DISPENSE_PENDING, self::DISPENSE_PARTIAL], true);
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.presc_status_' . (in_array($status, self::STATUSES, true) ? $status : 'draft');
    }

    public static function dispenseStatusLabelKey(?string $status): string
    {
        return 'health.rx_status_' . ($status ?: self::DISPENSE_PENDING);
    }

    /**
     * The name the counter reads. A registered patient wins over the snapshot —
     * the registry is the corrected spelling, the snapshot is what someone typed
     * in a hurry.
     *
     * Reads the `patient` relation, so every pharmacy query that renders this
     * must eager-load it (production throws on lazy loads).
     */
    public function getPatientDisplayNameAttribute(): string
    {
        if ($this->relationLoaded('patient') && $this->patient) {
            return (string) $this->patient->name;
        }

        return (string) ($this->patient_name ?: '');
    }

    /** Same rule for the prescriber: our own doctor first, then the snapshot. */
    public function getDoctorDisplayNameAttribute(): string
    {
        if ($this->relationLoaded('doctor') && $this->doctor) {
            return (string) $this->doctor->name;
        }

        return (string) ($this->doctor_name ?: '');
    }

    /** MR number: registry first, snapshot second. */
    public function getPatientDisplayMrAttribute(): string
    {
        if ($this->relationLoaded('patient') && $this->patient) {
            return (string) ($this->patient->mrn ?? '');
        }

        return (string) ($this->patient_mr_no ?: '');
    }
}
