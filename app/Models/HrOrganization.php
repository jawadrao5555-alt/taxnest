<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrOrganization extends Model
{
    protected $fillable = ['name', 'timezone', 'status'];

    public function employees(): HasMany { return $this->hasMany(HrEmployee::class, 'organization_id'); }
    public function memberships(): HasMany { return $this->hasMany(HrOrganizationMembership::class, 'organization_id'); }
    public function policyVersions(): HasMany { return $this->hasMany(HrPolicyVersion::class, 'organization_id'); }
    public function payrollPeriods(): HasMany { return $this->hasMany(HrPayrollPeriod::class, 'organization_id'); }
}
