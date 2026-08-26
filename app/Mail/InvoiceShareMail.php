<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Mail\Mailable;

/**
 * Buyer-facing invoice email: branded HTML + public share link + the B/W
 * invoice PDF attached. Sent SYNCHRONOUSLY via the existing noreply SMTP
 * (no queue worker on cPanel) — intentionally NOT ShouldQueue, same as
 * TrialReminderMail. Callers wrap in try/catch + MailHealth bookkeeping.
 *
 * Owner rule: ALL buyer-facing content (email body + PDF) stays ENGLISH;
 * Roman Urdu is only for in-app UI labels.
 *
 * The PDF is rendered lazily inside build() so Mail::fake() in tests never
 * pays the DomPDF cost. A PDF render failure intentionally fails the whole
 * send (no silent "email without attachment" fallback).
 */
class InvoiceShareMail extends Mailable
{
    public function __construct(
        public Invoice $invoice,
        public string $shareUrl,
    ) {
    }

    public function build()
    {
        // Branding view resolves DiBrandingService::forCompany($invoice->company)
        // at render time — make sure the relation is loaded.
        $this->invoice->loadMissing('company', 'branch');

        $number = $this->invoice->display_invoice_number;
        $companyName = \App\Support\InvoiceSellerIdentity::for($this->invoice)['name'];

        $mail = $this->subject("Invoice {$number} from {$companyName}")
            ->view('emails.invoice-delivery')
            ->text('emails.invoice-share-text');

        $mail->attachData(
            InvoicePdfService::renderBw($this->invoice)->output(),
            InvoicePdfService::filename($this->invoice),
            ['mime' => 'application/pdf']
        );

        return $mail;
    }
}
