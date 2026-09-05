<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A department inside a healthcare organisation (OPD, Emergency, Pathology…).
 *
 * The second isolation boundary after branch: a nurse posted to Ward B must not
 * see Ward A's work even inside the same hospital branch. `branch_id` NULL means
 * the department is organisation-wide (small clinics that never split by branch).
 */
class HealthDepartment extends Model
{
    /** Department kinds — kept aligned with HealthModuleService::MODULES where they overlap. */
    public const TYPES = ['opd', 'ipd', 'lab', 'pharmacy', 'radiology', 'admin', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'code',
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Staff posted to this department in addition to their primary posting. */
    public function users()
    {
        return $this->belongsToMany(User::class, 'health_department_user')
            ->withTimestamps();
    }

    public static function isType(?string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.dept_type_' . (self::isType($type) ? $type : 'other');
    }
}
