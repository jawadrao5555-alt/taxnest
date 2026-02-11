<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsUnmappedLog extends Model
{
    protected $table = 'hs_unmapped_log';

    protected $fillable = [
        'hs_code', 'company_id', 'invoice_id', 'frequency_count',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
