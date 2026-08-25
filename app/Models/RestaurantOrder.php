<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrder extends Model
{
    protected $fillable = [
        'company_id', 'order_number', 'table_id', 'order_type', 'status',
        'customer_id', 'customer_name', 'customer_phone', 'delivery_address',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'tax_amount', 'total_amount',
        'payment_method', 'kitchen_notes', 'priority', 'pos_transaction_id', 'created_by',
        'estimated_cost',
        'kot_sent_at', 'kot_print_count',
        'kitchen_status', 'kitchen_started_at', 'kitchen_ready_at', 'kitchen_cleared_at',
        'assigned_cashier_id', 'source',
        'token_no',
        'superseded_at',
        // Task 841: KDS cancelled-items badge — JSON list of voided dishes.
        'void_items',
        // Task 1001: per-hold-attempt idempotency key — replay guard for lost responses.
        'hold_uuid',
        // Owner batch 26 Aug 2026: "paisay online aa rahay hain" marker — the
        // proof bill prints ONLINE instead of NOT PAID and the bill cannot be
        // finalized until the counter confirms the payment landed.
        'online_payment_awaited_at', 'online_payment_marked_by',
    ];

    protected $casts = [
        // int casts: live cPanel PDO returns uncast int columns as STRINGS
        // (dev = ints) — JS strict === on ids breaks only on live.
        'table_id' => 'integer',
        'assigned_cashier_id' => 'integer',
        'created_by' => 'integer',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'priority' => 'boolean',
        'kot_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancelled_by' => 'integer',
        'superseded_at' => 'datetime',
        'kot_print_count' => 'integer',
        'kitchen_started_at' => 'datetime',
        'kitchen_ready_at' => 'datetime',
        'kitchen_cleared_at' => 'datetime',
        'online_payment_awaited_at' => 'datetime',
        'online_payment_marked_by' => 'integer',
    ];

    /** Owner batch 26 Aug 2026: is this order waiting on an online transfer? */
    public function awaitingOnlinePayment(): bool
    {
        return !empty($this->online_payment_awaited_at);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // P7 (F6): cashier a waiter-tablet order was sent to for payment.
    public function assignedCashier()
    {
        return $this->belongsTo(User::class, 'assigned_cashier_id');
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function customer()
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }

    public function items()
    {
        return $this->hasMany(RestaurantOrderItem::class, 'order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Task 506: jis user ne order cancel kiya (deleteOrder / waiter cancelOrder).
    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Task 506: sirf ASLI (human) cancels — recall+re-hold ke system-supersede
     * ghosts ko chhupao. Shared predicate: report page/CSV/PDF/summary aur
     * dashboard "cancelled today" tile sab isi se guzarte hain.
     * - superseded_at marker (naya supersede write + backfill)
     * - legacy-ghost signature fallback (NULL stamps + zero items) — auto-deploy
     *   code ko migrate se pehle bhi utar sakta hai, is liye dono guards.
     */
    public function scopeGenuineCancelled($query)
    {
        $query->where('status', 'cancelled');
        if (\Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'superseded_at')) {
            $query->whereNull('superseded_at');
        }
        return $query->where(function ($q) {
            $q->whereNotNull('cancelled_at')
                ->orWhereNotNull('cancelled_by')
                ->orWhereHas('items');
        });
    }

    public function posTransaction()
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }
}
