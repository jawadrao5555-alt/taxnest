<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveOpsAutonomousRequest extends Model
{
    protected $table = 'live_ops_autonomous_requests';

    public const STATUSES = [
        'INVESTIGATING',
        'DIAGNOSED',
        'FIXING',
        'TESTING',
        'READY_TO_DEPLOY',
        'DEPLOYING',
        'VERIFYING',
        'RESOLVED',
        'BLOCKED',
        'FAILED',
    ];

    protected $fillable = [
        'request_id',
        'requester',
        'intent',
        'owner_text',
        'company_id',
        'company_name',
        'operation',
        'status',
        'iteration',
        'diagnostic_report_id',
        'diagnostic_run_id',
        'root_cause',
        'risk_class',
        'fix_commit',
        'pull_request',
        'tests_result',
        'deployment_sha',
        'deployment_result',
        'live_verification',
        'owner_report',
        'error',
        'metadata',
    ];

    protected $casts = [
        'iteration' => 'integer',
        'tests_result' => 'array',
        'deployment_result' => 'array',
        'live_verification' => 'array',
        'owner_report' => 'array',
        'metadata' => 'array',
    ];
}
