<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cash settlement event: the rider handed over cash for a set of bills.
 * Bills point back via pos_transactions.rider_settlement_id.
 */
class PosRiderSettlement extends Model
{
    protected $fillable = [
        'company_id', 'rider_id', 'settled_by', 'total_amount', 'bill_count', 'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function rider()
    {
        return $this->belongsTo(PosRider::class, 'rider_id');
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function bills()
    {
        return $this->hasMany(PosTransaction::class, 'rider_settlement_id');
    }
}
