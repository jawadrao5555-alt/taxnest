<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One accounting month (Task 1552).
 *
 * The period, not the year, is what gets closed. A hospital reconciles monthly
 * and cannot wait until the financial year ends to stop people back-dating into
 * January — by then the statements built on January are already out in the
 * world.
 *
 * A closed period accepts nothing new. A correction that arrives afterwards is
 * posted as an ADJUSTMENT in an open period, carrying the closed period it
 * corrects, so the month's own report can show both the frozen figure and what
 * arrived after the door shut.
 */
class HealthFiscalPeriod extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'name',
        'starts_on',
        'ends_on',
        'status',
        'closed_at',
        'closed_by',
        'close_note',
        'closing_snapshot',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'closed_at' => 'datetime',
        'closing_snapshot' => 'array',
        'company_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function journals()
    {
        return $this->hasMany(HealthJournal::class, 'health_fiscal_period_id');
    }
}
