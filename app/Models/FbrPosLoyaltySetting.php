<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosLoyaltySetting extends Model
{
    protected $fillable = [
        'company_id', 'is_enabled', 'rs_per_point', 'point_value',
        'min_redeem_points', 'points_expiry_days',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'rs_per_point' => 'decimal:2',
        'point_value' => 'decimal:2',
    ];

    public static function forCompany(int $companyId): self
    {
        return self::firstOrCreate(['company_id' => $companyId], [
            'is_enabled' => false, 'rs_per_point' => 100, 'point_value' => 1, 'min_redeem_points' => 50,
        ]);
    }
}
