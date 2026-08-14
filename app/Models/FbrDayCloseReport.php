<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrDayCloseReport extends Model
{
    protected $fillable = [
        'company_id', 'report_date', 'report_number',
        'total_invoices', 'fbr_invoices', 'local_invoices', 'failed_invoices',
        'gross_sales', 'total_discount', 'net_sales', 'total_tax', 'total_fbr_fee', 'total_amount',
        'cash_amount', 'card_amount', 'other_amount', 'udhaar_amount',
        'first_invoice_number', 'last_invoice_number',
        'first_invoice_time', 'last_invoice_time',
        'returns_count', 'returns_amount',
        'closed_by', 'notes', 'hash',
        'opening_float', 'counted_cash', 'expected_cash', 'cash_variance',
        'rider_summary', 'local_summary',
    ];

    protected $casts = [
        'report_date' => 'date',
        'rider_summary' => 'array',
        'local_summary' => 'array',
        'first_invoice_time' => 'datetime',
        'last_invoice_time' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
