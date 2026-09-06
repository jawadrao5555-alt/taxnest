<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only MRP history of a catalogue row (Task 1579).
 *
 * A re-sync that finds a different notified price NEVER overwrites silently:
 * it writes one of these (old → new, effective date, which run) and the shop
 * side turns it into a "Price updates" notice for every linked product.
 */
class MedicineCataloguePrice extends Model
{
    protected $table = 'medicine_catalogue_prices';

    public $timestamps = false;

    protected $fillable = [
        'catalogue_id', 'old_mrp', 'new_mrp', 'old_effective_date', 'effective_date',
        'source', 'sync_id', 'created_at',
    ];

    protected $casts = [
        'old_mrp' => 'decimal:2',
        'new_mrp' => 'decimal:2',
        'old_effective_date' => 'date',
        'effective_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function entry()
    {
        return $this->belongsTo(MedicineCatalogueEntry::class, 'catalogue_id');
    }
}
