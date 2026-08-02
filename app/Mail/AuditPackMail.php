<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Queued "Audit Pack ready / failed" notification. Reuses the shared branded
 * TaxNest email template (emails.trial-reminder + text fallback).
 *
 * Queued on the database queue: dispatching only inserts a jobs row, so a
 * broken SMTP setup can never block or slow down pack generation. Send
 * failures surface via MailHealth from the queue worker.
 */
class AuditPackMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $subjectLine,
        public string $companyName,
        public string $headline,
        /** @var string[] plain-text paragraphs */
        public array $paragraphs,
        public string $ctaUrl,
        public string $ctaLabel,
        public string $panelName,
    ) {
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view('emails.trial-reminder')
            ->text('emails.trial-reminder-text');
    }

    /** Queue-worker send failure — record for the admin MailHealth banner. */
    public function failed(\Throwable $e): void
    {
        \App\Services\MailHealth::recordFailure('audit pack email: ' . $this->subjectLine, $e);
    }
}
