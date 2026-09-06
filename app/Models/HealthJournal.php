<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One balanced entry in the books (Task 1552).
 *
 * A journal is never edited and never deleted. A wrong one is REVERSED by a
 * second journal that points back at it, and both stay readable — that pair is
 * the difference between books an auditor can follow and a database somebody
 * has been tidying.
 *
 * `dedupe_key` is what makes the source sweep safe to run continuously: one
 * bill, one receipt, one purchase produces exactly one journal however many
 * times the sweep passes over it. The uniqueness is enforced by the database,
 * not by a caller remembering to check first.
 */
class HealthJournal extends Model
{
    public const TYPE_AUTO = 'auto';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_OPENING = 'opening';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_CLOSING = 'closing';

    public const TYPES = [
        self::TYPE_AUTO,
        self::TYPE_MANUAL,
        self::TYPE_OPENING,
        self::TYPE_ADJUSTMENT,
        self::TYPE_CLOSING,
    ];

    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    /**
     * Which journals a BALANCE or report must count.
     *
     * Both of them. A reversed entry is not deleted and its lines are not
     * cancelled — the correction is a second, opposite journal sitting beside
     * it, and the two net to zero on their own. Dropping the original from the
     * sums while keeping its reversal would subtract the same mistake twice and
     * leave the books further out than before the correction was made.
     *
     * The 'posted'-only filter is for finding a live entry to act on (reverse
     * it, dedupe against it), never for adding money up.
     */
    public const COUNTED_STATUSES = [self::STATUS_POSTED, self::STATUS_REVERSED];

    /** Where an automatic journal came from. */
    public const SRC_BILL = 'bill';
    public const SRC_PAYMENT = 'payment';
    public const SRC_ADVANCE_APPLIED = 'advance_applied';
    public const SRC_PURCHASE = 'purchase';
    public const SRC_SUPPLIER_PAYMENT = 'supplier_payment';
    public const SRC_PHARMACY_SALE = 'pharmacy_sale';
    public const SRC_PHARMACY_COGS = 'pharmacy_cogs';
    public const SRC_PHARMACY_RETURN = 'pharmacy_return';
    public const SRC_EXPENSE = 'expense';
    public const SRC_TRANSFER = 'transfer';
    public const SRC_DOCTOR_SETTLEMENT = 'doctor_settlement';
    public const SRC_MANUAL = 'manual';
    public const SRC_OPENING = 'opening';

    /** Every source, in the order the journals screen offers them as a filter. */
    public const SOURCES = [
        self::SRC_BILL,
        self::SRC_PAYMENT,
        self::SRC_ADVANCE_APPLIED,
        self::SRC_PURCHASE,
        self::SRC_SUPPLIER_PAYMENT,
        self::SRC_PHARMACY_SALE,
        self::SRC_PHARMACY_COGS,
        self::SRC_PHARMACY_RETURN,
        self::SRC_EXPENSE,
        self::SRC_TRANSFER,
        self::SRC_DOCTOR_SETTLEMENT,
        self::SRC_MANUAL,
        self::SRC_OPENING,
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_fiscal_period_id',
        'journal_no',
        'journal_date',
        'type',
        'source_type',
        'source_id',
        'source_reference',
        'memo',
        'total_debit',
        'total_credit',
        'status',
        'posted_at',
        'posted_by',
        'reversed_by_journal_id',
        'reverses_journal_id',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'adjusts_period_id',
        'dedupe_key',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'source_id' => 'integer',
        'health_fiscal_period_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function lines()
    {
        return $this->hasMany(HealthJournalLine::class, 'health_journal_id')->orderBy('line_no');
    }

    public function period()
    {
        return $this->belongsTo(HealthFiscalPeriod::class, 'health_fiscal_period_id');
    }

    public function reversal()
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    public function reversed()
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /** TRUE when the two sides agree to the paisa. */
    public function isBalanced(): bool
    {
        return abs(round((float) $this->total_debit - (float) $this->total_credit, 2)) < 0.01;
    }

    public function typeLabelKey(): string
    {
        return 'health.jrn_type_' . (in_array($this->type, self::TYPES, true) ? $this->type : self::TYPE_AUTO);
    }
}
