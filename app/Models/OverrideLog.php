<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverrideLog extends Model
{
    protected $fillable = ['invoice_id', 'company_id', 'user_id', 'action', 'reason', 'metadata', 'ip_address'];

    protected $casts = ['metadata' => 'array'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function user() { return $this->belongsTo(User::class); }
}
