<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every AI Reader page a company spends, gets back, or buys.
 *
 * Two pockets are tracked separately on purpose:
 *   from_allowance — the package's monthly pages (reset every calendar month)
 *   from_balance   — pages the shop PAID for (never expire)
 *
 * Without this ledger "meri pages kahan gayin?" has no answer, and a failed
 * batch cannot be refunded to the pocket it actually came out of.
 */
class AiPageLedger extends Model
{
    public const KIND_CONSUME = 'consume';
    public const KIND_REFUND = 'refund';
    public const KIND_TOPUP = 'topup';
    public const KIND_ADMIN_GRANT = 'admin_grant';

    protected $fillable = [
        'company_id',
        'user_id',
        'kind',
        'from_allowance',
        'from_balance',
        'source',
        'ref_type',
        'ref_id',
        'note',
    ];

    protected $casts = [
        'from_allowance' => 'integer',
        'from_balance' => 'integer',
        'ref_id' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getPagesAttribute(): int
    {
        return (int) $this->from_allowance + (int) $this->from_balance;
    }
}
