<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosCallbackDiagnostic extends Model
{
    protected $fillable = [
        'company_id',
        'transaction_id',
        'source',
        'agent_version',
        'ims_version',
        'requested_environment',
        'client_environment',
        'callback_received_at',
        'success',
        'offline',
        'response_code',
        'invoice_number_field',
        'central_sync_status',
        'central_reference',
        'response_diagnostics',
        'error_message',
    ];

    protected $casts = [
        'callback_received_at' => 'datetime',
        'success' => 'boolean',
        'offline' => 'boolean',
        'response_diagnostics' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transaction()
    {
        return $this->belongsTo(FbrPosTransaction::class, 'transaction_id');
    }
}