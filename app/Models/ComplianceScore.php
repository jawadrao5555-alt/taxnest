<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceScore extends Model
{
    protected $fillable = [
        'company_id',
        'score',
        'success_rate',
        'retry_ratio',
        'draft_aging',
        'failure_ratio',
        'category',
        'calculated_date',
    ];

    protected $casts = [
        'calculated_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
