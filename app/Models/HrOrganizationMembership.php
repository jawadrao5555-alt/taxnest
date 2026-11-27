<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrOrganizationMembership extends Model
{
    protected $fillable = ['organization_id','user_id','role','active'];
    protected $casts = ['active' => 'boolean'];

    public function organization(): BelongsTo { return $this->belongsTo(HrOrganization::class, 'organization_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
