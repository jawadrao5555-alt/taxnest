<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDepartment extends Model
{
    protected $fillable = ['organization_id','name'];
    public function organization(): BelongsTo { return $this->belongsTo(HrOrganization::class, 'organization_id'); }
}
