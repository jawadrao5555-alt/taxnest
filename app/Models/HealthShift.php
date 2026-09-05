<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A duty shift template (Morning 08-14, Night 20-08, Split 09-13 + 17-21).
 *
 * A split duty is ONE template with two spans, never two roster rows: the
 * roster stays one row per person per day, which is what makes coverage
 * counting and the leave/holiday collision check trustworthy.
 */
class HealthShift extends Model
{
    protected $fillable = [
        'company_id', 'name', 'code',
        'start_time', 'end_time', 'second_start_time', 'second_end_time',
        'break_minutes', 'grace_in_minutes', 'grace_out_minutes',
        'crosses_midnight', 'is_on_call', 'colour', 'is_active',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'is_on_call'       => 'boolean',
        'is_active'        => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** "08:00" from a stored "08:00:00" — the form and the grid both want it. */
    public static function hhmm(?string $time): string
    {
        return $time ? substr($time, 0, 5) : '';
    }

    /** Does the first span run past midnight? Recomputed on every save. */
    public static function spansMidnight(?string $start, ?string $end): bool
    {
        if (!$start || !$end) {
            return false;
        }

        return strtotime($end) <= strtotime($start);
    }

    public function hasSecondSpan(): bool
    {
        return !empty($this->second_start_time) && !empty($this->second_end_time);
    }

    /** Rostered minutes, breaks already removed. */
    public function scheduledMinutes(): int
    {
        $minutes = self::spanMinutes($this->start_time, $this->end_time);

        if ($this->hasSecondSpan()) {
            $minutes += self::spanMinutes($this->second_start_time, $this->second_end_time);
        }

        return max(0, $minutes - (int) $this->break_minutes);
    }

    /** Minutes between two clock times, wrapping past midnight when needed. */
    public static function spanMinutes(?string $start, ?string $end): int
    {
        if (!$start || !$end) {
            return 0;
        }

        $from = strtotime('1970-01-01 ' . $start);
        $to = strtotime('1970-01-01 ' . $end);

        if ($to <= $from) {
            $to += 86400;
        }

        return (int) round(($to - $from) / 60);
    }
}
