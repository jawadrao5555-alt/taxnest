<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled or performed procedure.
 *
 *   scheduled → in_progress → completed
 *             ↘ postponed
 *             ↘ cancelled
 *
 * `health_admission_id` is nullable on purpose: a day-care procedure is a real
 * operation with a real bill and no bed. When it IS linked to a stay, its fee
 * lands on that stay's ledger.
 *
 * `charge_posted_at` is the idempotency stamp. Completing an operation twice —
 * a double-clicked button, a retried request — must never bill the patient
 * twice, and the stamp is the only thing standing between those two outcomes.
 */
class HealthOperation extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_POSTPONED = 'postponed';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_POSTPONED,
    ];

    /** Statuses that still hold a slot in a theatre's diary. */
    public const BLOCKING_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
    ];

    public const URGENCIES = ['elective', 'emergency'];

    public const OUTCOMES = ['successful', 'complications', 'aborted', 'expired'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_patient_id',
        'health_admission_id',
        'health_procedure_id',
        'health_operation_theatre_id',
        'operation_no',
        'title',
        'status',
        'urgency',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'primary_surgeon_id',
        'anaesthetist_id',
        'anaesthesia_type',
        'pre_op_checklist',
        'pre_op_notes',
        'pre_op_completed_at',
        'pre_op_completed_by',
        'consent_reference',
        'is_package',
        'price',
        'concession_amount',
        'concession_reason',
        'operative_notes',
        'findings',
        'outcome',
        'complications',
        'blood_loss_ml',
        'specimen_sent',
        'post_op_instructions',
        'cancel_reason',
        'cancelled_at',
        'cancelled_by',
        'completed_at',
        'completed_by',
        'charge_posted_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'pre_op_completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'charge_posted_at' => 'datetime',
        'is_package' => 'boolean',
        'specimen_sent' => 'boolean',
        'price' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'blood_loss_ml' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_admission_id' => 'integer',
        'health_procedure_id' => 'integer',
        'health_operation_theatre_id' => 'integer',
        'primary_surgeon_id' => 'integer',
        'anaesthetist_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function patient()
    {
        return $this->belongsTo(HealthPatient::class, 'health_patient_id');
    }

    public function admission()
    {
        return $this->belongsTo(HealthAdmission::class, 'health_admission_id');
    }

    public function procedure()
    {
        return $this->belongsTo(HealthProcedure::class, 'health_procedure_id');
    }

    public function theatre()
    {
        return $this->belongsTo(HealthOperationTheatre::class, 'health_operation_theatre_id');
    }

    public function surgeon()
    {
        return $this->belongsTo(HealthDoctor::class, 'primary_surgeon_id');
    }

    public function anaesthetist()
    {
        return $this->belongsTo(HealthDoctor::class, 'anaesthetist_id');
    }

    public function team()
    {
        return $this->hasMany(HealthOperationTeamMember::class, 'health_operation_id');
    }

    public function consumables()
    {
        return $this->hasMany(HealthOperationConsumable::class, 'health_operation_id');
    }

    public function scopeBlocking($query)
    {
        return $query->whereIn('status', self::BLOCKING_STATUSES);
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.op_status_' . (in_array($status, self::STATUSES, true) ? $status : self::STATUS_SCHEDULED);
    }

    public static function outcomeLabelKey(?string $outcome): string
    {
        return 'health.op_outcome_' . (in_array($outcome, self::OUTCOMES, true) ? $outcome : 'successful');
    }

    /**
     * The pre-op checklist as [{item, done, note}], whatever shape it was
     * stored in.
     */
    public function checklist(): array
    {
        $decoded = json_decode((string) $this->pre_op_checklist, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $label = trim((string) ($entry['item'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $items[] = [
                    'item' => $label,
                    'done' => (bool) ($entry['done'] ?? false),
                    'note' => (string) ($entry['note'] ?? ''),
                ];
                continue;
            }

            $label = trim((string) $entry);
            if ($label !== '') {
                $items[] = ['item' => $label, 'done' => false, 'note' => ''];
            }
        }

        return $items;
    }

    /** Every checklist item ticked — or no checklist at all. */
    public function preOpReady(): bool
    {
        foreach ($this->checklist() as $item) {
            if (!$item['done']) {
                return false;
            }
        }

        return true;
    }

    /** What this operation contributes to the bill. */
    public function netCharge(): float
    {
        return max(0, round((float) $this->price - (float) $this->concession_amount, 2));
    }

    /** Consumables billable on top of the operation fee (never for a package). */
    public function billableConsumableTotal(): float
    {
        if ($this->is_package) {
            return 0.0;
        }

        $rows = $this->relationLoaded('consumables') ? $this->getRelation('consumables') : $this->consumables;

        return round((float) $rows->where('is_billable', true)->sum('amount'), 2);
    }
}
