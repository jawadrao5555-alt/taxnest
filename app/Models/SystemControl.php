<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemControl extends Model
{
    protected $fillable = ['key', 'value', 'description', 'updated_by'];

    public static function isEnabled(string $key): bool
    {
        return Cache::remember("system_control_{$key}", 60, function () use ($key) {
            $control = self::where('key', $key)->first();
            return $control ? $control->value === 'enabled' : true;
        });
    }

    public static function toggle(string $key, int $adminId): self
    {
        $control = self::where('key', $key)->firstOrFail();
        $control->update([
            'value' => $control->value === 'enabled' ? 'disabled' : 'enabled',
            'updated_by' => $adminId,
        ]);
        Cache::forget("system_control_{$key}");
        return $control;
    }

    public static function setValue(string $key, string $value, int $adminId): self
    {
        $control = self::where('key', $key)->firstOrFail();
        $control->update(['value' => $value, 'updated_by' => $adminId]);
        Cache::forget("system_control_{$key}");
        return $control;
    }
}
