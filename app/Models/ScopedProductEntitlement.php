<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ScopedProductEntitlement extends Model
{
    protected $fillable = [
        'scope_type',
        'scope_id',
        'product_key',
        'module_key',
        'source_reference',
        'starts_at_epoch',
        'ends_at_epoch',
        'revoked_at_epoch',
    ];

    protected $casts = [
        'scope_id' => 'integer',
        'starts_at_epoch' => 'integer',
        'ends_at_epoch' => 'integer',
        'revoked_at_epoch' => 'integer',
    ];
}
