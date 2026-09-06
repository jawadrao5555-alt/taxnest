<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What kind of expense this was, and which ledger account it books into
 * (Task 1552).
 *
 * The category carries the account so an accountant records "Electricity" and
 * the books post to the right expense line without anybody choosing an account
 * code at the counter. A category with no account cannot post — that is checked
 * at save time rather than discovered by a missing entry.
 */
class HealthExpenseCategory extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'health_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'health_account_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function account()
    {
        return $this->belongsTo(HealthAccount::class, 'health_account_id');
    }

    public function expenses()
    {
        return $this->hasMany(HealthExpense::class, 'health_expense_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
