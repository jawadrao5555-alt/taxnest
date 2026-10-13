<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceImportSourceReceipt extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['audit' => 'array'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
