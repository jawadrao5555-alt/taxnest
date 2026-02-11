<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorRiskProfile extends Model
{
    protected $fillable = [
        'company_id',
        'vendor_ntn',
        'vendor_name',
        'vendor_score',
        'total_invoices',
        'rejected_invoices',
        'tax_mismatches',
        'anomaly_count',
        'last_flagged_at',
    ];

    protected $casts = [
        'last_flagged_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
