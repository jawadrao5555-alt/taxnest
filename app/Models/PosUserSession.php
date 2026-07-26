<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Staff Hazri (26 Jul 2026): POS-guard login sessions. logout_at NULL =
// user never pressed Logout — reports show last_activity_at honestly.
class PosUserSession extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'login_at', 'logout_at', 'last_activity_at', 'ip',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'logout_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
