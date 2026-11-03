<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Separate evidence only: never an Invoice, stock movement or ledger entry. */
class PosDiSandboxSubmission extends Model
{
    protected $guarded = ['id'];

    public function reportingEnvironment(): string
    {
        return $this->environment ?? 'sandbox';
    }

    public function fiscalReference(): ?string
    {
        return $this->reportingEnvironment() === 'production'
            ? $this->production_invoice_number : $this->sandbox_invoice_number;
    }

    protected $casts = [
        'snapshot' => 'encrypted:array',
        'payload' => 'encrypted:array',
        'attempts' => 'encrypted:array',
        'attempted_at' => 'datetime',
    ];
}
