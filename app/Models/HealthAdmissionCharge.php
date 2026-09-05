<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One charge-producing event on a stay.
 *
 * Money is three numbers — gross, concession, net — never "the amount we took".
 * A concession is somebody's decision and has to survive in the record together
 * with the list price it was granted against.
 *
 * A charge is never deleted, only REVERSED, which leaves both the original and
 * the reversal in the ledger. `dedupe_key` is what makes the recurring daily
 * charge safe to re-run: the room-day for one admission on one date has exactly
 * one key, and the unique index refuses the second attempt.
 */
class HealthAdmissionCharge extends Model
{
    public const CAT_ROOM = 'room';
    public const CAT_NURSING = 'nursing';
    public const CAT_SERVICE = 'service';
    public const CAT_MEDICINE = 'medicine';
    public const CAT_CONSUMABLE = 'consumable';
    public const CAT_PROCEDURE = 'procedure';
    public const CAT_DOCTOR = 'doctor';
    public const CAT_INVESTIGATION = 'investigation';
    public const CAT_MISC = 'misc';

    public const CATEGORIES = [
        self::CAT_ROOM,
        self::CAT_NURSING,
        self::CAT_SERVICE,
        self::CAT_MEDICINE,
        self::CAT_CONSUMABLE,
        self::CAT_PROCEDURE,
        self::CAT_DOCTOR,
        self::CAT_INVESTIGATION,
        self::CAT_MISC,
    ];

    /**
     * Categories a person may post by hand.
     *
     * Room and nursing are missing on purpose: those are produced by the daily
     * run against the bed the patient is actually in. A hand-typed room-day
     * would sit alongside the automatic one and nobody could tell which is
     * real.
     */
    public const MANUAL_CATEGORIES = [
        self::CAT_SERVICE,
        self::CAT_MEDICINE,
        self::CAT_CONSUMABLE,
        self::CAT_PROCEDURE,
        self::CAT_DOCTOR,
        self::CAT_INVESTIGATION,
        self::CAT_MISC,
    ];

    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_admission_id',
        'health_patient_id',
        'charge_date',
        'category',
        'description',
        'reference',
        'source_type',
        'source_id',
        'unit_price',
        'quantity',
        'gross_amount',
        'concession_amount',
        'concession_reason',
        'concession_approved_by',
        'net_amount',
        'is_recurring',
        'dedupe_key',
        'status',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'created_by',
    ];

    protected $casts = [
        'charge_date' => 'date',
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'is_recurring' => 'boolean',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_admission_id' => 'integer',
        'health_patient_id' => 'integer',
        'source_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function admission()
    {
        return $this->belongsTo(HealthAdmission::class, 'health_admission_id');
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public static function categoryLabelKey(?string $category): string
    {
        return 'health.charge_cat_' . (in_array($category, self::CATEGORIES, true) ? $category : self::CAT_MISC);
    }
}
