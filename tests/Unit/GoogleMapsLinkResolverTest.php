<?php

namespace Tests\Unit;

use App\Services\GoogleMapsLinkResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task #446 (ZFC): shop-pin Google Maps link resolution.
 */
class GoogleMapsLinkResolverTest extends TestCase
{
    // ---- parseCoordinates (no network) ----

    public function test_parses_at_style_full_maps_url(): void
    {
        $ll = GoogleMapsLinkResolver::parseCoordinates(
            'https://www.google.com/maps/place/ZFC+Pizza+Point/@30.8138,74.2581,17z/data=x'
        );
        $this->assertNotNull($ll);
        $this->assertEqualsWithDelta(30.8138, $ll['lat'], 0.0001);
        $this->assertEqualsWithDelta(74.2581, $ll['lng'], 0.0001);
    }

    public function test_parses_3d4d_style_url_and_prefers_it_over_at(): void
    {
        $ll = GoogleMapsLinkResolver::parseCoordinates(
            'https://www.google.com/maps/place/x/@30.80,74.20,17z/data=!3m1!4b1!4m6!3m5!3d30.8138!4d74.2581'
        );
        $this->assertEqualsWithDelta(30.8138, $ll['lat'], 0.0001);
        $this->assertEqualsWithDelta(74.2581, $ll['lng'], 0.0001);
    }

    public function test_parses_query_param_coordinates_including_encoded_comma(): void
    {
        $ll = GoogleMapsLinkResolver::parseCoordinates('https://maps.google.com/?q=31.5204%2C74.3587');
        $this->assertEqualsWithDelta(31.5204, $ll['lat'], 0.0001);
        $this->assertEqualsWithDelta(74.3587, $ll['lng'], 0.0001);
    }

    public function test_rejects_coordinates_outside_pakistan(): void
    {
        // Mumbai — clearly outside the Pakistan box; must not become a shop pin.
        $this->assertNull(GoogleMapsLinkResolver::parseCoordinates(
            'https://www.google.com/maps/@19.0760,72.8777,15z'
        ));
        // London
        $this->assertNull(GoogleMapsLinkResolver::parseCoordinates(
            'https://maps.google.com/?q=51.5072,-0.1276'
        ));
    }

    public function test_url_without_coordinates_returns_null(): void
    {
        $this->assertNull(GoogleMapsLinkResolver::parseCoordinates('https://maps.app.goo.gl/AbCdEf123'));
    }

    // ---- allowlist ----

    public function test_only_google_maps_hosts_are_resolvable(): void
    {
        $this->assertTrue(GoogleMapsLinkResolver::isResolvableUrl('https://maps.app.goo.gl/AbC'));
        $this->assertTrue(GoogleMapsLinkResolver::isResolvableUrl('https://goo.gl/maps/xyz'));
        $this->assertFalse(GoogleMapsLinkResolver::isResolvableUrl('https://evil.example.com/@31.5,74.3'));
        $this->assertFalse(GoogleMapsLinkResolver::isResolvableUrl('not a url'));
    }

    // ---- resolve (redirect chain, Http faked) ----

    public function test_resolves_redirect_only_short_link_via_location_header(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/ZFC/@30.8138,74.2581,17z/',
            ]),
        ]);

        $ll = GoogleMapsLinkResolver::resolve('https://maps.app.goo.gl/AbCdEf123');
        $this->assertNotNull($ll);
        $this->assertEqualsWithDelta(30.8138, $ll['lat'], 0.0001);
        $this->assertEqualsWithDelta(74.2581, $ll['lng'], 0.0001);
    }

    public function test_resolve_follows_multi_hop_within_allowlist(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://consent.google.com/m?continue=https%3A%2F%2Fwww.google.com%2Fmaps%2F%4030.8138%2C74.2581%2C17z',
            ]),
        ]);

        // Hop 1's Location has the coords consent-wrapped (urldecode path).
        $ll = GoogleMapsLinkResolver::resolve('https://maps.app.goo.gl/XyZ');
        $this->assertNotNull($ll);
        $this->assertEqualsWithDelta(30.8138, $ll['lat'], 0.0001);
    }

    public function test_resolve_never_fetches_non_allowlisted_host(): void
    {
        // The resolver must refuse to follow a redirect to a non-Google host.
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://evil.example.com/no-coords-here',
            ]),
        ]);
        $this->assertNull(GoogleMapsLinkResolver::resolve('https://maps.app.goo.gl/AbC'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.example.com'));
    }

    public function test_resolve_rejects_plain_http_and_foreign_start_urls(): void
    {
        Http::fake();
        $this->assertNull(GoogleMapsLinkResolver::resolve('http://maps.app.goo.gl/AbC'));
        $this->assertNull(GoogleMapsLinkResolver::resolve('https://attacker.pk/x'));
        Http::assertNothingSent();
    }

    public function test_resolve_returns_null_when_chain_ends_without_coordinates(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/somewhere']),
            'www.google.com/*' => Http::response('<html></html>', 200),
        ]);
        $this->assertNull(GoogleMapsLinkResolver::resolve('https://maps.app.goo.gl/NoCoords'));
    }
}
