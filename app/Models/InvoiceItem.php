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
        'default_uom',
        'sale_type',
        'st_withheld_at_source',
        'petroleum_levy',
        'description',
        'quantity',
        'price',
        'tax'
    ];

    protected $casts = [
        'st_withheld_at_source' => 'boolean',
        'petroleum_levy' => 'float',
        'tax_rate' => 'float',
        'quantity' => 'float',
        'price' => 'float',
        'tax' => 'float',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
