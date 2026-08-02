<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditPack extends Model
{
    public const ACTIVE_STATUSES = ['pending', 'processing'];

    protected $fillable = [
        'company_id',
        'user_id',
        'date_from',
        'date_to',
        'status',
        'total_invoices',
        'processed_invoices',
        'progress',
        'integrity_passed',
        'integrity_failed',
        'integrity_missing',
        'integrity_failed_list',
        'file_path',
        'file_size',
        'error_message',
        'locked_at',
        'completed_at',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'locked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at
            ? $this->created_at->copy()->addDays(\App\Services\AuditPackBuilderService::RETENTION_DAYS)
            : null;
    }
}
