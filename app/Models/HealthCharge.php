<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One billable thing that happened to a patient (Task 1551).
 *
 * This is the unified ledger every healthcare module feeds — OPD fees, pharmacy
 * sales, laboratory work, room and nursing days, operations and anything a
 * counter posts by hand. Before it existed each module kept its own money and a
 * patient could be given three different answers to one question.
 *
 * Three properties the rest of the module leans on:
 *
 *  1. IMMUTABLE. A charge is never edited into a different charge and never
 *     deleted. It is reversed or adjusted, and both the original and the
 *     decision survive (health_charge_adjustments).
 *  2. SOURCE-LINKED. source_type/source_id/source_reference keep the visit,
 *     prescription, admission charge, operation or pharmacy sale behind the
 *     line reachable forever, so "why am I being charged this" always has an
 *     answer.
 *  3. TREATMENT IS STAMPED, NOT DERIVED. The local / exempt / FBR decision is
 *     written onto the row when it is posted and FROZEN (tax_locked_at) when a
 *     bill carrying it is finalized. After that nobody — owner included — can
 *     move it between treatments.
 */
class HealthCharge extends Model
{
    public const CAT_OPD = 'opd';
    public const CAT_PHARMACY = 'pharmacy';
    public const CAT_LAB = 'lab';
    public const CAT_ROOM = 'room';
    public const CAT_NURSING = 'nursing';
    public const CAT_OPERATION = 'operation';
    public const CAT_PROCEDURE = 'procedure';
    public const CAT_DOCTOR = 'doctor';
    public const CAT_CONSUMABLE = 'consumable';
    public const CAT_INVESTIGATION = 'investigation';
    public const CAT_SERVICE = 'service';
    public const CAT_MISC = 'misc';

    public const CATEGORIES = [
        self::CAT_OPD,
        self::CAT_PHARMACY,
        self::CAT_LAB,
        self::CAT_ROOM,
        self::CAT_NURSING,
        self::CAT_OPERATION,
        self::CAT_PROCEDURE,
        self::CAT_DOCTOR,
        self::CAT_CONSUMABLE,
        self::CAT_INVESTIGATION,
        self::CAT_SERVICE,
        self::CAT_MISC,
    ];

    /**
     * Categories a person may post by hand.
     *
     * Room and nursing are missing on purpose — those are produced by the ward's
     * daily run against the bed the patient is actually in, and a hand-typed
     * room-day would sit next to the automatic one with nobody able to say which
     * is real. Same rule the IPD ledger already applies.
     */
    public const MANUAL_CATEGORIES = [
        self::CAT_SERVICE,
        self::CAT_PROCEDURE,
        self::CAT_DOCTOR,
        self::CAT_INVESTIGATION,
        self::CAT_CONSUMABLE,
        self::CAT_MISC,
    ];

    public const STATUS_POSTED = 'posted';
    public const STATUS_BILLED = 'billed';
    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_VISIT = 'visit';
    public const SOURCE_PRESCRIPTION = 'prescription';
    public const SOURCE_PHARMACY_SALE = 'pharmacy_sale';
    public const SOURCE_ADMISSION_CHARGE = 'admission_charge';
    public const SOURCE_OPERATION = 'operation';
    public const SOURCE_LAB_ORDER = 'lab_order';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_patient_id',
        'health_visit_id',
        'health_admission_id',
        'charge_no',
        'charge_date',
        'category',
        'description',
        'reference',
        'source_type',
        'source_id',
        'source_reference',
        'unit_price',
        'quantity',
        'gross_amount',
        'concession_amount',
        'concession_reason',
        'concession_approved_by',
        'net_amount',
        'health_tax_category_id',
        'tax_treatment',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'pct_code',
        'tax_locked_at',
        'tax_locked_by',
        'health_bill_id',
        'billed_at',
        'status',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'dedupe_key',
        'created_by',
    ];

    protected $casts = [
        'charge_date' => 'date',
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'gross_amount' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_locked_at' => 'datetime',
        'billed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_visit_id' => 'integer',
        'health_admission_id' => 'integer',
        'health_bill_id' => 'integer',
        'health_tax_category_id' => 'integer',
        'source_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function patient()
    {
        return $this->belongsTo(HealthPatient::class, 'health_patient_id');
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function bill()
    {
        return $this->belongsTo(HealthBill::class, 'health_bill_id');
    }

    public function taxCategory()
    {
        return $this->belongsTo(HealthTaxCategory::class, 'health_tax_category_id');
    }

    public function adjustments()
    {
        return $this->hasMany(HealthChargeAdjustment::class, 'health_charge_id')->orderBy('id');
    }

    /** Still on the ledger — not reversed. */
    public function scopeLive($query)
    {
        return $query->whereIn('status', [self::STATUS_POSTED, self::STATUS_BILLED]);
    }

    /** Posted, not reversed, and not yet claimed by a bill. */
    public function scopeUnbilled($query)
    {
        return $query->where('status', self::STATUS_POSTED)->whereNull('health_bill_id');
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /**
     * TRUE once a finalized bill froze this charge's regulatory treatment.
     * Nothing may reclassify, discount or reverse it after that point — the
     * correction path is a credit note or a refund, not a rewrite.
     */
    public function isLocked(): bool
    {
        return $this->tax_locked_at !== null;
    }

    public function isFbrReportable(): bool
    {
        return $this->tax_treatment === HealthTaxCategory::TREATMENT_FBR;
    }

    public static function categoryLabelKey(?string $category): string
    {
        return 'health.led_cat_' . (in_array($category, self::CATEGORIES, true) ? $category : self::CAT_MISC);
    }

    public static function sourceLabelKey(?string $source): string
    {
        $known = [
            self::SOURCE_VISIT,
            self::SOURCE_PRESCRIPTION,
            self::SOURCE_PHARMACY_SALE,
            self::SOURCE_ADMISSION_CHARGE,
            self::SOURCE_OPERATION,
            self::SOURCE_LAB_ORDER,
            self::SOURCE_MANUAL,
        ];

        return 'health.led_src_' . (in_array($source, $known, true) ? $source : self::SOURCE_MANUAL);
    }
}
