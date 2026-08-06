<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $fillable = [
        'company_id', 'floor_id', 'table_number', 'seats', 'status',
        'locked_by_user_id', 'locked_at', 'occupied_since', 'reservation_name', 'reservation_time',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'locked_at' => 'datetime',
        'occupied_since' => 'datetime',
        'reservation_time' => 'datetime',
    ];

    public function floor()
    {
        return $this->belongsTo(RestaurantFloor::class, 'floor_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    public function activeOrders()
    {
        return $this->hasMany(RestaurantOrder::class, 'table_id')
            ->whereNotIn('status', ['completed', 'cancelled']);
    }

    /** Held-only subset — used by the waiter table-picker to eager-load items. */
    public function heldOrders()
    {
        return $this->hasMany(RestaurantOrder::class, 'table_id')
            ->where('status', 'held');
    }

    public function isLocked()
    {
        if (!$this->locked_by_user_id) return false;
        if ($this->locked_at && $this->locked_at->diffInMinutes(now()) > 30) {
            $this->update(['locked_by_user_id' => null, 'locked_at' => null]);
            return false;
        }
        return true;
    }

    public function isLockedByOther($userId)
    {
        if (!$this->isLocked()) return false;
        // Loose int compare — PDO may return the column as a string on some hosts.
        return (int) $this->locked_by_user_id !== (int) $userId;
    }

    /**
     * Self-heal: free RESERVED tables whose lock went stale (30min+, same
     * threshold reserveTable uses for takeover) or that carry no lock at all.
     * A cashier who reserves from the sale screen and walks away otherwise
     * leaves the tile amber forever. Deliberately never touches 'occupied' —
     * that status belongs to the held-order lifecycle. Call before any
     * table listing/status render. Cheap no-op when nothing is stale.
     */
    public static function releaseStaleReservations($companyId): int
    {
        return static::where('company_id', $companyId)
            ->where('status', 'reserved')
            ->where(function ($q) {
                $q->whereNull('locked_at')
                    ->orWhere('locked_at', '<', now()->subMinutes(30));
            })
            ->update([
                'status' => 'available',
                'locked_by_user_id' => null,
                'locked_at' => null,
                'occupied_since' => null,
            ]);
    }
}
