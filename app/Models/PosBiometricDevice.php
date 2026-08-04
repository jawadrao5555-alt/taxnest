<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Biometric Hazri sync (4 Aug 2026): one row per registered device per company.
class PosBiometricDevice extends Model
{
    protected $fillable = [
        'company_id', 'label', 'device_sn', 'push_token', 'is_active',
        'last_push_ip', 'last_push_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_push_at' => 'datetime',
    ];

    public function userMaps()
    {
        return $this->hasMany(PosBiometricUserMap::class, 'device_id');
    }

    public function punches()
    {
        return $this->hasMany(PosBiometricPunch::class, 'device_id');
    }

    /** Generate a cryptographically random URL-safe token. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(24)); // 48-char hex
    }
}
