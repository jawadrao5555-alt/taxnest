<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPolicyVersion extends Model
{
    protected $fillable = ['organization_id','version','effective_from','rules','created_by'];
    protected $casts = ['rules'=>'array','effective_from'=>'date'];
    public function organization(): BelongsTo { return $this->belongsTo(HrOrganization::class, 'organization_id'); }
}
