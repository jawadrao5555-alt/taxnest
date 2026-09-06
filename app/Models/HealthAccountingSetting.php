<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The accounts module's own switches, one row per organisation (Task 1552).
 *
 * `doctor_share_basis` is the one that changes real money. BILLED accrues the
 * consultant's share the moment the bill is raised; COLLECTED waits until the
 * patient's money actually arrives. A clinic whose receivables never age is
 * happy with billed; one carrying panel patients for ninety days would be
 * paying doctors out of money it has not got.
 */
class HealthAccountingSetting extends Model
{
    public const BASIS_BILLED = 'billed';
    public const BASIS_COLLECTED = 'collected';
    public const BASES = [self::BASIS_BILLED, self::BASIS_COLLECTED];

    protected $fillable = [
        'company_id',
        'fiscal_year_start_month',
        'auto_post_enabled',
        'doctor_share_basis',
        'doctor_shares_enabled',
        'books_start_date',
        'last_posted_at',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'auto_post_enabled' => 'boolean',
        'doctor_shares_enabled' => 'boolean',
        'books_start_date' => 'date',
        'last_posted_at' => 'datetime',
        'company_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }
}
