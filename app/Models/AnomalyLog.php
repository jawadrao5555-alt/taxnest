<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnomalyLog extends Model
{
    protected $fillable = [
        'company_id',
        'type',
        'severity',
        'description',
        'metadata',
        'resolved',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
