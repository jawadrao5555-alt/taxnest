<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable customer-spend ledger row written just BEFORE a local bill (deliberate
 * provisional or reporting-OFF final) is DELETED at day-close, when the company's
 * "customer spend persist" setting is ON. Keeps per-customer purchase history alive
 * after the underlying pos_transactions row is gone.
 *
 * Never updated after insert; merged (read-only) into customer history views/CSV/PDF.
 */
class PosCustomerSpendSnapshot extends Model
{
    protected $fillable = [
        'company_id', 'customer_id', 'customer_phone', 'customer_name',
        'invoice_number', 'bill_kind', 'payment_method',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
        'sold_at', 'dayclose_report_id',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];
}
