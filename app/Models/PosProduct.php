<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProduct extends Model
{
    protected $fillable = [
        'company_id', 'name', 'description', 'price', 'tax_rate',
        'hs_code', 'uom', 'category', 'image', 'sku', 'barcode', 'is_active', 'is_tax_exempt',
        'batch_number', 'expiry_date', 'drug_type', 'prescription_required',
        'weight_based', 'unit_type', 'size', 'color', 'season',
        'serial_number', 'warranty_months', 'imei',
        'bulk_discount_qty', 'bulk_discount_pct',
        'service_duration', 'staff_assignment',
        'vehicle_make', 'vehicle_model', 'part_number',
        'custom_order', 'box_type',
    ];

    protected $casts = [
        'price' => 'float',
        'tax_rate' => 'float',
        'is_active' => 'boolean',
        'is_tax_exempt' => 'boolean',
        'prescription_required' => 'boolean',
        'weight_based' => 'boolean',
        'custom_order' => 'boolean',
        'expiry_date' => 'date',
        'warranty_months' => 'integer',
        'service_duration' => 'integer',
        'bulk_discount_qty' => 'integer',
        'bulk_discount_pct' => 'float',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public static function categoryFields(): array
    {
        return [
            'pharmacy' => ['batch_number', 'expiry_date', 'drug_type', 'prescription_required'],
            'grocery' => ['weight_based', 'unit_type', 'barcode'],
            'clothing' => ['size', 'color', 'season'],
            'electronics' => ['serial_number', 'warranty_months', 'imei'],
            'hardware' => ['unit_type', 'bulk_discount_qty', 'bulk_discount_pct'],
            'salon' => ['service_duration', 'staff_assignment'],
            'autoparts' => ['vehicle_make', 'vehicle_model', 'part_number'],
            'bakery' => ['weight_based', 'custom_order', 'box_type'],
            'retail' => [],
            'restaurant' => [],
        ];
    }
}
