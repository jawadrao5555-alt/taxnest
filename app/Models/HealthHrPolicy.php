<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Organisation-level attendance policy — one row per healthcare company.
 *
 * Everything the attendance calculation needs that is NOT a property of a
 * single shift lives here: the day boundary, grace, the half/full day
 * thresholds, which capture methods are allowed and what an odd number of
 * punches becomes. Kept in a table rather than a config file because two
 * hospitals on the same deploy legitimately answer these differently.
 */
class HealthHrPolicy extends Model
{
    protected $table = 'health_hr_policies';

    /** What a company gets before anybody opens the settings screen. */
    public const DEFAULTS = [
        'business_day_start'    => '06:00:00',
        'grace_in_minutes'      => 15,
        'grace_out_minutes'     => 10,
        'half_day_minutes'      => 240,
        'full_day_minutes'      => 480,
        'overtime_enabled'      => true,
        'min_overtime_minutes'  => 30,
        'missed_punch_status'   => 'missed_punch',
        'weekly_off_days'       => [7],
        'biometric_enabled'     => true,
        'web_checkin_enabled'   => true,
        'mobile_checkin_enabled' => true,
        'session_punch_enabled' => false,
        'geo_required'          => false,
        // geo_latitude / geo_longitude are deliberately NOT defaulted here: a
        // box whose geofence migration has not landed yet must still be able to
        // create its policy row.
        'geo_radius_m'          => 300,
        'cross_branch_allowed'  => true,
    ];

    /** What a day with an unpaired punch is recorded as. */
    public const MISSED_PUNCH_STATUSES = ['missed_punch', 'absent', 'half_day'];

    protected $fillable = [
        'company_id',
        'business_day_start',
        'grace_in_minutes', 'grace_out_minutes',
        'half_day_minutes', 'full_day_minutes',
        'overtime_enabled', 'min_overtime_minutes',
        'missed_punch_status',
        'weekly_off_days',
        'biometric_enabled', 'web_checkin_enabled', 'mobile_checkin_enabled',
        'session_punch_enabled', 'geo_required', 'geo_radius_m',
        'geo_latitude', 'geo_longitude',
        'cross_branch_allowed',
    ];

    protected $casts = [
        'weekly_off_days'        => 'array',
        'overtime_enabled'       => 'boolean',
        'biometric_enabled'      => 'boolean',
        'web_checkin_enabled'    => 'boolean',
        'mobile_checkin_enabled' => 'boolean',
        'session_punch_enabled'  => 'boolean',
        'geo_required'           => 'boolean',
        'cross_branch_allowed'   => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    /** ISO weekday numbers (1 = Monday … 7 = Sunday) that are weekly offs. */
    public function offDays(): array
    {
        $days = $this->weekly_off_days;

        if (!is_array($days)) {
            $days = self::DEFAULTS['weekly_off_days'];
        }

        return array_values(array_filter(
            array_map('intval', $days),
            fn (int $day) => $day >= 1 && $day <= 7
        ));
    }
}
