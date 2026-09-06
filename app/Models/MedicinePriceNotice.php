<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Price updates" notice for a shop (Task 1579).
 *
 * Raised when a catalogue re-sync changes the MRP of a row this shop linked a
 * product to. The shop decides: apply (product MRP → new; sale price follows
 * only when it equalled the old MRP), or dismiss. Never auto-repriced.
 */
class MedicinePriceNotice extends Model
{
    protected $table = 'medicine_price_notices';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'company_id', 'product_id', 'catalogue_id', 'price_id', 'old_mrp', 'new_mrp',
        'effective_date', 'status', 'acted_by', 'acted_at',
    ];

    protected $casts = [
        'old_mrp' => 'decimal:2',
        'new_mrp' => 'decimal:2',
        'effective_date' => 'date',
        'acted_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function entry()
    {
        return $this->belongsTo(MedicineCatalogueEntry::class, 'catalogue_id');
    }

    public static function pendingCountFor(int $companyId): int
    {
        return static::where('company_id', $companyId)->where('status', self::STATUS_PENDING)->count();
    }
}
