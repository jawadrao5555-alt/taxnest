<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Item #1 (Jul 2026) — extra saved delivery addresses for a POS customer.
 * pos_customers.address remains "address #1"; rows here are additional ones.
 * No FK (shared-table rule) — ALWAYS scope queries by company_id.
 */
class PosCustomerAddress extends Model
{
    protected $fillable = [
        'company_id', 'customer_id', 'label', 'address',
    ];

    public function customer()
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }
}
