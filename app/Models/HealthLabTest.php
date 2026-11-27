<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

class HealthLabTest extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'price_paisa', 'unit_cost_paisa', 'is_active'];

    protected $casts = ['price_paisa' => 'integer', 'unit_cost_paisa' => 'integer', 'is_active' => 'boolean'];

    protected $hidden = ['unit_cost_paisa'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
