<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveOpsRemediationRequest extends Model
{
    protected $table = 'live_ops_remediation_requests';

    protected $fillable = [
        'action_id',
        'action',
        'risk',
        'company_id',
        'parameters',
        'parameters_hash',
        'idempotency_key',
        'requester',
        'proposal',
        'evidence',
        'status',
        'approved_by',
        'approved_at',
        'owner_approval_phrase',
        'execution_result',
        'verification_result',
        'executed_at',
        'expires_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'evidence' => 'array',
        'execution_result' => 'array',
        'verification_result' => 'array',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
