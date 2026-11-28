<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DistributionStockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'warehouse_id', 'item_id', 'item_unit_id', 'request_key',
        'quantity', 'unit_code', 'base_units_per_unit', 'delta_base_units',
        'balance_after_base_units', 'recorded_by',
    ];

    protected $casts = [
        'base_units_per_unit' => 'integer',
        'delta_base_units' => 'integer',
        'balance_after_base_units' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(static fn () => throw new LogicException('Distribution stock movements are append-only.'));
        static::deleting(static fn () => throw new LogicException('Distribution stock movements are append-only.'));
    }
}
