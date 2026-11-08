<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only evidence of an explicitly reviewed billing adjustment. */
class SubscriptionFinancialAdjustment extends Model
{
    public const CREDIT = 'credit_note';
    public const REFUND = 'refund_record';
    public const REVERSAL = 'reversal';

    protected $fillable = [
        'company_id', 'subscription_invoice_id', 'subscription_payment_id',
        'reverses_adjustment_id', 'admin_id', 'kind', 'amount', 'currency',
        'reason', 'external_reference', 'idempotency_key', 'occurred_at',
        'evidence_snapshot',
    ];

    protected $casts = [
        'amount' => 'float',
        'occurred_at' => 'datetime',
        'evidence_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('Subscription financial adjustment evidence is immutable; append a reversal instead.');
        });
        static::deleting(static function (): void {
            throw new \LogicException('Subscription financial adjustment evidence cannot be deleted.');
        });
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function invoice() { return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id'); }
    public function payment() { return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id'); }
    public function actor() { return $this->belongsTo(AdminUser::class, 'admin_id'); }
    public function original() { return $this->belongsTo(self::class, 'reverses_adjustment_id'); }
    public function reversal() { return $this->hasOne(self::class, 'reverses_adjustment_id'); }
}
