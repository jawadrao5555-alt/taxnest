<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveOpsAuditEvent extends Model
{
    protected $table = 'live_ops_audit_events';

    protected $fillable = [
        'event_type',
        'requester',
        'admin_id',
        'company_id',
        'operation',
        'action_id',
        'report_id',
        'params_hash',
        'artifact_digest',
        'result_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
