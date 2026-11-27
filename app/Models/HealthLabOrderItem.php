<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthLabOrderItem extends Model
{
    protected $fillable = ['company_id', 'health_lab_test_id', 'test_code', 'test_name', 'price_paisa'];

    protected $casts = ['price_paisa' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function specimens(): HasMany
    {
        return $this->hasMany(HealthLabSpecimen::class)->orderByDesc('sequence');
    }

    public function results(): HasMany
    {
        return $this->hasMany(HealthLabResult::class)->orderByDesc('revision');
    }
}
