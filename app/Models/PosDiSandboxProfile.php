<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Opt-in pilot settings; never reuse or change the company's live fiscal setup. */
class PosDiSandboxProfile extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['sandbox_token', 'production_token'];
    protected $casts = [
        'enabled' => 'boolean', 'sandbox_token' => 'encrypted',
        'production_token' => 'encrypted', 'production_confirmed_at' => 'datetime',
    ];
}
