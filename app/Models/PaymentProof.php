<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PaymentProof extends Model
{
    protected $fillable = [
        'company_id',
        'amount',
        'payment_method',
        'auto_access_until',
        'reference',
        'payment_date',
        'proof_path',
        'status',
        'pricing_plan_id',
        'billing_cycle',
        // Extra-branch add-on (Aug 2026): 'subscription' (default) ya
        // 'extra_branch' + kitne slots maange gaye.
        'request_type',
        'extra_branch_qty',
        'subscription_id',
        'notes',
        'verified_by',
        'verified_at',
        'reject_reason',
        'file_pruned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'auto_access_until' => 'datetime',
        'verified_at' => 'datetime',
        'file_pruned_at' => 'datetime',
        'extra_branch_qty' => 'integer',
    ];

    /** hasColumn() per call = DB round trip; per-request memo. */
    private static ?bool $kindColumn = null;

    public static function kindColumnExists(): bool
    {
        if (self::$kindColumn === null) {
            try {
                self::$kindColumn = Schema::hasColumn('payment_proofs', 'request_type');
            } catch (\Throwable $e) {
                self::$kindColumn = false;
            }
        }

        return self::$kindColumn;
    }

    /** 'subscription' | 'extra_branch' — pre-migration rows = subscription. */
    public function kind(): string
    {
        return self::kindColumnExists() && $this->request_type === 'extra_branch'
            ? 'extra_branch'
            : 'subscription';
    }

    public function isExtraBranch(): bool
    {
        return $this->kind() === 'extra_branch';
    }

    /**
     * Package/renewal proofs only. Har wo jagah jahan "pending proof" ka matlab
     * "renewal review mein hai" hai (lock modal, expiry popup, one-pending rule)
     * ko is scope se guzarna chahiye — warna ek extra-branch request renewal ka
     * form chhupa deti hai.
     */
    public function scopeSubscriptionKind(Builder $q): Builder
    {
        if (!self::kindColumnExists()) {
            return $q;
        }

        return $q->where(function ($w) {
            $w->whereNull('request_type')->orWhere('request_type', '!=', 'extra_branch');
        });
    }

    public function scopeExtraBranchKind(Builder $q): Builder
    {
        if (!self::kindColumnExists()) {
            // Column abhi migrate nahi hua — koi extra-branch row ho hi nahi sakti.
            return $q->whereRaw('1 = 0');
        }

        return $q->where('request_type', 'extra_branch');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }
}
