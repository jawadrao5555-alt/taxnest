<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyGreetingService
{
    public const EVENTS = ['welcome', 'birthday', 'anniversary'];

    public static function validateTemplates(array $templates): array
    {
        foreach ($templates as $event => $text) {
            if (! in_array($event, self::EVENTS, true) || ! is_string($text) || mb_strlen($text) > 500 || trim($text) === '') {
                throw ValidationException::withMessages(['templates' => 'Use a supported event and a message of 1–500 characters.']);
            }
            preg_match_all('/\{[^}]*\}/', $text, $matches);
            if (array_diff($matches[0], ['{name}', '{company}'])) {
                throw ValidationException::withMessages(['templates' => 'Only {name} and {company} placeholders are allowed.']);
            }
        }

        return $templates;
    }

    public static function render(string $text, string $name, string $company): string
    {
        return strtr($text, ['{name}' => $name, '{company}' => $company]);
    }

    public function due(object $recipient, CarbonImmutable $today): bool
    {
        $date = CarbonImmutable::parse($recipient->event_date, $today->timezone);
        if ($date->isAfter($today->endOfDay())) {
            return false;
        }
        if ($recipient->event === 'welcome') {
            return $date->toDateString() === $today->toDateString();
        }
        // Explicit policy: February 29 occurs on February 28 in non-leap years.
        $day = $date->month === 2 && $date->day === 29 && ! $today->isLeapYear() ? 28 : $date->day;

        return $today->month === $date->month && $today->day === $day;
    }

    public function run(CarbonImmutable $now): void
    {
        foreach (DB::table('greeting_preferences')->where('enabled', true)->get() as $pref) {
            $today = $now->setTimezone($pref->timezone);
            foreach (DB::table('greeting_recipients')->where('company_id', $pref->company_id)->where('email_consent', true)->get() as $recipient) {
                if (! $this->due($recipient, $today)) {
                    continue;
                }
                $key = ['company_id' => $pref->company_id, 'recipient_id' => $recipient->id, 'event' => $recipient->event, 'channel' => 'email', 'occurrence' => $today->toDateString()];
                DB::table('greeting_deliveries')->insertOrIgnore($key + ['status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (DB::table('greeting_deliveries')->where('status', 'pending')->where('attempts', '<', 3)->pluck('id') as $id) {
            $this->dispatch((int) $id, $now);
        }
    }

    public function dispatch(int $id, CarbonImmutable $now): void
    {
        // Commit the claim before any transport call: a crash stays HOLD/sending, never pending.
        if (! DB::table('greeting_deliveries')->where('id', $id)->where('status', 'pending')->where('attempts', '<', 3)->update(['status' => 'sending', 'updated_at' => $now])) {
            return;
        }
        DB::transaction(function () use ($id, $now) {
            $delivery = DB::table('greeting_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== 'sending' || $delivery->attempts >= 3) {
                return;
            }
            $pref = DB::table('greeting_preferences')->where('company_id', $delivery->company_id)->lockForUpdate()->first();
            $recipient = DB::table('greeting_recipients')->where('id', $delivery->recipient_id)->where('company_id', $delivery->company_id)->lockForUpdate()->first();
            $templates = $pref ? json_decode($pref->templates, true) : [];
            $update = fn ($values) => DB::table('greeting_deliveries')->where('id', $id)->update($values + ['updated_at' => $now]);
            if (! $pref || ! $pref->enabled || ! $recipient || ! $recipient->email_consent || ! isset($templates[$delivery->event])) {
                $update(['status' => 'cancelled', 'reason' => 'consent_or_preference_unavailable']);

                return;
            }
            if ($recipient->event !== $delivery->event || ! $this->due($recipient, $now->setTimezone($pref->timezone)) || $now->setTimezone($pref->timezone)->toDateString() !== $delivery->occurrence) {
                $update(['status' => 'cancelled', 'reason' => 'occurrence_expired']);

                return;
            }
            $transport = app(GreetingTransport::class);
            if (! $transport->available()) {
                $update(['status' => $delivery->attempts >= 2 ? 'blocked' : 'pending', 'attempts' => $delivery->attempts + 1, 'reason' => 'provider_unavailable']);

                return;
            }
            $company = DB::table('companies')->where('id', $delivery->company_id)->value('name');
            if (! $company) {
                $update(['status' => 'cancelled', 'reason' => 'company_unavailable']);

                return;
            }
            $update(['status' => 'sending', 'attempts' => $delivery->attempts + 1]);
            try {
                $transport->send($recipient->email, self::render($templates[$delivery->event], $recipient->name, $company));
                $update(['status' => 'sent', 'sent_at' => $now, 'reason' => null]);
            } catch (\Throwable $e) {
                $update(['status' => 'hold', 'reason' => 'delivery_outcome_unknown']);
            }
        }, 1); // Never retry a transaction containing an external delivery.
    }
}
