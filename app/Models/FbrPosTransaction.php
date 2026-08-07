<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosTransaction extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'invoice_number', 'invoice_mode', 'customer_name', 'customer_phone', 'customer_ntn',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_amount', 'fbr_service_charge', 'total_amount', 'payment_method',
        'status', 'fbr_invoice_number', 'fbr_status', 'fbr_response_code',
        'fbr_response', 'fbr_submission_hash', 'created_by',
        'share_token', 'share_token_created_at',
        // Phase 2 fields
        'terminal_id', 'shift_id', 'transaction_type', 'parent_transaction_id',
        'customer_id', 'promotion_id', 'promotion_code',
        'loyalty_points_earned', 'loyalty_points_redeemed', 'loyalty_redemption_amount',
        'cash_received', 'change_due', 'payment_breakdown',
        // Task 156: order-type + delivery-address snapshot (Pending Deliveries panel)
        'order_type', 'delivery_address',
        // Aug 2026: idempotency key — client-generated UUID riding on every submit attempt
        // so the server replay-guard can return the existing bill on a lost-response retry.
        'offline_uuid',
        // Order Matching (Aug 2026): token/code copied from held sale at billing time;
        // stored permanently so receipt reprints always carry the correct match identifier.
        'token_no', 'order_code',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'fbr_service_charge' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'fbr_response' => 'array',
        'share_token_created_at' => 'datetime',
        'cash_received' => 'decimal:2',
        'change_due' => 'decimal:2',
        'loyalty_redemption_amount' => 'decimal:2',
        'payment_breakdown' => 'array',
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
