<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A refund document against a pharmacy sale.
 *
 * `restock` is one decision per return: sealed goods go back onto their own
 * batch, opened or damaged goods are written off instead. Either way the money
 * and the medicine stay attributable to the person who accepted the return.
 */
class HealthPharmacyReturn extends Model
{
    protected $table = 'health_pharmacy_returns';

    protected $fillable = [
        'company_id',
        'branch_id',
        'sale_id',
        'return_number',
        'refund_amount',
        'restock',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'refund_amount' => 'float',
        'restock' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function sale()
    {
        return $this->belongsTo(HealthPharmacySale::class, 'sale_id');
    }

    public function items()
    {
        return $this->hasMany(HealthPharmacyReturnItem::class, 'return_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
