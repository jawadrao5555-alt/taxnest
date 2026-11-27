<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const APPROVED_BILLING_RATE_CARD_APPLIED = 'billing_approved_rate_card_applied';

    public const ERP_HEALTH_SIGNUP = 'erp_health_signup_enabled';

    public const ERP_SCHOOL_SIGNUP = 'erp_school_signup_enabled';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    public static function get($key, $default = null)
    {
        try {
            $setting = static::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function set($key, $value, $description = null)
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description]
        );
    }
}
