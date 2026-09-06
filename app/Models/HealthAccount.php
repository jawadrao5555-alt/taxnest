<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the chart of accounts (Task 1552).
 *
 * `system_key` is the only handle the posting engine ever uses. An owner may
 * rename "Cash in Hand" or re-code it to 1001-A and every future receipt still
 * lands in the same place — resolving by name or id would break posting
 * silently, and the symptom (a trial balance that stops balancing) would show
 * up weeks later with no obvious cause.
 */
class HealthAccount extends Model
{
    public const TYPE_ASSET = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_EQUITY = 'equity';
    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [
        self::TYPE_ASSET,
        self::TYPE_LIABILITY,
        self::TYPE_EQUITY,
        self::TYPE_INCOME,
        self::TYPE_EXPENSE,
    ];

    /** Types whose natural balance is a DEBIT. */
    public const DEBIT_TYPES = [self::TYPE_ASSET, self::TYPE_EXPENSE];

    /** Types that live on the balance sheet rather than the P&L. */
    public const BALANCE_SHEET_TYPES = [self::TYPE_ASSET, self::TYPE_LIABILITY, self::TYPE_EQUITY];

    /** Types that live on the profit & loss. */
    public const PROFIT_LOSS_TYPES = [self::TYPE_INCOME, self::TYPE_EXPENSE];

    public const FLOW_OPERATING = 'operating';
    public const FLOW_INVESTING = 'investing';
    public const FLOW_FINANCING = 'financing';

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'subtype',
        'cash_flow',
        'system_key',
        'is_system',
        'is_cash',
        'is_bank',
        'opening_balance',
        'opening_balance_date',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_cash' => 'boolean',
        'is_bank' => 'boolean',
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
        'company_id' => 'integer',
        'parent_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function lines()
    {
        return $this->hasMany(HealthJournalLine::class, 'health_account_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** TRUE when a positive balance on this account is a debit balance. */
    public function isDebitNatured(): bool
    {
        return in_array($this->type, self::DEBIT_TYPES, true);
    }

    /**
     * Turn a raw debit/credit pair into the account's own signed balance.
     *
     * An asset with 500 debit and 100 credit holds 400; a liability with the
     * same pair owes -400. Reporting both as "400" is how a balance sheet ends
     * up with a negative liability nobody can explain.
     */
    public function signedBalance(float $debit, float $credit): float
    {
        return round($this->isDebitNatured() ? $debit - $credit : $credit - $debit, 2);
    }

    /**
     * The name to show.
     *
     * A default account's `name` column holds a translation key, not text, so
     * the same chart reads correctly in English, Roman Urdu and Urdu. An account
     * the organisation created itself is shown exactly as they typed it —
     * translating somebody's own account name would be rude and wrong.
     */
    public function displayName(): string
    {
        if (!$this->system_key) {
            return (string) $this->name;
        }

        $key = 'health.acc_' . $this->system_key;
        $translated = __($key);

        return $translated === $key ? (string) $this->name : $translated;
    }

    public function typeLabelKey(): string
    {
        return 'health.acc_type_' . (in_array($this->type, self::TYPES, true) ? $this->type : self::TYPE_ASSET);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
