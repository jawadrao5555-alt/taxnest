<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A decision taken against a charge after it was posted (Task 1551).
 *
 * Append-only. This table is the reason "a charge is immutable" is a fact
 * rather than a wish: nothing can move a charge's money or its regulatory
 * treatment without leaving a row here naming the amount, the reason and the
 * person who signed it.
 *
 * `from_value` / `to_value` carry the before-and-after for a reclassification,
 * which is the one adjustment that changes no money but changes what the
 * regulator is told.
 */
class HealthChargeAdjustment extends Model
{
    public const KIND_CONCESSION = 'concession';
    public const KIND_CORRECTION = 'correction';
    public const KIND_REVERSAL = 'reversal';
    public const KIND_RECLASSIFY = 'reclassify';
    public const KIND_WRITE_OFF = 'write_off';

    public const KINDS = [
        self::KIND_CONCESSION,
        self::KIND_CORRECTION,
        self::KIND_REVERSAL,
        self::KIND_RECLASSIFY,
        self::KIND_WRITE_OFF,
    ];

    protected $fillable = [
        'company_id',
        'health_charge_id',
        'kind',
        'amount',
        'from_value',
        'to_value',
        'reason',
        'approved_by',
        'created_by',
        'actor_name',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
        'company_id' => 'integer',
        'health_charge_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function charge()
    {
        return $this->belongsTo(HealthCharge::class, 'health_charge_id');
    }

    public static function kindLabelKey(?string $kind): string
    {
        return 'health.ledadj_' . (in_array($kind, self::KINDS, true) ? $kind : self::KIND_CORRECTION);
    }
}
