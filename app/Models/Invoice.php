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
        'wht_locked',
        'retry_count',
        'last_retry_at',
        'source',
        'client_reference',
        'import_batch_id',
    ];

    protected $casts = [
        'fbr_submission_date' => 'datetime',
        'is_fbr_processing' => 'boolean',
        'wht_locked' => 'boolean',
        'total_value_excluding_st' => 'float',
        'total_sales_tax' => 'float',
        'wht_rate' => 'float',
        'wht_amount' => 'float',
        'net_receivable' => 'float',
    ];

    /**
     * OUR number for this invoice, in the short form a person can read.
     *
     * It is deliberately never FBR's number. The regulator issues a 30-odd
     * character reference and it used to headline every screen, email and
     * document, which left the shop reading a wall of digits to find one bill.
     * FBR's number is still printed and still searchable — but in its own
     * labelled place, beside the QR that verifies it, not as the invoice's name.
     */
    public function getDisplayInvoiceNumberAttribute()
    {
        $own = \App\Services\InvoiceNumberingService::shortNumber(
            $this->internal_invoice_number ?? $this->invoice_number
        );

        return $own ?? 'INV-' . $this->id;
    }

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
        static::creating(function ($invoice) {
            if (!$invoice->share_uuid) {
                $invoice->share_uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function getQrImageUrlAttribute()
    {
        // Same rule as the PDF: the QR carries the FBR invoice number alone,
        // because that is the only thing Tax Asaan can look up from a scan.
        // Rendered locally too — an invoice screen must not depend on an
        // outside image service to show its own verification code.
        $decoded = json_decode((string) $this->qr_data, true);

        $number = trim((string) ($this->fbr_invoice_number
            ?: (is_array($decoded) ? ($decoded['fbr_invoice_number'] ?? '') : $this->qr_data)));

        if ($number === '') return null;

        return \App\Support\QrImage::dataUri($number, 6) ?: null;
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

    /** Buyer send log (Email / WhatsApp) — newest first. */
    public function deliveries()
    {
        return $this->hasMany(InvoiceDelivery::class)->orderBy('created_at', 'desc')->orderBy('id', 'desc');
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
