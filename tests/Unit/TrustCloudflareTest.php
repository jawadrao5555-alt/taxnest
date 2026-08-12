<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustCloudflare;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class TrustCloudflareTest extends TestCase
{
    public function test_cf_ranges_match(): void
    {
        // Inside published Cloudflare ranges
        $this->assertTrue(TrustCloudflare::isCloudflareIp('104.16.1.1'));
        $this->assertTrue(TrustCloudflare::isCloudflareIp('172.71.90.5'));
        $this->assertTrue(TrustCloudflare::isCloudflareIp('162.158.0.1'));
        $this->assertTrue(TrustCloudflare::isCloudflareIp('2606:4700::1'));

        // Outside
        $this->assertFalse(TrustCloudflare::isCloudflareIp('66.29.138.229')); // origin server
        $this->assertFalse(TrustCloudflare::isCloudflareIp('39.50.1.1'));     // PK visitor
        $this->assertFalse(TrustCloudflare::isCloudflareIp('127.0.0.1'));
        $this->assertFalse(TrustCloudflare::isCloudflareIp('not-an-ip'));
    }

    public function test_overrides_remote_addr_only_for_cf_peer(): void
    {
        $mw = new TrustCloudflare();

        // Peer IS Cloudflare → REMOTE_ADDR replaced with CF-Connecting-IP
        $req = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '104.16.1.1']);
        $req->headers->set('CF-Connecting-IP', '39.50.22.33');
        $mw->handle($req, fn ($r) => new \Symfony\Component\HttpFoundation\Response());
        $this->assertSame('39.50.22.33', $req->server->get('REMOTE_ADDR'));

        // Peer is NOT Cloudflare → header ignored (spoof attempt)
        $req2 = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '5.6.7.8']);
        $req2->headers->set('CF-Connecting-IP', '1.2.3.4');
        $mw->handle($req2, fn ($r) => new \Symfony\Component\HttpFoundation\Response());
        $this->assertSame('5.6.7.8', $req2->server->get('REMOTE_ADDR'));

        // Host-already-restored shape: peer == CF-Connecting-IP, polluted XFF
        // ("client, edge") must be normalized to just the client.
        $req2b = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '39.50.22.33']);
        $req2b->headers->set('CF-Connecting-IP', '39.50.22.33');
        $req2b->headers->set('X-Forwarded-For', '39.50.22.33, 104.22.56.202');
        $mw->handle($req2b, fn ($r) => new \Symfony\Component\HttpFoundation\Response());
        $this->assertSame('39.50.22.33', $req2b->server->get('REMOTE_ADDR'));
        $this->assertSame('39.50.22.33', $req2b->headers->get('X-Forwarded-For'));

        // Direct spoof against the host-restored shape: peer (attacker's real
        // IP) != header and not CF → untouched.
        $req2c = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '5.6.7.8']);
        $req2c->headers->set('CF-Connecting-IP', '1.2.3.4');
        $mw->handle($req2c, fn ($r) => new \Symfony\Component\HttpFoundation\Response());
        $this->assertSame('5.6.7.8', $req2c->server->get('REMOTE_ADDR'));

        // Garbage header value → ignored even from CF peer
        $req3 = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '104.16.1.1']);
        $req3->headers->set('CF-Connecting-IP', 'evil"payload');
        $mw->handle($req3, fn ($r) => new \Symfony\Component\HttpFoundation\Response());
        $this->assertSame('104.16.1.1', $req3->server->get('REMOTE_ADDR'));
    }
}
