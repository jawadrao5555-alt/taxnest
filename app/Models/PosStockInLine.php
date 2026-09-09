<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosStockInLine extends Model
{
    public const TYPE_INGREDIENT = 'INGREDIENT';
    public const TYPE_ITEM = 'ITEM';

    public const MATCHED = 'MATCHED';
    public const NEEDS_CLEARANCE = 'NEEDS_CLEARANCE';
    public const INVALID = 'INVALID';
    public const SKIPPED_GATE = 'SKIPPED_GATE';

    public const REASON_MAPPED = 'Mapped from clearance';

    protected $table = 'pos_stock_in_lines';

    protected $fillable = [
        'batch_id',
        'source_row_no',
        'line_type',
        'supplier_item_code',
        'supplier_item_name',
        'nestpos_code_raw',
        'qty',
        'unit',
        'rate',
        'branch_id',
        'reference_override',
        'notes',
        'match_status',
        'matched_ingredient_id',
        'matched_product_id',
        'invalid_reason',
        'selected',
        'collapsed_into_line_id',
        'posted_at',
        'movement_id',
        'movement_table',
    ];

    protected $casts = [
        'qty' => 'float',
        'rate' => 'float',
        'selected' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PosStockInBatch::class, 'batch_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'matched_ingredient_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'matched_product_id');
    }

    public function isPostable(): bool
    {
        return $this->match_status === self::MATCHED
            && $this->posted_at === null
            && $this->collapsed_into_line_id === null
            && (float) $this->qty > 0;
    }
}
