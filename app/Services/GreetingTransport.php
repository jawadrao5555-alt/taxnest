<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

class GreetingTransport
{
    public function available(): bool
    {
        return config('greetings.delivery_enabled', false) && ! in_array(config('mail.default'), [null, 'log', 'array'], true) && filled(config('mail.from.address'));
    }

    public function send(string $email, string $body): void
    {
        Mail::raw($body, fn ($message) => $message->to($email)->subject('A greeting from your company'));
    }
}
