<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosTransaction extends Model
{
    protected $fillable = [
        'company_id', 'invoice_number', 'customer_name', 'customer_phone', 'customer_ntn',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_amount', 'total_amount', 'payment_method',
        'status', 'fbr_invoice_number', 'fbr_status', 'fbr_response_code',
        'fbr_response', 'fbr_submission_hash', 'created_by',
        'share_token', 'share_token_created_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'fbr_response' => 'array',
        'share_token_created_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(FbrPosTransactionItem::class, 'transaction_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fbrLogs()
    {
        return $this->hasMany(FbrPosLog::class, 'transaction_id');
    }
}
