<?php

namespace Tests\Feature;

use App\Http\Middleware\RejectCrossSitePosWrites;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `pos/*` stays CSRF-exempt (Android/WebView shells post the session cookie
 * without a CSRF token). Defence-in-depth: RejectCrossSitePosWrites refuses
 * a write a modern browser has labelled cross-site, or an Origin host that
 * does not match the application host. Requests with neither header stay
 * allowed so Kotlin / older WebViews keep working.
 */
class PosCsrfDefenceTest extends TestCase
{
    private function throughGuard(Request $request): int
    {
        try {
            $response = (new RejectCrossSitePosWrites())->handle($request, fn () => response('ok', 200));
            return $response->getStatusCode();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    private function posPost(array $server): Request
    {
        return Request::create('http://127.0.0.1/pos/receipt-settings', 'POST', [], [], [], $server);
    }

    public function test_same_origin_post_is_not_blocked_by_the_cross_site_guard(): void
    {
        $this->assertSame(200, $this->throughGuard($this->posPost([
            'HTTP_HOST' => '127.0.0.1',
            'HTTP_ORIGIN' => 'http://127.0.0.1',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ])));
    }

    public function test_origin_host_mismatch_is_rejected(): void
    {
        $this->assertSame(403, $this->throughGuard($this->posPost([
            'HTTP_HOST' => '127.0.0.1',
            'HTTP_ORIGIN' => 'https://evil.example',
        ])));

        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->post('/pos/receipt-settings')
            ->assertForbidden();
    }

    public function test_sec_fetch_site_cross_site_is_rejected(): void
    {
        $this->assertSame(403, $this->throughGuard($this->posPost([
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ])));

        $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])
            ->post('/pos/receipt-settings')
            ->assertForbidden();
    }

    public function test_request_without_origin_or_sec_fetch_site_is_allowed_through_the_guard(): void
    {
        $this->assertSame(200, $this->throughGuard($this->posPost([])));

        $response = $this->post('/pos/receipt-settings');
        $this->assertNotSame(403, $response->getStatusCode(),
            'WebView/Kotlin posts without Origin/Sec-Fetch-Site must not be blocked here');
    }

    public function test_non_pos_routes_are_not_affected(): void
    {
        $login = Request::create('http://127.0.0.1/login', 'POST', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);
        $this->assertSame(200, $this->throughGuard($login));
    }

    public function test_safe_methods_on_pos_are_not_blocked(): void
    {
        $get = Request::create('http://127.0.0.1/pos/login', 'GET', [], [], [], [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);
        $this->assertSame(200, $this->throughGuard($get));
    }
}
