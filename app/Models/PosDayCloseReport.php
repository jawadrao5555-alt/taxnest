<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDayCloseReport extends Model
{
    protected $fillable = [
        'company_id', 'report_date', 'report_number',
        'total_invoices', 'pra_invoices', 'local_invoices', 'offline_invoices',
        'gross_sales', 'total_discount', 'net_sales', 'total_tax', 'total_amount',
        'cash_amount', 'card_amount', 'other_amount',
        'first_invoice_number', 'last_invoice_number',
        'first_invoice_time', 'last_invoice_time',
        'closed_by', 'notes', 'hash',
        'deleted_final_count', 'deleted_provisional_count', 'local_summary',
        // Per-stream figures (Task 660): PRA vs Local vs Exempt split with
        // payment buckets + exempt item detail, frozen at close time.
        'stream_summary',
        'opening_float', 'counted_cash', 'expected_cash', 'cash_variance',
        'rider_summary',
        // Return / credit-note netting (Task 570).
        'returns_count', 'returns_amount',
        // Wastage (Task 596): spoiled-goods return figures on the stored Z-report.
        'wastage_count', 'wastage_amount',
    ];

    protected $casts = [
        'report_date' => 'date',
        'first_invoice_time' => 'datetime',
        'last_invoice_time' => 'datetime',
        'local_summary' => 'array',
        'stream_summary' => 'array',
        'rider_summary' => 'array',
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
