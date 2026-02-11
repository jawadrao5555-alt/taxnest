<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'company_id',
        'user_id',
        'action',
        'changes_json',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'changes_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
