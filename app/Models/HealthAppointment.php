<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The queue record: a booked appointment, a walk-in token, or both.
 *
 * A walk-in is NOT a second table. Reception hands out a token for today and
 * the same row is created already checked in, which is why one status machine
 * covers the whole desk:
 *
 *     booked → checked_in → in_consultation → completed
 *                  ↘ cancelled          ↘ no_show
 *
 * Every dead end stamps its own timestamp, so "why did this patient never get
 * seen" is answerable from the row itself rather than from a log nobody keeps.
 */
class HealthAppointment extends Model
{
    public const KIND_SCHEDULED = 'scheduled';
    public const KIND_WALKIN = 'walkin';
    public const KINDS = [self::KIND_SCHEDULED, self::KIND_WALKIN];

    public const STATUS_BOOKED = 'booked';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_IN_CONSULTATION = 'in_consultation';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_CONSULTATION,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /** Statuses that still occupy the queue — nothing has been decided yet. */
    public const OPEN_STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_CONSULTATION,
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_patient_id',
        'health_doctor_id',
        'kind',
        'appointment_date',
        'appointment_time',
        'token_no',
        'status',
        'reason',
        'checked_in_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
        'no_show_at',
        'health_visit_id',
        'created_by',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'checked_in_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'no_show_at' => 'datetime',
        'token_no' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_visit_id' => 'integer',
        'created_by' => 'integer',
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

    public function visit()
    {
        return $this->belongsTo(HealthVisit::class, 'health_visit_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.appt_status_' . (in_array($status, self::STATUSES, true) ? $status : 'booked');
    }

    /** Badge colours, kept next to the states they describe. */
    public static function statusClasses(?string $status): string
    {
        return match ($status) {
            self::STATUS_CHECKED_IN => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
            self::STATUS_IN_CONSULTATION => 'bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200',
            self::STATUS_COMPLETED => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
            self::STATUS_CANCELLED => 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
            self::STATUS_NO_SHOW => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
            default => 'bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200',
        };
    }
}
