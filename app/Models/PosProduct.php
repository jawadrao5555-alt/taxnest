<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProduct extends Model
{
    protected $fillable = [
        'company_id', 'name', 'description', 'price', 'cost_price', 'tax_rate',
        'stock_quantity', 'low_stock_threshold',
        'hs_code', 'uom', 'category', 'image', 'sku', 'barcode', 'is_active', 'show_on_sale', 'is_tax_exempt',
        'batch_number', 'expiry_date', 'drug_type', 'prescription_required',
        'weight_based', 'unit_type', 'size', 'color', 'season',
        'serial_number', 'warranty_months', 'imei',
        'bulk_discount_qty', 'bulk_discount_pct',
        'service_duration', 'staff_assignment',
        'vehicle_make', 'vehicle_model', 'part_number',
        'custom_order', 'box_type',
        'is_third_schedule',
    ];

    protected $casts = [
        'price' => 'float',
        'cost_price' => 'float',
        'tax_rate' => 'float',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_active' => 'boolean',
        'show_on_sale' => 'boolean',
        'is_tax_exempt' => 'boolean',
        'is_third_schedule' => 'boolean',
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
            // ---- Service businesses (PRA panel) ----
            'salon' => ['service_duration', 'staff_assignment'],
            'gym' => ['service_duration', 'staff_assignment'],
            'laundry' => ['service_duration', 'unit_type'],
            'workshop' => ['service_duration', 'staff_assignment', 'vehicle_make', 'vehicle_model'],
            'restaurant' => [],
            'cafe' => [],
            'quick_service' => [],
            'hotel' => ['service_duration'],
            'marquee' => ['service_duration'],
            'catering' => ['weight_based', 'unit_type'],

            // Second Schedule service families added Sep 2026. A service item
            // is not a retail SKU: duration-based services carry how long the
            // job runs and who it is assigned to, courier work carries the
            // parcel's weight/unit, and rent-a-car carries the vehicle itself.
            // Anything that genuinely needs nothing extra stays empty rather
            // than borrowing a goods field.
            'courier' => ['weight_based', 'unit_type'],
            'photography' => ['service_duration', 'staff_assignment'],
            'event_management' => ['service_duration', 'staff_assignment'],
            'travel_agent' => ['service_duration'],
            'rent_a_car' => ['service_duration', 'vehicle_make', 'vehicle_model'],
            'property_dealer' => [],
            'advertising' => ['service_duration'],
            'it_services' => ['service_duration', 'staff_assignment'],
            'security_services' => ['service_duration', 'staff_assignment'],

            // ---- Goods businesses (FBR panel) ----
            'pharmacy' => ['batch_number', 'expiry_date', 'drug_type', 'prescription_required'],
            'grocery' => ['weight_based', 'unit_type', 'barcode'],
            'clothing' => ['size', 'color', 'season'],
            'electronics' => ['serial_number', 'warranty_months', 'imei'],
            'hardware' => ['unit_type', 'bulk_discount_qty', 'bulk_discount_pct'],
            'autoparts' => ['vehicle_make', 'vehicle_model', 'part_number'],
            'bakery' => ['weight_based', 'custom_order', 'box_type'],
            'retail' => [],
        ];
    }
}
