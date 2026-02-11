<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceReport extends Model
{
    protected $fillable = [
        'company_id',
        'invoice_id',
        'rule_flags',
        'anomaly_flags',
        'final_score',
        'risk_level',
    ];

    protected $casts = [
        'rule_flags' => 'array',
        'anomaly_flags' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
