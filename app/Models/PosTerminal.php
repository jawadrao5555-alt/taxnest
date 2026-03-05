<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTerminal extends Model
{
    protected $fillable = [
        'company_id', 'terminal_name', 'terminal_code', 'location', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions()
    {
        return $this->hasMany(PosTransaction::class, 'terminal_id');
    }
}
