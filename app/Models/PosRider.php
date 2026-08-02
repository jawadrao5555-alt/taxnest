<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Delivery rider (PRA POS restaurant module).
 *
 * Plain per-company record; optionally linked to a confined `pos_rider`
 * login via user_id (limit-exempt, like kitchen/waiter accounts).
 */
class PosRider extends Model
{
    protected $fillable = [
        'company_id', 'name', 'phone', 'cnic', 'vehicle_no', 'is_active', 'user_id',
        // Live tracking (Aug 2026)
        'on_duty', 'duty_started_at', 'last_lat', 'last_lng', 'last_located_at', 'app_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'on_duty' => 'boolean',
        'duty_started_at' => 'datetime',
        'last_located_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settlements()
    {
        return $this->hasMany(PosRiderSettlement::class, 'rider_id');
    }

    /**
     * Open CASH khata bills for this rider (unsettled, not returned).
     * Bypasses hide_archived — day-close archives bills while the rider
     * still owes the cash.
     */
    public function openCashBills()
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $this->company_id)
            ->where('rider_id', $this->id)
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            });
    }
}
