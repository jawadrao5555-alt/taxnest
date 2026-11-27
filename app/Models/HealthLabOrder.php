<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthLabOrder extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'health_department_id', 'health_patient_id', 'health_visit_id',
        'order_no', 'request_key', 'request_fingerprint', 'ordered_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public static function visibleTo(User $user): Builder
    {
        $query = HealthScopeService::apply(self::query()->where('company_id', $user->company_id), $user);

        return HealthRecordAccessService::hideConfidential($query, $user);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(HealthPatient::class, 'health_patient_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HealthLabOrderItem::class);
    }
}
