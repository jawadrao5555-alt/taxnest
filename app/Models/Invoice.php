<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'invoice_number',
        'status',
        'integrity_hash',
        'buyer_name',
        'buyer_ntn',
        'total_amount',
        'override_reason',
        'override_by',
        'submission_mode',
        'fbr_invoice_id',
        'qr_data',
        'share_uuid',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($invoice) {
            if (!$invoice->share_uuid) {
                $invoice->share_uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function getQrImageUrlAttribute()
    {
        if (!$this->qr_data) return null;
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($this->qr_data);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function isLocked()
    {
        return $this->status === 'locked';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(InvoiceActivityLog::class)->orderBy('created_at', 'desc');
    }

    public function fbrLogs()
    {
        return $this->hasMany(FbrLog::class);
    }

    public function complianceReports()
    {
        return $this->hasMany(ComplianceReport::class);
    }
}
