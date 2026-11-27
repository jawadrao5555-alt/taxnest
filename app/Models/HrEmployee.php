<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEmployee extends Model
{
    protected $fillable = ['organization_id','department_id','user_id','employee_number','full_name','father_name','cnic_encrypted','cnic_lookup_hash','email','phone','designation','joined_on','status'];
    protected $hidden = ['cnic_encrypted','cnic_lookup_hash'];
    protected $casts = ['joined_on'=>'date', 'cnic_encrypted'=>'encrypted'];

    public function organization(): BelongsTo { return $this->belongsTo(HrOrganization::class, 'organization_id'); }
    public function department(): BelongsTo { return $this->belongsTo(HrDepartment::class, 'department_id'); }
}
