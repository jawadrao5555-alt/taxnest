<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

class HealthLabTest extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'price_paisa', 'is_active'];

    protected $casts = ['price_paisa' => 'integer', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
