<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the patient is handed (Task 1551).
 *
 * One engine, three shapes, because a hospital legitimately produces all three:
 *
 *   scope=department  the pharmacy's own receipt, the lab's own receipt
 *   scope=combined    everything outstanding across departments on one sheet
 *   scope=final       the discharge settlement of a stay
 *
 * and two documents:
 *
 *   estimate  a quote. Reserves nothing, owes nothing, prints "estimate".
 *   invoice   owed money. Freezes its charges and their tax treatment.
 *
 * The FBR columns are deliberately the same contract the retail FBR POS bill
 * uses — invoice number, status, response code, short error, retry count — so
 * the fiscal half of healthcare behaves exactly like the half of the platform
 * that has been filing live for a year.
 */
class HealthBill extends Model
{
    public const TYPE_ESTIMATE = 'estimate';
    public const TYPE_INVOICE = 'invoice';
    public const TYPES = [self::TYPE_ESTIMATE, self::TYPE_INVOICE];

    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_COMBINED = 'combined';
    public const SCOPE_FINAL = 'final';
    public const SCOPES = [self::SCOPE_DEPARTMENT, self::SCOPE_COMBINED, self::SCOPE_FINAL];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_FINALIZED,
        self::STATUS_SETTLED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses whose money still counts towards what the patient owes. */
    public const LIVE_STATUSES = [self::STATUS_FINALIZED, self::STATUS_SETTLED];

    public const PAYER_TYPES = ['self', 'panel', 'insurance', 'corporate', 'charity', 'government'];

    /** FBR lifecycle, mirroring fbr_pos_transactions.fbr_status. */
    public const FBR_NOT_APPLICABLE = 'not_applicable';
    public const FBR_PENDING = 'pending';
    public const FBR_SUBMITTED = 'submitted';
    public const FBR_FAILED = 'failed';
    public const FBR_CONFIG_ERROR = 'config_error';

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_patient_id',
        'health_visit_id',
        'health_admission_id',
        'bill_no',
        'doc_type',
        'scope',
        'status',
        'bill_date',
        'business_date',
        'due_date',
        'gross_amount',
        'concession_amount',
        'net_amount',
        'tax_amount',
        'total_amount',
        'insurance_amount',
        'corporate_amount',
        'patient_payable',
        'deposit_applied',
        'paid_amount',
        'refunded_amount',
        'outstanding_amount',
        'payer_type',
        'payer_name',
        'payer_reference',
        'treatment_totals',
        'fbr_eligible',
        'fbr_status',
        'fbr_invoice_number',
        'fbr_response_code',
        'fbr_error_message',
        'fbr_submitted_at',
        'fbr_retry_count',
        'fbr_pos_transaction_id',
        'share_token',
        'notes',
        'finalized_at',
        'finalized_by',
        'settled_at',
        'settled_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'created_by',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'business_date' => 'date',
        'due_date' => 'date',
        'gross_amount' => 'decimal:2',
        'concession_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'corporate_amount' => 'decimal:2',
        'patient_payable' => 'decimal:2',
        'deposit_applied' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'treatment_totals' => 'array',
        'fbr_eligible' => 'boolean',
        'fbr_submitted_at' => 'datetime',
        'fbr_retry_count' => 'integer',
        'finalized_at' => 'datetime',
        'settled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_patient_id' => 'integer',
        'health_visit_id' => 'integer',
        'health_admission_id' => 'integer',
        'fbr_pos_transaction_id' => 'integer',
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

    public function admission()
    {
        return $this->belongsTo(HealthAdmission::class, 'health_admission_id');
    }

    public function visit()
    {
        return $this->belongsTo(HealthVisit::class, 'health_visit_id');
    }

    public function lines()
    {
        return $this->hasMany(HealthBillLine::class, 'health_bill_id')->orderBy('line_no');
    }

    public function charges()
    {
        return $this->hasMany(HealthCharge::class, 'health_bill_id');
    }

    public function payments()
    {
        return $this->hasMany(HealthPayment::class, 'health_bill_id')->orderBy('id');
    }

    public function submissions()
    {
        return $this->hasMany(HealthFbrSubmission::class, 'health_bill_id')->orderByDesc('id');
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function isEstimate(): bool
    {
        return $this->doc_type === self::TYPE_ESTIMATE;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Finalized or settled — its charges and treatments are frozen. */
    public function isFinal(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function isFbrFiled(): bool
    {
        return !empty($this->fbr_invoice_number);
    }

    /**
     * The QR a healthcare receipt prints.
     *
     * The BARE FBR invoice number, never a JSON blob: Tax Asaan reads a scanned
     * QR AS the invoice number, and a JSON payload verifies nowhere. Same rule
     * the retail FBR receipt follows.
     */
    public function fbrQrPayload(): ?string
    {
        return $this->fbr_invoice_number ?: null;
    }

    public static function statusLabelKey(?string $status): string
    {
        return 'health.bill_status_' . (in_array($status, self::STATUSES, true) ? $status : self::STATUS_DRAFT);
    }

    public static function scopeLabelKey(?string $scope): string
    {
        return 'health.bill_scope_' . (in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_DEPARTMENT);
    }
}
