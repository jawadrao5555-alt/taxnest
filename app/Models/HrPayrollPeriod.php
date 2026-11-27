<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class HrPayrollPeriod extends Model
{
    protected $fillable = ['month', 'created_by', 'version', 'approved_by', 'approved_at_utc'];

    protected $casts = ['month' => 'immutable_date', 'version' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(HrPayrollLine::class, 'period_id');
    }

    public function isApproved(): bool
    {
        return $this->approved_at_utc !== null;
    }
}
