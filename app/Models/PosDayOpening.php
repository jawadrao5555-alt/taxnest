<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Opening Cash Balance — one row per company per business date.
 * Recorded at day start (drawer float for change); consumed by day-close
 * cash reconciliation (auto-fills opening_float on the Z-report).
 */
class PosDayOpening extends Model
{
    protected $table = 'pos_day_openings';

    protected $fillable = [
        'company_id',
        'business_date',
        'opening_cash',
        'entered_by',
        'notes',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_cash' => 'decimal:2',
    ];

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** The recorded opening for a company + date, or null. */
    public static function forDate(int $companyId, string $date): ?self
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_day_openings')) {
            return null;
        }
        return static::where('company_id', $companyId)
            ->whereDate('business_date', $date)
            ->first();
    }
}
