<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

class HealthLabSpecimen extends Model
{
    public const PENDING = 'pending';
    public const COLLECTED = 'collected';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'sequence', 'barcode', 'status', 'collected_by', 'collected_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = ['collected_at' => 'datetime', 'rejected_at' => 'datetime', 'sequence' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
