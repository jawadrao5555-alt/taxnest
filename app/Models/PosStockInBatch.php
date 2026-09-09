<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosStockInBatch extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const REFERENCE_TYPE = 'stock_in';

    protected $table = 'pos_stock_in_batches';

    protected $fillable = [
        'company_id',
        'created_by',
        'branch_id',
        'reference',
        'update_cost',
        'save_supplier_code',
        'status',
        'original_filename',
        'line_count',
        'posted_at',
    ];

    protected $casts = [
        'update_cost' => 'boolean',
        'save_supplier_code' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PosStockInLine::class, 'batch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
