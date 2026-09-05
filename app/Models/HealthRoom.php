<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A room inside a ward.
 *
 * Rates are NULLABLE and null means "inherit the ward", not "free". Every rate
 * lookup goes through HealthBed::resolvedDailyRate() so that inheritance is
 * decided in exactly one place.
 */
class HealthRoom extends Model
{
    public const TYPES = ['general', 'semi_private', 'private', 'deluxe', 'suite', 'icu', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'health_ward_id',
        'name',
        'room_type',
        'daily_rate',
        'nursing_daily_rate',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'daily_rate' => 'decimal:2',
        'nursing_daily_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_ward_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function ward()
    {
        return $this->belongsTo(HealthWard::class, 'health_ward_id');
    }

    public function beds()
    {
        return $this->hasMany(HealthBed::class, 'health_room_id');
    }

    public static function isType(?string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.room_type_' . (self::isType($type) ? $type : 'other');
    }
}
