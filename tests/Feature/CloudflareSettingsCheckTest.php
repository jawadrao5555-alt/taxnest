<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the Cloudflare zone-settings guard (cloudflare:check-settings):
 *  - all settings correct => success, no API PATCH, no mail
 *  - drifted setting => PATCH desired value + "auto-fixed" admin email
 *  - PATCH rejected / read rejected => URGENT manual-fix email
 *  - retired setting (404 / unknown setting) => skipped, not an incident
 *  - token/zone unset => failure exit, no API calls, no mail
 *
 * NOTE: Mail::fake() cannot be used — MailFake::raw() is an explicit no-op.
 * Same array-transport pattern as CloudflareRocketLoaderCheckTest.
 */
class CloudflareSettingsCheckTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.cloudflare.com/client/v4/zones/zone123/settings/';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
            'services.cloudflare.api_token' => 'test-token',
            'services.cloudflare.zone_id' => 'zone123',
        ]);
        AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role' => 'super_admin',
        ]);
    }

    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    private function ok(string $id, mixed $value): array
    {
        return ['success' => true, 'result' => ['id' => $id, 'value' => $value]];
    }

    public function test_all_settings_correct_passes_silently(): void
    {
        Http::fake([
            self::BASE . 'minify' => Http::response($this->ok('minify', ['css' => 'off', 'html' => 'off', 'js' => 'off'])),
            self::BASE . 'ssl' => Http::response($this->ok('ssl', 'strict')),
            self::BASE . 'browser_cache_ttl' => Http::response($this->ok('browser_cache_ttl', 0)),
        ]);

        $this->artisan('cloudflare:check-settings')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_drifted_settings_are_patched_and_fixed_email_sent(): void
    {
        Http::fake([
            self::BASE . 'minify' => Http::sequence()
                ->push($this->ok('minify', ['css' => 'on', 'html' => 'off', 'js' => 'on']))   // GET: drifted
                ->push($this->ok('minify', ['css' => 'off', 'html' => 'off', 'js' => 'off'])), // PATCH ok
            self::BASE . 'ssl' => Http::sequence()
                ->push($this->ok('ssl', 'flexible'))  // GET: drifted
                ->push($this->ok('ssl', 'strict')),   // PATCH ok
            self::BASE . 'browser_cache_ttl' => Http::response($this->ok('browser_cache_ttl', 0)),
        ]);

        $this->artisan('cloudflare:check-settings')->assertExitCode(1);

        Http::assertSent(function ($r) {
            return $r->url() === self::BASE . 'ssl'
                && $r->method() === 'PATCH'
                && ($r->data()['value'] ?? null) === 'strict'
                && $r->hasHeader('Authorization', 'Bearer test-token');
        });
        Http::assertSent(function ($r) {
            return $r->url() === self::BASE . 'minify'
                && $r->method() === 'PATCH'
                && ($r->data()['value'] ?? null) === ['css' => 'off', 'html' => 'off', 'js' => 'off'];
        });

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('FIXED', (string) $email->getSubject());
        $body = $email->getTextBody();
        $this->assertStringContainsString('AUTOMATICALLY fixed', $body);
        $this->assertStringContainsString('SSL mode', $body);
        $this->assertStringContainsString('Auto Minify', $body);
    }

    public function test_browser_cache_ttl_drift_is_fixed_to_respect_headers(): void
    {
        Http::fake([
            self::BASE . 'minify' => Http::response($this->ok('minify', ['css' => 'off', 'html' => 'off', 'js' => 'off'])),
            self::BASE . 'ssl' => Http::response($this->ok('ssl', 'strict')),
            self::BASE . 'browser_cache_ttl' => Http::sequence()
                ->push($this->ok('browser_cache_ttl', 14400))
                ->push($this->ok('browser_cache_ttl', 0)),
        ]);

        $this->artisan('cloudflare:check-settings')->assertExitCode(1);

        Http::assertSent(fn ($r) => $r->url() === self::BASE . 'browser_cache_ttl'
            && $r->method() === 'PATCH'
            && ($r->data()['value'] ?? null) === 0);

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('FIXED', (string) $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_patch_rejected_sends_urgent_email(): void
    {
        Http::fake([
            self::BASE . 'minify' => Http::response($this->ok('minify', ['css' => 'off', 'html' => 'off', 'js' => 'off'])),
            self::BASE . 'browser_cache_ttl' => Http::response($this->ok('browser_cache_ttl', 0)),
            self::BASE . 'ssl' => Http::sequence()
                ->push($this->ok('ssl', 'flexible'))
                ->push(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']]], 403),
        ]);

        $this->artisan('cloudflare:check-settings')->assertExitCode(1);

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('URGENT', (string) $email->getSubject());
        $this->assertStringContainsString('Fix NOW', $email->getTextBody());
        $this->assertStringContainsString('SSL mode', $email->getTextBody());
    }

    public function test_read_rejected_sends_urgent_email(): void
    {
        Http::fake([
            self::BASE . 'minify' => Http::response($this->ok('minify', ['css' => 'off', 'html' => 'off', 'js' => 'off'])),
            self::BASE . 'browser_cache_ttl' => Http::response($this->ok('browser_cache_ttl', 0)),
            self::BASE . 'ssl' => Http::response(
                ['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']]],
                403
            ),
        ]);

        $this->artisan('cloudflare:check-settings')->assertExitCode(1);

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('URGENT', (string) $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_retired_setting_404_is_skipped_not_an_incident(): void
    {
        // Cloudflare deprecated Auto Minify — a 404 for it must not alert.
        Http::fake([
            self::BASE . 'minify' => Http::response(
                ['success' => false, 'errors' => [['code' => 1006, 'message' => 'Unknown setting minify']]],
                404
            ),
            self::BASE . 'ssl' => Http::response($this->ok('ssl', 'strict')),
            self::BASE . 'browser_cache_ttl' => Http::response($this->ok('browser_cache_ttl', 0)),
        ]);

        $this->artisan('cloudflare:check-settings')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_missing_config_skips_without_api_calls_or_mail(): void
    {
        config(['services.cloudflare.api_token' => '', 'services.cloudflare.zone_id' => '']);
        Http::fake();

        $this->artisan('cloudflare:check-settings')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertCount(0, $this->sentMessages());
    }
}
