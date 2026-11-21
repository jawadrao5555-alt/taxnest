<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

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

    public function scopeDownloadableReceipt(Builder $query): Builder
    {
        return $query->where(function (Builder $records) {
            $records->where(fn (Builder $issued) => $issued->whereNotNull('receipt_number')->whereNotNull('receipt_snapshot'))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('receipt_number')->whereNull('receipt_snapshot')
                    ->where('amount', '>', 0)->whereNotNull('paid_at'));
        });
    }

    public function hasDownloadableReceipt(): bool
    {
        if ($this->receipt_number !== null || $this->receipt_snapshot !== null) {
            return filled($this->receipt_number) && is_array($this->receipt_snapshot);
        }

        return $this->paid_at !== null && (float) $this->amount > 0;
    }

    public function receiptDisplayNumber(): string
    {
        return $this->receipt_number ?? 'HIST-PMT-'.$this->getKey();
    }
}

