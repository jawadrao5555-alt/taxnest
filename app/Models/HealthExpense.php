<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money the organisation spent (Task 1552).
 *
 * `pay_mode` is the whole design. CASH and BANK take money out of a named
 * account the moment the expense is recorded; CREDIT raises a payable instead
 * and pays nothing — which is how an unpaid utility bill becomes a liability
 * the balance sheet shows rather than an expense that quietly never happened.
 *
 * Like every other money record in the panel, an expense is reversed rather
 * than deleted: the ledger entry behind it can only be undone by another ledger
 * entry, so deleting the document would strand its journal.
 */
class HealthExpense extends Model
{
    public const PAY_CASH = 'cash';
    public const PAY_BANK = 'bank';
    public const PAY_CREDIT = 'credit';
    public const PAY_MODES = [self::PAY_CASH, self::PAY_BANK, self::PAY_CREDIT];

    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'health_expense_category_id',
        'expense_no',
        'expense_date',
        'payee',
        'supplier_id',
        'amount',
        'tax_amount',
        'total_amount',
        'pay_mode',
        'paid_from_account_id',
        'reference',
        'description',
        'status',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_expense_category_id' => 'integer',
        'paid_from_account_id' => 'integer',
        'supplier_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function category()
    {
        return $this->belongsTo(HealthExpenseCategory::class, 'health_expense_category_id');
    }

    public function payFrom()
    {
        return $this->belongsTo(HealthAccount::class, 'paid_from_account_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function payModeLabelKey(): string
    {
        return 'health.exp_mode_' . (in_array($this->pay_mode, self::PAY_MODES, true) ? $this->pay_mode : self::PAY_CASH);
    }
}
