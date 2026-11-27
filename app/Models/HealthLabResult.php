<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

class HealthLabResult extends Model
{
    protected $fillable = [
        'company_id', 'health_lab_specimen_id', 'revision', 'version', 'value', 'unit',
        'reference', 'amendment_reason', 'entered_by', 'verified_by', 'verifier_name', 'verified_at',
    ];

    protected $casts = ['verified_at' => 'datetime', 'revision' => 'integer', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (HealthLabResult $result): void {
            if ($result->getOriginal('verified_at') !== null) {
                throw new \LogicException('A verified laboratory result requires a new revision.');
            }
        });
    }
}
