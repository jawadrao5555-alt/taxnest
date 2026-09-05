<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The prescription header written during a consultation.
 *
 * `status` stops at `issued` on purpose. Dispensing is the pharmacy's state,
 * not the doctor's, and the pharmacy module owns it — an OPD table that
 * pretended to know whether medicine had actually been handed over would be
 * wrong the moment a patient took the slip to an outside chemist.
 */
class HealthPrescription extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_visit_id',
        'health_patient_id',
        'health_doctor_id',
        'prescription_no',
        'status',
        'general_instructions',
        'valid_until',
        'issued_at',
        'created_by',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'issued_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_visit_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_doctor_id' => 'integer',
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

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.presc_status_' . (in_array($status, self::STATUSES, true) ? $status : 'draft');
    }
}
