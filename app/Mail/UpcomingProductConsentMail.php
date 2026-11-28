<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class UpcomingProductConsentMail extends Mailable
{
    public function __construct(
        public readonly string $productName,
        public readonly string $purpose,
        public readonly string $manageUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your TaxNest product interest');
    }

    public function content(): Content
    {
        return new Content(text: 'public.upcoming-products.consent-email');
    }
}
