<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The auditable timeline of one stay. Append-only.
 *
 * Nothing here is ever edited or deleted. It answers "who moved this patient,
 * and when" — the first question asked whenever a stay or a bill is disputed —
 * and an editable audit trail answers nothing.
 *
 * `actor_name` is frozen at write time because staff leave and accounts get
 * deactivated; a timeline that renders a blank where the ward sister's name
 * used to be has lost the only thing it was keeping.
 */
class HealthAdmissionEvent extends Model
{
    public const REQUESTED = 'requested';
    public const ADMITTED = 'admitted';
    public const BED_ASSIGNED = 'bed_assigned';
    public const TRANSFERRED = 'transferred';
    public const CARE_NOTE = 'care_note';
    public const CHARGE_POSTED = 'charge_posted';
    public const CHARGE_REVERSED = 'charge_reversed';
    public const PAYMENT = 'payment';
    public const CONCESSION = 'concession';
    public const OPERATION_SCHEDULED = 'operation_scheduled';
    public const OPERATION_COMPLETED = 'operation_completed';
    public const OPERATION_CANCELLED = 'operation_cancelled';
    public const DISCHARGE_REQUESTED = 'discharge_requested';
    public const CLEARED = 'cleared';
    public const DISCHARGED = 'discharged';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'health_admission_id',
        'event',
        'from_status',
        'to_status',
        'from_bed_id',
        'to_bed_id',
        'note',
        'meta',
        'actor_id',
        'actor_name',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'company_id' => 'integer',
        'health_admission_id' => 'integer',
        'from_bed_id' => 'integer',
        'to_bed_id' => 'integer',
        'actor_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function admission()
    {
        return $this->belongsTo(HealthAdmission::class, 'health_admission_id');
    }

    public function metaArray(): array
    {
        if (!$this->meta) {
            return [];
        }

        $decoded = json_decode((string) $this->meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function labelKey(?string $event): string
    {
        return 'health.adm_event_' . ($event ?: 'care_note');
    }
}
