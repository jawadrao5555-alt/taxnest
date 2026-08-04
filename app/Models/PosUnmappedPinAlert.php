<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unmapped biometric device PIN alert (Task #277, Aug 2026).
 *
 * One row per (company_id, device_pin). Lifecycle:
 *   created  — first unmapped punch arrives for this PIN.
 *   mapped   — saveMapping() / quickMapPin() sets mapped_at.
 *   dismissed — admin dismisses banner; sets dismissed_at (never re-fires
 *               for the same PIN unless the alert row is hard-deleted, which
 *               happens when saveMapping() removes a PIN from a device mapping).
 */
class PosUnmappedPinAlert extends Model
{
    protected $table = 'pos_bio_pin_alerts';

    protected $fillable = [
        'company_id',
        'device_pin',
        'first_seen_at',
        'dismissed_at',
        'mapped_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'dismissed_at'  => 'datetime',
        'mapped_at'     => 'datetime',
    ];

    /** Active alerts: not yet mapped and not dismissed. */
    public function scopeActive($query)
    {
        return $query->whereNull('dismissed_at')->whereNull('mapped_at');
    }
}
