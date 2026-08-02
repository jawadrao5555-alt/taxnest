<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Client-generated single-use invite code — the client-consent path for
 * linking a consultant. Redeeming a valid code activates the link
 * immediately (consent was given by generating the code).
 */
class ConsultantInvite extends Model
{
    protected $fillable = [
        'company_id',
        'code',
        'created_by_user_id',
        'expires_at',
        'used_by_user_id',
        'used_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isRedeemable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
