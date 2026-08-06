<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosShift extends Model
{
    protected $fillable = [
        'company_id', 'terminal_id', 'user_id', 'opened_at', 'closed_at',
        'opening_cash', 'expected_cash', 'closing_cash', 'variance',
        'total_sales', 'total_cash', 'total_card', 'total_other', 'total_udhaar', 'total_returns',
        'sales_count', 'returns_count', 'notes', 'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'closing_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_cash' => 'decimal:2',
        'total_card' => 'decimal:2',
        'total_other' => 'decimal:2',
        'total_udhaar' => 'decimal:2',
        'total_returns' => 'decimal:2',
    ];

    public function movements()
    {
        return $this->hasMany(FbrPosCashMovement::class, 'shift_id');
    }
}
