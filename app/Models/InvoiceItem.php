<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'fbr_reference_checked',
        'inventory_snapshot',
        'st_withheld_amount',
        'invoice_id',
        'selling_snapshot',
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
        'further_tax',
        'description',
        'quantity',
        'price',
        'tax'
    ];

    protected $casts = [
        'fbr_reference_checked' => 'boolean',
        'inventory_snapshot' => 'array',
        'selling_snapshot' => 'array',
        'st_withheld_amount' => 'float',
        'st_withheld_at_source' => 'boolean',
        'petroleum_levy' => 'float',
        'further_tax' => 'float',
        'tax_rate' => 'float',
        'quantity' => 'float',
        'price' => 'float',
        'tax' => 'float',
    ];

    public function getDiFederalExciseAttribute(): float
    {
        $rule = \App\Services\DiSpecialTax::rule($this->schedule_type);

        return $rule ? \App\Services\DiSpecialTax::excise($rule, (float) $this->quantity) : 0.0;
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
