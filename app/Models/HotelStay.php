<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelStay extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_CHECKED_OUT = 'checked_out';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const OPEN_STATUSES = [self::STATUS_RESERVED, self::STATUS_CHECKED_IN];

    protected $fillable = [
        'company_id', 'branch_id', 'stay_number', 'status', 'room_id',
        'guest_customer_id', 'payer_customer_id', 'guest_name', 'guest_phone',
        'guest_cnic', 'check_in_date', 'check_out_date', 'actual_check_in_at',
        'actual_check_out_at', 'adult_count', 'child_count', 'nights',
        'rate_amount', 'rate_unit', 'charging_rule', 'notes', 'cancel_reason',
        'idempotency_key', 'created_by',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'actual_check_in_at' => 'datetime',
        'actual_check_out_at' => 'datetime',
        'adult_count' => 'integer',
        'child_count' => 'integer',
        'nights' => 'integer',
        'rate_amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HotelRoom::class, 'room_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'guest_customer_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'payer_customer_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HotelStayAssignment::class, 'stay_id');
    }

    public function occupants(): HasMany
    {
        return $this->hasMany(HotelStayGuest::class, 'stay_id');
    }

    public function folioEntries(): HasMany
    {
        return $this->hasMany(HotelFolioEntry::class, 'stay_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
