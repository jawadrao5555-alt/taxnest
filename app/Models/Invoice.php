<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'invoice_number',
        'internal_invoice_number',
        'fbr_invoice_number',
        'fbr_submission_date',
        'status',
        'fbr_status',
        'integrity_hash',
        'buyer_name',
        'buyer_ntn',
        'buyer_cnic',
        'buyer_address',
        'buyer_registration_type',
        'total_amount',
        'total_value_excluding_st',
        'total_sales_tax',
        'wht_rate',
        'wht_amount',
        'net_receivable',
        'override_reason',
        'override_by',
        'submission_mode',
        'fbr_invoice_id',
        'qr_data',
        'share_uuid',
        'branch_id',
        'document_type',
        'reference_invoice_number',
        'supplier_province',
        'destination_province',
        'invoice_date',
        'submitted_at',
        'fbr_submission_hash',
        'is_fbr_processing',
    ];

    protected $casts = [
        'fbr_submission_date' => 'datetime',
        'is_fbr_processing' => 'boolean',
        'total_value_excluding_st' => 'float',
        'total_sales_tax' => 'float',
        'wht_rate' => 'float',
        'wht_amount' => 'float',
        'net_receivable' => 'float',
    ];

    public function getDisplayInvoiceNumberAttribute()
    {
        if ($this->fbr_invoice_number) {
            return $this->fbr_invoice_number;
        }
        return $this->internal_invoice_number ?? $this->invoice_number ?? 'INV-' . $this->id;
    }

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

        $decoded = json_decode($this->qr_data, true);
        if (is_array($decoded)) {
            $qrPayload = json_encode([
                'sellerNTNCNIC' => $decoded['sellerNTNCNIC'] ?? '',
                'fbr_invoice_number' => $decoded['fbr_invoice_number'] ?? '',
                'invoiceDate' => $decoded['invoiceDate'] ?? '',
                'totalValues' => $decoded['totalValues'] ?? 0,
            ]);
            return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrPayload);
        }

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

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
