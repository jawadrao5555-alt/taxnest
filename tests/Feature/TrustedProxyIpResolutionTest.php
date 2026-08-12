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
