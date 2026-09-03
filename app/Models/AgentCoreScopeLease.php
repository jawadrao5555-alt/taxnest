<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCoreScopeLease extends Model
{
    protected $fillable = [
        'company_id', 'device_uid', 'branch_id', 'user_id', 'token_hash',
        'nonce', 'allowed_actions', 'permission_version', 'expires_at', 'revoked_at',
        'signing_secret', 'last_sequence', 'last_chain_hash',
    ];
    protected $hidden = ['token_hash', 'signing_secret'];
    protected $casts = [
        'allowed_actions' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime',
        'last_sequence' => 'integer',
    ];
}