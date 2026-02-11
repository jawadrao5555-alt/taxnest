<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'invoice_number',
        'status',
        'buyer_name',
        'buyer_ntn',
        'total_amount'
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function isLocked()
    {
        return $this->status === 'locked';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
