<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A POS shell-app device token (Task #1142) — one row per phone that has the
 * TaxNest POS app installed and logged in. Multiple devices per user allowed
 * (waiter's phone + spare tablet). token_hash (sha256 of the FCM token) is
 * the dedupe key: the same physical device re-registering after a user
 * switch simply moves to the new user/company.
 */
class PosAppDevice extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'fcm_token',
        'token_hash',
        'app_version',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
