<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves the trusted-proxy boundary end-to-end through the real HTTP kernel
 * (TrustCloudflare + trustProxies config from bootstrap/app.php):
 *
 *  - A direct-origin attacker CANNOT change Request::ip() (and therefore
 *    IP-keyed login throttles / audit logs) with a forged X-Forwarded-For
 *    or CF-Connecting-IP header.
 *  - Genuine Cloudflare shapes DO resolve to the real visitor IP.
 */
class TrustedProxyIpResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip-echo', fn () => response(request()->ip()));
        Route::get('/_test/url-echo', function () {
            return response()->json([
                'host' => request()->getHost(),
                'port' => request()->getPort(),
                'secure' => request()->isSecure(),
                'current' => url()->current(),
                'signed' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    '_test.signed', now()->addMinutes(5)
                ),
            ]);
        });
        Route::get('/_test/signed-target', fn () => response('ok'))->name('_test.signed');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_direct_request_with_forged_xff_cannot_change_ip(): void
    {
        // Attacker connects straight to the origin (peer = their real IP)
        // and sends a forged X-Forwarded-For.
        $res = $this->get('/_test/ip-echo', [
            'X-Forwarded-For' => '1.2.3.4',
        ], /* server: REMOTE_ADDR default 127.0.0.1 */);

        $res->assertOk();
        $this->assertSame('127.0.0.1', $res->getContent(), 'Forged XFF must not become the client IP');
    }

    public function test_direct_request_with_forged_cf_header_cannot_change_ip(): void
    {
        $res = $this->withServerVariables(['REMOTE_ADDR' => '5.6.7.8'])
            ->get('/_test/ip-echo', [
                'CF-Connecting-IP' => '1.2.3.4',
                'X-Forwarded-For' => '1.2.3.4',
            ]);

        $res->assertOk();
        $this->assertSame('5.6.7.8', $res->getContent(), 'Forged CF-Connecting-IP from a non-CF peer must be ignored');
    }

    public function test_genuine_cloudflare_peer_resolves_to_real_visitor_ip(): void
    {
        // Peer is a real CF edge (104.16.0.0/13) carrying the visitor IP.
        $res = $this->withServerVariables(['REMOTE_ADDR' => '104.16.1.1'])
            ->get('/_test/ip-echo', [
                'CF-Connecting-IP' => '39.50.22.33',
                'X-Forwarded-For' => '39.50.22.33',
            ]);

        $res->assertOk();
        $this->assertSame('39.50.22.33', $res->getContent());
    }

    public function test_direct_request_cannot_poison_host_port_prefix_or_scheme(): void
    {
        // Public (non-CF) peer forging every forwarded header. None of them
        // may leak into request metadata, generated URLs, or signed URLs.
        $res = $this->withServerVariables(['REMOTE_ADDR' => '5.6.7.8'])
            ->get('http://localhost/_test/url-echo', [
                'X-Forwarded-Host' => 'evil.example',
                'X-Forwarded-Port' => '8443',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Prefix' => '/evil',
                'X-Forwarded-For' => '1.2.3.4',
            ]);

        $res->assertOk();
        $data = $res->json();
        $this->assertSame('localhost', $data['host'], 'Forged X-Forwarded-Host must not change the host');
        $this->assertSame(80, $data['port'], 'Forged X-Forwarded-Port must not change the port');
        $this->assertFalse($data['secure'], 'Forged X-Forwarded-Proto from a public non-CF peer must be ignored');
        // NB: the app force-schemes generated URLs to https (canonical),
        // so scheme in url()/signed URLs is config-driven, never attacker
        // input — the assertions here pin the HOST.
        $this->assertStringContainsString('://localhost/', $data['current']);
        $this->assertStringNotContainsString('evil', $data['current']);
        $this->assertStringContainsString('://localhost/', $data['signed'], 'Signed URLs must be generated against the real host');
        $this->assertStringNotContainsString('evil', $data['signed']);
    }

    public function test_cloudflare_shape_keeps_https_and_real_host(): void
    {
        // Genuine CF edge peer: proto stays trusted (https), host from the
        // real Host header, forged host/prefix still dropped.
        $res = $this->withServerVariables(['REMOTE_ADDR' => '104.16.1.1'])
            ->get('http://localhost/_test/url-echo', [
                'CF-Connecting-IP' => '39.50.22.33',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'evil.example',
                'X-Forwarded-Prefix' => '/evil',
            ]);

        $res->assertOk();
        $data = $res->json();
        $this->assertTrue($data['secure'], 'X-Forwarded-Proto from Cloudflare must stay honoured');
        $this->assertSame('localhost', $data['host']);
        $this->assertStringContainsString('://localhost/', $data['signed']);
        $this->assertStringNotContainsString('evil', $data['signed']);
    }

    public function test_loopback_dev_proxy_keeps_forwarded_proto(): void
    {
        // The dev preview proxy (loopback peer) needs X-Forwarded-Proto for
        // secure cookies — must remain honoured.
        $res = $this->get('http://localhost/_test/url-echo', ['X-Forwarded-Proto' => 'https']);

        $res->assertOk();
        $this->assertTrue($res->json('secure'));
    }

    public function test_host_restored_shape_with_polluted_xff_resolves_to_client_not_edge(): void
    {
        // The cPanel host's remoteip layer already rewrote REMOTE_ADDR to the
        // client but appended the CF edge IP to XFF ("client, edge").
        $res = $this->withServerVariables(['REMOTE_ADDR' => '39.50.22.33'])
            ->get('/_test/ip-echo', [
                'CF-Connecting-IP' => '39.50.22.33',
                'X-Forwarded-For' => '39.50.22.33, 104.22.56.202',
            ]);

        $res->assertOk();
        $this->assertSame('39.50.22.33', $res->getContent(), 'Must resolve to the client, never the appended CF edge IP');
    }
}
