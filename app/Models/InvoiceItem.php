<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'hs_code',
        'schedule_type',
        'pct_code',
        'tax_rate',
        'sro_schedule_no',
        'serial_no',
        'mrp',
        'description',
        'quantity',
        'price',
        'tax'
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
