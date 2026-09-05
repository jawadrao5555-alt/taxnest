<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The OPD encounter.
 *
 * Opened by reception at check-in, finished by the doctor. Vitals, the clinical
 * record, the consultation fee and the follow-up all live on this one row so
 * later modules have a single encounter to point at: the pharmacy dispenses
 * against its prescription, billing charges against its fee, IPD admits from
 * it. None of them has to re-derive who saw whom.
 *
 * The fee is stored as three separate numbers — gross, concession, net — and
 * never as "the amount we took". A concession is a decision somebody made and
 * has to survive in the record; collapsing it into one discounted figure loses
 * both the list price and the reason.
 */
class HealthVisit extends Model
{
    public const TYPE_NEW = 'new';
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_EMERGENCY = 'emergency';
    public const TYPES = [self::TYPE_NEW, self::TYPE_FOLLOW_UP, self::TYPE_EMERGENCY];

    public const STATUS_WAITING = 'waiting';
    public const STATUS_IN_CONSULTATION = 'in_consultation';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_IN_CONSULTATION,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const FEE_PENDING = 'pending';
    public const FEE_PAID = 'paid';
    public const FEE_WAIVED = 'waived';
    public const FEE_STATUSES = [self::FEE_PENDING, self::FEE_PAID, self::FEE_WAIVED];

    public const PAYMENT_METHODS = ['cash', 'card', 'online', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_patient_id',
        'health_doctor_id',
        'health_appointment_id',
        'visit_no',
        'visit_date',
        'visit_type',
        'status',
        'temperature_c',
        'pulse_bpm',
        'respiratory_rate',
        'bp_systolic',
        'bp_diastolic',
        'spo2',
        'weight_kg',
        'height_cm',
        'blood_sugar',
        'vitals_recorded_by',
        'vitals_recorded_at',
        'chief_complaint',
        'history',
        'examination',
        'diagnosis',
        'procedures',
        'advice',
        'clinical_notes',
        'follow_up_date',
        'follow_up_notes',
        'fee_amount',
        'concession_amount',
        'concession_reason',
        'net_fee',
        'fee_status',
        'payment_method',
        'fee_collected_by',
        'fee_collected_at',
        'opened_by',
        'closed_by',
        'consultation_started_at',
        'closed_at',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'follow_up_date' => 'date',
        'vitals_recorded_at' => 'datetime',
        'fee_collected_at' => 'datetime',
        'consultation_started_at' => 'datetime',
        'closed_at' => 'datetime',
        'temperature_c' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'blood_sugar' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'net_fee' => 'decimal:2',
        'pulse_bpm' => 'integer',
        'respiratory_rate' => 'integer',
        'bp_systolic' => 'integer',
        'bp_diastolic' => 'integer',
        'spo2' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_appointment_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
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

    public function appointment()
    {
        return $this->belongsTo(HealthAppointment::class, 'health_appointment_id');
    }

    public function attachments()
    {
        return $this->hasMany(HealthVisitAttachment::class, 'health_visit_id');
    }

    public function prescriptions()
    {
        return $this->hasMany(HealthPrescription::class, 'health_visit_id');
    }

    /** Any vitals at all recorded? Used to keep the card off an empty screen. */
    public function hasVitals(): bool
    {
        foreach (['temperature_c', 'pulse_bpm', 'respiratory_rate', 'bp_systolic',
                  'bp_diastolic', 'spo2', 'weight_kg', 'height_cm', 'blood_sugar'] as $field) {
            if ($this->{$field} !== null) {
                return true;
            }
        }

        return false;
    }

    /** Any clinical text at all? Distinguishes "seen" from "opened and left". */
    public function hasClinicalRecord(): bool
    {
        foreach (['chief_complaint', 'history', 'examination', 'diagnosis',
                  'procedures', 'advice', 'clinical_notes'] as $field) {
            if (trim((string) $this->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * BMI, computed and never stored.
     *
     * Height and weight can both be edited afterwards; a stored BMI would go
     * stale the moment either changes, and a stale clinical number is worse
     * than none.
     */
    public function getBmiAttribute(): ?float
    {
        $h = (float) $this->height_cm;
        $w = (float) $this->weight_kg;
        if ($h <= 0 || $w <= 0) {
            return null;
        }

        return round($w / (($h / 100) ** 2), 1);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.visit_type_' . (in_array($type, self::TYPES, true) ? $type : 'new');
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.visit_status_' . (in_array($status, self::STATUSES, true) ? $status : 'waiting');
    }

    public static function feeStatusLabelKey(?string $status): string
    {
        return 'health.fee_status_' . (in_array($status, self::FEE_STATUSES, true) ? $status : 'pending');
    }

    public static function statusClasses(?string $status): string
    {
        return match ($status) {
            self::STATUS_IN_CONSULTATION => 'bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200',
            self::STATUS_COMPLETED => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
            self::STATUS_CANCELLED => 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
            default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
        };
    }
}
