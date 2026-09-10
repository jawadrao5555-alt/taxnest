<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OwnerDeploymentApprovalRequest extends Model
{
    protected $table = 'owner_deployment_approval_requests';
    protected $primaryKey = 'request_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'request_id',
        'pull_request_number',
        'head_sha',
        'repository',
        'status',
        'requested_admin_id',
        'approved_admin_id',
        'approved_at',
        'expires_at',
        'dispatch_lease_id',
        'dispatch_lease_expires_at',
        'claimed_at',
        'provenance_receipt_hash',
        'provenance_receipt_used_at',
        'merge_sha',
        'workflow_run_url',
        'deploy_result',
        'deployed_sha',
        'failure_summary',
        'deployment_run_id',
        'deployment_run_attempt',
        'owner_workflow_sha','owner_workflow_run_id','owner_workflow_run_attempt',
        'handoff_nonce_hash','deployment_workflow_sha',
    ];

    protected $hidden = [
        'provenance_receipt_hash',
        'handoff_nonce_hash',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'dispatch_lease_expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'provenance_receipt_used_at' => 'datetime',
        'deployment_run_id' => 'integer',
        'deployment_run_attempt' => 'integer',
        'owner_workflow_run_id' => 'integer',
        'owner_workflow_run_attempt' => 'integer',
    ];
    protected static function booted(): void
    {
        static::creating(function (self $model) { $model->request_id ??= (string) Str::uuid(); });
    }
    public function requestedBy()
    {
        return $this->belongsTo(AdminUser::class, 'requested_admin_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(AdminUser::class, 'approved_admin_id');
    }

    public function isUnexpired(): bool
    {
        return $this->expires_at->isFuture();
    }
}