<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Task #446 (ZFC): resolve a pasted Google Maps link to lat/lng.
 *
 * Normal share links from the Google Maps app (https://maps.app.goo.gl/<token>)
 * carry NO coordinates — they are redirect-only. The browser cannot follow them
 * (CORS), so the server follows the redirect chain and parses coordinates from
 * the URLs it passes through.
 *
 * SSRF safeguards:
 *  - Only https URLs whose host is on a fixed Google-Maps allowlist are fetched.
 *  - Redirect targets are re-checked against the same allowlist each hop.
 *  - Max 4 hops, no response body needed (coords come from the URL), 6s timeout.
 */
class GoogleMapsLinkResolver
{
    /** Hosts we will ever contact / follow redirects to. */
    public const ALLOWED_HOSTS = [
        'maps.app.goo.gl',
        'goo.gl',
        'g.co',
        'maps.google.com',
        'www.google.com',
        'google.com',
        'consent.google.com',
        'maps.google.com.pk',
        'www.google.com.pk',
    ];

    private const MAX_HOPS = 4;

    /** True when the string looks like a URL we are allowed to resolve. */
    public static function isResolvableUrl(string $q): bool
    {
        $host = strtolower((string) parse_url(trim($q), PHP_URL_HOST));

        return $host !== '' && in_array($host, self::ALLOWED_HOSTS, true);
    }

    /**
     * Parse lat/lng out of a Google Maps URL (no network).
     * Handles: /@31.52,74.35,17z · !3d31.52!4d74.35 · ?q=31.52,74.35 (also ll=,
     * destination=, %2C encoding) and consent-wrapped ?continue=<url>.
     * Returns ['lat' => float, 'lng' => float] or null. Pakistan-bounds checked.
     */
    public static function parseCoordinates(string $url): ?array
    {
        $url = urldecode($url);
        $patterns = [
            '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',
            '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',
            '/[?&](?:q|query|ll|destination|center)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if (self::inPakistan($lat, $lng)) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }

        return null;
    }

    /** Pakistan bounding box — same box the map is locked to. */
    public static function inPakistan(float $lat, float $lng): bool
    {
        return $lat >= 22.8 && $lat <= 37.5 && $lng >= 60.4 && $lng <= 77.6;
    }

    /**
     * Resolve a pasted link (short or full) to coordinates.
     * Returns ['lat','lng'] or null.
     */
    public static function resolve(string $url): ?array
    {
        $url = trim($url);

        // Coordinates already in the pasted URL? No network needed.
        if ($ll = self::parseCoordinates($url)) {
            return $ll;
        }

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $parts = parse_url($url);
            $host = strtolower($parts['host'] ?? '');
            $scheme = strtolower($parts['scheme'] ?? '');
            if ($scheme !== 'https' || !in_array($host, self::ALLOWED_HOSTS, true)) {
                return null; // never fetch a non-allowlisted target
            }

            try {
                $resp = Http::withoutRedirecting()->timeout(6)
                    ->withHeaders(['User-Agent' => 'TaxNest-POS/1.0 (shop-pin resolver)'])
                    ->get($url);
            } catch (\Throwable $e) {
                return null;
            }

            $location = $resp->header('Location');
            if ($location === null || $location === '') {
                // Final page — coords may sit in the landing URL's query
                // (consent pages wrap the target in ?continue=<url>).
                return self::parseCoordinates($url);
            }

            // Relative redirect → same host.
            if (str_starts_with($location, '/')) {
                $location = 'https://' . $host . $location;
            }

            if ($ll = self::parseCoordinates($location)) {
                return $ll;
            }
            $url = $location;
        }

        return null;
    }
}
