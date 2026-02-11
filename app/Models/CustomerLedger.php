<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLedger extends Model
{
    protected $fillable = [
        'company_id',
        'customer_name',
        'customer_ntn',
        'invoice_id',
        'debit',
        'credit',
        'balance_after',
        'type',
        'notes',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('Ledger entries are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Ledger entries are append-only and cannot be deleted.');
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
