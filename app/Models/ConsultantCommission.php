<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Consultant commission ledger. One row per admin-recorded payment
 * (subscription assignment) of a company the consultant referred.
 * Money history: rows survive consultant/company deletion (nullable FKs
 * + company_name snapshot). Only SaaS admin flips status to 'paid'.
 */
class ConsultantCommission extends Model
{
    protected $fillable = [
        'consultant_user_id',
        'company_id',
        'company_name',
        'subscription_id',
        'description',
        'base_amount',
        'rate_percent',
        'amount',
        'status',
        'source',
        'paid_at',
        'paid_by_admin_id',
        'payout_reference',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'rate_percent' => 'decimal:2',
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function consultant()
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
