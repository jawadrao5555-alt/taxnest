<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AgentCoreAggregateMapping extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'local_type', 'local_aggregate_id',
        'cloud_type', 'cloud_id', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}