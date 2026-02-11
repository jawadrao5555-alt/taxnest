<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverrideUsageLog extends Model
{
    protected $fillable = [
        'company_id', 'invoice_id', 'hs_code', 'override_layer',
        'override_source_id', 'original_values', 'overridden_values',
    ];

    protected $casts = [
        'original_values' => 'array',
        'overridden_values' => 'array',
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
