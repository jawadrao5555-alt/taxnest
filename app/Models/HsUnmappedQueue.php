<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsUnmappedQueue extends Model
{
    protected $table = 'hs_unmapped_queue';

    protected $fillable = [
        'hs_code',
        'company_id',
        'usage_count',
        'first_seen_at',
        'flagged_reason',
    ];

    protected $casts = [
        'usage_count' => 'integer',
        'first_seen_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
