<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionInvoice extends Model
{
    protected $fillable = [
        'subscription_id', 'company_id', 'amount', 'status', 'due_date', 'paid_at',
        'payment_proof_id', 'document_number', 'document_snapshot',
    ];

    protected $casts = [
        'amount' => 'float',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'document_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            if ($invoice->getRawOriginal('document_snapshot') !== null && $invoice->isDirty()) {
                throw new \LogicException('Issued subscription documents are immutable; retain adjustments separately.');
            }
            if ($invoice->isDirty(['payment_proof_id', 'document_number', 'document_snapshot'])) {
                throw new \LogicException('Do not backfill unknown historical subscription documents.');
            }
        });
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function payments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
