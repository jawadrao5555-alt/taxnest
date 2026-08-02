<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One "invoice sent to buyer" event (Email or WhatsApp wa.me hand-off).
 * Queried per-invoice via Invoice::deliveries(); company_id is carried for
 * the admin hard-delete purge. No CompanyScope — always accessed through an
 * already-authorized invoice.
 */
class InvoiceDelivery extends Model
{
    protected $fillable = [
        'invoice_id',
        'company_id',
        'user_id',
        'channel',    // email | whatsapp
        'recipient',  // email address or normalized international phone digits
        'status',     // sent | delivered | read | failed (delivered/read via WA Business API webhook)
        'error',
        'provider_message_id', // Meta wamid — set only on Business API direct sends
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
