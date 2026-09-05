<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One batch line on a distributor claim (Task 1558). */
class PharmacyClaimItem extends Model
{
    public const REASONS = ['expired', 'damaged', 'near_expiry', 'other'];

    protected $fillable = [
        'claim_id',
        'company_id',
        'product_id',
        'batch_id',
        'item_name',
        'batch_number',
        'expiry_date',
        'quantity',
        'cost_price',
        'total_amount',
        'reason',
    ];

    protected $casts = [
        'quantity' => 'float',
        'cost_price' => 'float',
        'total_amount' => 'float',
        'expiry_date' => 'date',
    ];

    public function claim()
    {
        return $this->belongsTo(PharmacyClaim::class, 'claim_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
