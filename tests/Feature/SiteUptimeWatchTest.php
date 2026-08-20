<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the uptime watchdog (site:uptime-watch):
 *  - healthy edge => success, origin never probed, no mail
 *  - edge 525 + origin healthy (verified TLS) => CLOUDFLARE-ORIGIN-LINK (hosting)
 *  - edge 525 + origin answers ONLY with TLS verification off => ORIGIN-TLS-INVALID
 *    (expired/mismatched origin cert — the classic 525 cause, and OUR fix, so it
 *     must never be reported as a hosting-firewall problem)
 *  - edge + origin both failing => classified ORIGIN-DOWN
 *  - alert only on the SECOND consecutive failure, and only ONCE per incident
 *  - recovery email after an alerted incident; silence when never alerted
 *  - --no-mail probes and logs without emailing
 *  - non-production without --force does nothing at all
 *
 * NOTE: Mail::fake() cannot be used — MailFake::raw() is an explicit no-op.
 * Same array-transport pattern as CloudflareSettingsCheckTest.
 */
class SiteUptimeWatchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://live.example.test/up';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'services.uptime_watch.url' => self::URL,
            'services.uptime_watch.origin_ip' => '203.0.113.7',
        ]);

        AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role' => 'super_admin',
        ]);

        @unlink(storage_path('app/uptime-watch.json'));
        @unlink(storage_path('logs/uptime-watch.log'));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/uptime-watch.json'));
        @unlink(storage_path('logs/uptime-watch.log'));

        parent::tearDown();
    }

    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    private function watchLog(): string
    {
        $path = storage_path('logs/uptime-watch.log');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function probeRun(array $args = []): void
    {
        $this->artisan('site:uptime-watch', array_merge(['--force' => true], $args))->run();
    }

    public function test_healthy_site_logs_ok_probes_origin_never_and_sends_no_mail(): void
    {
        Http::fake([self::URL . '*' => Http::response('OK', 200)]);

        $this->artisan('site:uptime-watch', ['--force' => true])->assertExitCode(0);

        // Exactly one request: the origin leg must not run while healthy.
        Http::assertSentCount(1);
        $this->assertStringContainsString('| OK', $this->watchLog());
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_edge_525_with_healthy_origin_is_classified_as_cloudflare_link_and_alerts_once(): void
    {
        // Both legs hit the same URL; the origin leg is the one pinned via CURLOPT_RESOLVE.
        Http::fake([self::URL . '*' => Http::sequence()
            ->push('cf error', 525)   // run 1 edge
            ->push('OK', 200)         // run 1 origin
            ->push('cf error', 525)   // run 2 edge
            ->push('OK', 200)         // run 2 origin
            ->push('cf error', 525)   // run 3 edge
            ->push('OK', 200),        // run 3 origin
        ]);

        // First failure: counted, but too early to alert.
        $this->artisan('site:uptime-watch', ['--force' => true])->assertExitCode(1);
        $this->assertCount(0, $this->sentMessages());

        // Second consecutive failure: one alert.
        $this->probeRun();
        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('DOWN', (string) $email->getSubject());
        $this->assertStringContainsString('CLOUDFLARE-ORIGIN-LINK', (string) $email->getSubject());
        $body = $email->getTextBody();
        $this->assertStringContainsString('HOSTING-side problem', $body);
        $this->assertStringContainsString('whitelist all Cloudflare IP ranges', $body);

        // Third failure in the same incident: still exactly one email.
        $this->probeRun();
        $this->assertCount(1, $this->sentMessages());

        $this->assertStringContainsString('CLOUDFLARE-ORIGIN-LINK', $this->watchLog());
    }

    public function test_origin_answering_only_without_tls_verification_is_classified_as_bad_certificate(): void
    {
        // Per run: edge (525), origin WITH verification (TLS error), origin
        // WITHOUT verification (200) — i.e. the server is alive, the cert is not.
        Http::fake([self::URL . '*' => Http::sequence()
            ->push('cf error', 525)->pushFailedConnection('SSL certificate problem: certificate has expired')->push('OK', 200)
            ->push('cf error', 525)->pushFailedConnection('SSL certificate problem: certificate has expired')->push('OK', 200),
        ]);

        $this->probeRun();
        $this->probeRun();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('ORIGIN-TLS-INVALID', (string) $email->getSubject());
        $body = $email->getTextBody();
        $this->assertStringContainsString('SSL certificate is being REJECTED', $body);
        $this->assertStringContainsString('AutoSSL', $body);
        // Must NOT be blamed on the hosting firewall.
        $this->assertStringNotContainsString('whitelist all Cloudflare IP ranges', $body);

        $this->assertStringContainsString('INVALID TLS', $this->watchLog());
    }

    public function test_alert_delivery_failure_leaves_the_incident_unalerted_for_retry(): void
    {
        Http::fake([self::URL . '*' => Http::response('boom', 503)]);
        config(['mail.default' => 'no-such-mailer']);   // every send throws

        $this->probeRun();
        $this->probeRun();

        $state = json_decode((string) file_get_contents(storage_path('app/uptime-watch.json')), true);
        $this->assertSame(2, $state['fails']);
        // A swallowed delivery failure must not count as "already alerted",
        // otherwise the outage goes unreported and only the all-clear arrives.
        $this->assertFalse($state['alerted']);

        // Recovery with mail working again stays silent — nothing was announced.
        config(['mail.default' => 'array']);
        Http::fake([self::URL . '*' => Http::response('OK', 200)]);
        $this->probeRun();
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_both_legs_failing_is_classified_as_origin_down(): void
    {
        Http::fake([self::URL . '*' => Http::response('boom', 503)]);

        $this->probeRun();
        $this->probeRun();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ORIGIN-DOWN', (string) $messages[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString(
            'server or the application itself is down',
            $messages[0]->getOriginalMessage()->getTextBody()
        );
    }

    public function test_recovery_email_is_sent_after_an_alerted_incident(): void
    {
        Http::fake([self::URL . '*' => Http::sequence()
            ->push('cf error', 525)->push('OK', 200)   // run 1 (edge, origin)
            ->push('cf error', 525)->push('OK', 200)   // run 2 -> alert
            ->push('OK', 200),                          // run 3 -> recovered
        ]);

        $this->probeRun();
        $this->probeRun();
        $this->assertCount(1, $this->sentMessages());

        $this->artisan('site:uptime-watch', ['--force' => true])->assertExitCode(0);

        $messages = $this->sentMessages();
        $this->assertCount(2, $messages);
        $recovery = $messages[1]->getOriginalMessage();
        $this->assertStringContainsString('RECOVERED', (string) $recovery->getSubject());
        $this->assertStringContainsString('CLOUDFLARE-ORIGIN-LINK', $recovery->getTextBody());
    }

    public function test_single_blip_recovers_without_any_email(): void
    {
        Http::fake([self::URL . '*' => Http::sequence()
            ->push('cf error', 525)->push('OK', 200)   // one failed run only
            ->push('OK', 200),                          // recovered before alerting
        ]);

        $this->probeRun();
        $this->probeRun();

        // Never alerted, so the all-clear must stay silent too.
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_no_mail_option_probes_and_logs_without_emailing(): void
    {
        Http::fake([self::URL . '*' => Http::response('cf error', 525)]);

        $this->probeRun(['--no-mail' => true]);
        $this->probeRun(['--no-mail' => true]);

        $this->assertCount(0, $this->sentMessages());
        $this->assertStringContainsString('ORIGIN-DOWN', $this->watchLog());
    }

    public function test_missing_url_config_skips_without_requests_or_mail(): void
    {
        config(['services.uptime_watch.url' => '']);
        Http::fake();

        $this->artisan('site:uptime-watch', ['--force' => true])->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_non_production_without_force_does_nothing(): void
    {
        Http::fake();

        $this->artisan('site:uptime-watch')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('', $this->watchLog());
    }
}
