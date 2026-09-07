<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveOpsAgentCommand extends Model
{
    protected $table = 'live_ops_agent_commands';

    protected $fillable = [
        'command_id',
        'command_type',
        'company_id',
        'device_uid',
        'payload',
        'idempotency_key',
        'status',
        'expires_at',
        'acked_at',
        'completed_at',
        'result',
        'requested_by',
        'remediation_action_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'expires_at' => 'datetime',
        'acked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
