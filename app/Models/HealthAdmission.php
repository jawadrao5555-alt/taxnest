<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One inpatient stay, request through to release.
 *
 *   requested → admitted → discharge_requested → discharged
 *                        ↘ cancelled
 *
 * The ladder is an explicit column rather than something derived from which
 * timestamps happen to be filled in. Derived state reads fine until the day two
 * timestamps disagree, and then nobody can say what the stay's status actually
 * is.
 *
 * Two separate signatures release a patient: the clinical discharge
 * (discharged_at) and the financial clearance (cleared_at). They are different
 * people's decisions, so collapsing them into one flag would mean either the
 * ward can waive a bill or accounts can discharge a patient.
 */
class HealthAdmission extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_ADMITTED = 'admitted';
    public const STATUS_DISCHARGE_REQUESTED = 'discharge_requested';
    public const STATUS_DISCHARGED = 'discharged';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_ADMITTED,
        self::STATUS_DISCHARGE_REQUESTED,
        self::STATUS_DISCHARGED,
        self::STATUS_CANCELLED,
    ];

    /** The stay is still consuming a bed. */
    public const OPEN_STATUSES = [
        self::STATUS_ADMITTED,
        self::STATUS_DISCHARGE_REQUESTED,
    ];

    public const TYPES = ['planned', 'emergency', 'daycare', 'maternity', 'transfer_in'];

    public const CARE_STATUSES = ['stable', 'improving', 'serious', 'critical'];

    public const DISCHARGE_TYPES = [
        'routine', 'lama', 'referred', 'absconded', 'expired', 'transfer_out',
    ];

    public const PAYER_TYPES = ['self', 'panel', 'insurance', 'charity', 'government'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_patient_id',
        'health_doctor_id',
        'health_visit_id',
        'admission_no',
        'status',
        'admission_type',
        'health_bed_id',
        'health_ward_id',
        'reason',
        'provisional_diagnosis',
        'estimated_days',
        'estimated_cost',
        'deposit_required',
        'attendant_name',
        'attendant_phone',
        'attendant_relation',
        'payer_type',
        'payer_name',
        'payer_reference',
        'requested_at',
        'requested_by',
        'admitted_at',
        'admitted_by',
        'care_status',
        'care_note',
        'care_updated_at',
        'discharge_requested_at',
        'discharge_requested_by',
        'discharge_type',
        'final_diagnosis',
        'discharge_summary',
        'discharge_advice',
        'follow_up_date',
        'discharged_at',
        'discharged_by',
        'concession_amount',
        'concession_reason',
        'concession_approved_by',
        'cleared_at',
        'cleared_by',
        'cancel_reason',
        'cancelled_at',
        'cancelled_by',
        'charges_posted_through',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'admitted_at' => 'datetime',
        'care_updated_at' => 'datetime',
        'discharge_requested_at' => 'datetime',
        'discharged_at' => 'datetime',
        'cleared_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'follow_up_date' => 'date',
        'charges_posted_through' => 'date',
        'estimated_cost' => 'decimal:2',
        'deposit_required' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'estimated_days' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_visit_id' => 'integer',
        'health_bed_id' => 'integer',
        'health_ward_id' => 'integer',
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

    public function bed()
    {
        return $this->belongsTo(HealthBed::class, 'health_bed_id');
    }

    public function ward()
    {
        return $this->belongsTo(HealthWard::class, 'health_ward_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function events()
    {
        return $this->hasMany(HealthAdmissionEvent::class, 'health_admission_id');
    }

    public function charges()
    {
        return $this->hasMany(HealthAdmissionCharge::class, 'health_admission_id');
    }

    public function payments()
    {
        return $this->hasMany(HealthAdmissionPayment::class, 'health_admission_id');
    }

    public function operations()
    {
        return $this->hasMany(HealthOperation::class, 'health_admission_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.adm_status_' . (in_array($status, self::STATUSES, true) ? $status : 'requested');
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.adm_type_' . (in_array($type, self::TYPES, true) ? $type : 'planned');
    }

    public static function dischargeTypeLabelKey(?string $type): string
    {
        return 'health.discharge_type_' . (in_array($type, self::DISCHARGE_TYPES, true) ? $type : 'routine');
    }

    /**
     * Length of stay in DAYS, counted the way a hospital counts it: the day of
     * admission is day one, and a stay still running is measured to now.
     *
     * Not calendar-difference: a patient admitted at 23:50 and discharged at
     * 00:10 has occupied a bed across two bed-days, and every ward register in
     * the country says two.
     */
    public function lengthOfStayDays(): int
    {
        if (!$this->admitted_at) {
            return 0;
        }

        $end = $this->discharged_at ?: now();

        return $this->admitted_at->copy()->startOfDay()
            ->diffInDays($end->copy()->startOfDay()) + 1;
    }

    /**
     * Which bed this stay actually occupied, day by day.
     *
     * Returned as change points — `[['from' => 'Y-m-d', 'bed_id' => 12], …]`
     * ascending — because that is what billing needs: the bed the patient was
     * in ON a given date, not the bed they happen to be in NOW.
     *
     * Reading the current bed for the whole stay is the trap. A patient moved
     * from a general bed to ICU on day four would otherwise have every earlier
     * day re-billed at the ICU rate, on top of the general-ward days already
     * posted. The timeline is derived from the stay's own events, which are
     * written in the same transaction as the move, so it cannot drift from
     * what the bed board did.
     */
    public function bedTimeline(): array
    {
        $points = [];

        $events = HealthAdmissionEvent::withoutGlobalScopes()
            ->where('health_admission_id', $this->id)
            ->whereIn('event', [HealthAdmissionEvent::ADMITTED, HealthAdmissionEvent::TRANSFERRED])
            ->orderBy('id')
            ->get(['event', 'to_bed_id', 'occurred_at', 'created_at']);

        foreach ($events as $event) {
            if (!$event->to_bed_id) {
                continue;
            }

            $when = $event->occurred_at ?: $event->created_at;
            if (!$when) {
                continue;
            }

            $points[] = [
                'from' => $when->copy()->startOfDay()->toDateString(),
                'bed_id' => (int) $event->to_bed_id,
            ];
        }

        // No events yet (or an older stay written before the timeline existed):
        // fall back to where the patient is now, from the day they came in.
        if (!$points && $this->health_bed_id) {
            $from = $this->admitted_at ?: $this->created_at;
            $points[] = [
                'from' => ($from ?: now())->copy()->startOfDay()->toDateString(),
                'bed_id' => (int) $this->health_bed_id,
            ];
        }

        // The first bed can never start later than the admission itself, or the
        // admission day would be billed to nobody.
        if ($points && $this->admitted_at) {
            $admitDay = $this->admitted_at->copy()->startOfDay()->toDateString();
            if ($points[0]['from'] > $admitDay) {
                $points[0]['from'] = $admitDay;
            }
        }

        return $points;
    }

    /** The bed occupied on a given date, per the timeline above. */
    public function bedOnDate(string $date, ?array $timeline = null): ?int
    {
        $bedId = null;

        foreach ($timeline ?? $this->bedTimeline() as $point) {
            if ($point['from'] > $date) {
                break;
            }
            $bedId = $point['bed_id'];
        }

        return $bedId;
    }
}
