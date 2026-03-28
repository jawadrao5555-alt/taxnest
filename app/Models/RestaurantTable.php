<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $fillable = [
        'company_id', 'floor_id', 'table_number', 'seats', 'status',
        'locked_by_user_id', 'locked_at', 'reservation_name', 'reservation_time',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'locked_at' => 'datetime',
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
        return $this->locked_by_user_id !== $userId;
    }
}
