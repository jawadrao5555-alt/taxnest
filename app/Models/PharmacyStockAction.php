<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quarantine, release and write-off — with a reason and a person against it
 * (Task 1558).
 *
 * The stock effect itself still rides the normal inventory ledger (an
 * adjustment_out movement) so nothing about the shop's stock maths changes.
 * This row is the ACCOUNTABILITY record beside it: who ordered the write-off,
 * why, and which claim it was later attached to.
 */
class PharmacyStockAction extends Model
{
    public const ACTION_QUARANTINE = 'quarantine';
    public const ACTION_RELEASE = 'release';
    public const ACTION_WRITE_OFF = 'write_off';

    public const REASONS = ['expired', 'damaged', 'recalled', 'breakage', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'batch_id',
        'action',
        'quantity',
        'cost_value',
        'reason',
        'responsible_name',
        'responsible_user_id',
        'claim_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'cost_value' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
