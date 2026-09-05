<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A closed day. branch_id NULL closes the whole organisation; a branch id
 * closes only that branch, which is how a hospital keeps its emergency wing
 * rostered on a public holiday while the OPD block is shut.
 */
class HealthHoliday extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'name', 'holiday_date', 'is_paid', 'notes',
    ];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
        'is_paid'      => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
