<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = [
        'subscription_invoice_id', 'amount', 'payment_method', 'transaction_ref', 'paid_at',
        'receipt_number', 'receipt_snapshot',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
        'receipt_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $payment) {
            if ($payment->getRawOriginal('receipt_snapshot') !== null && $payment->isDirty()) {
                throw new \LogicException('Issued subscription payment receipts are immutable.');
            }
            if ($payment->isDirty(['receipt_number', 'receipt_snapshot'])) {
                throw new \LogicException('Do not backfill unknown historical payment receipts.');
            }
        });
    }

    public function invoice()
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
