<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosTerminal extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'terminal_name', 'terminal_code', 'location', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function transactions()
    {
        return $this->hasMany(FbrPosTransaction::class, 'terminal_id');
    }
}
