<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Biometric Hazri sync (4 Aug 2026): one row per attendance punch.
// source='adms'       → pushed by ZKTeco/compatible device via ADMS endpoint.
// source='csv_import' → imported from Excel/CSV file.
class PosBiometricPunch extends Model
{
    protected $fillable = [
        'company_id', 'device_id', 'device_pin', 'user_id',
        'punched_at', 'punch_type', 'raw_data', 'source',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
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
