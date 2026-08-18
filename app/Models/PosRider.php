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

    /**
     * SQL expression for a bill's khata remaining — partial receipts
     * (rider_partial_paid, Task 525) already handed over are deducted.
     * Schema-guarded for prod drift (column may not exist yet).
     */
    public static function remainingExpr(string $table = 'pos_transactions'): string
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, 'rider_partial_paid')
            ? '(total_amount - COALESCE(rider_partial_paid, 0))'
            : 'total_amount';
    }

    /** Rider's khata remaining (open cash bills minus partial receipts). */
    public function openCashRemaining(): float
    {
        return (float) $this->openCashBills()
            ->selectRaw('COALESCE(SUM(' . self::remainingExpr('pos_transactions') . '), 0) as rem')
            ->value('rem');
    }

    /**
     * Great-circle distance in km between two lat/lng points (haversine).
     * Used by the deliveries-board "nearest free rider" hint (Task 1104):
     * denormalized pos_riders.last_lat/lng vs the saved shop pin.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
