<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Branded HTML trial-ending reminder. Sent SYNCHRONOUSLY from the
 * trial:reminders scheduled command (no queue worker required on cPanel) —
 * intentionally NOT ShouldQueue.
 */
class TrialReminderMail extends Mailable
{
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
}
