<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Biometric Hazri sync (4 Aug 2026): maps device employee PIN → POS user.
class PosBiometricUserMap extends Model
{
    // Migration creates singular table name; override Eloquent's default pluralisation.
    protected $table = 'pos_biometric_user_map';

    protected $fillable = [
        'company_id', 'device_id', 'device_pin', 'user_id',
    ];

    public function device()
    {
        return $this->belongsTo(PosBiometricDevice::class, 'device_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
