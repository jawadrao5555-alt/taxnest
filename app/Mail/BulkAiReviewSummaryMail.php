<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Mail\Mailable;

/**
 * Task 1343: hand a Bulk AI Image Import review summary to another reviewer
 * (usually the shop's accountant) straight from TaxNest.
 *
 * Company-branded exactly like the buyer invoice email (DiBrandingService
 * accent + logo for Premium white-label companies, default TaxNest green for
 * everyone else) and English throughout — the recipient is outside the shop's
 * TaxNest account, so the owner's Roman-Urdu UI rule does not apply.
 *
 * The already-rendered PDF bytes are passed in so ONE render serves every
 * recipient of a send. Sent SYNCHRONOUSLY through the existing noreply SMTP
 * (no queue worker on cPanel) — intentionally NOT ShouldQueue, same as
 * InvoiceShareMail. The caller wraps in try/catch + MailHealth bookkeeping.
 *
 * PRIVACY: only the stored review data travels. The private source photos are
 * never attached or linked, and the email says so in plain words.
 */
class BulkAiReviewSummaryMail extends Mailable
{
    public function __construct(
        public Company $company,
        public array $report,
        public string $pdfBytes,
        public string $pdfFilename,
        public string $senderName = '',
    ) {
    }

    public function build()
    {
        $mail = $this->subject(
            'Invoice review summary (Batch #' . ($this->report['batch']['id'] ?? '') . ') from ' . $this->company->name
        )
            ->view('emails.bulk-ai-review-summary')
            ->text('emails.bulk-ai-review-summary-text');

        $mail->attachData($this->pdfBytes, $this->pdfFilename, ['mime' => 'application/pdf']);

        return $mail;
    }
}
