<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelFolioEntry extends Model
{
    public const TYPE_CHARGE = 'charge';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_REFUND = 'refund';
    public const TYPE_DEPOSIT_REFUND = 'deposit_refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'company_id', 'stay_id', 'entry_type', 'category', 'description',
        'quantity', 'uom', 'unit_amount', 'amount', 'pos_transaction_id',
        'product_id', 'payment_method', 'is_deposit', 'reverses_entry_id',
        'idempotency_key', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_deposit' => 'boolean',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(HotelStay::class, 'stay_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }

    public function isPostedSale(): bool
    {
        return $this->entry_type === self::TYPE_CHARGE && $this->pos_transaction_id;
    }
}
