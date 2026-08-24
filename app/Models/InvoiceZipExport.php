<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user-requested "download these invoices as a ZIP of PDFs" build.
 *
 * @see \App\Services\InvoiceZipBuilderService
 */
class InvoiceZipExport extends Model
{
    public const ACTIVE_STATUSES = ['pending', 'processing'];

    protected $fillable = [
        'company_id',
        'user_id',
        'filters',
        'scope_label',
        'status',
        'total_invoices',
        'processed_invoices',
        'failed_invoices',
        'progress',
        'max_invoice_id',
        'cursor_id',
        'failed_ids',
        'size_capped',
        'file_path',
        'file_size',
        'error_message',
        'locked_at',
        'lock_token',
        'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'failed_ids' => 'array',
        'size_capped' => 'boolean',
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

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->created_at
            ? $this->created_at->copy()->addHours(\App\Services\InvoiceZipBuilderService::RETENTION_HOURS)
            : null;
    }
}
