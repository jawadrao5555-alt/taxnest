<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare real-visitor-IP restore.
 *
 * When the site is proxied through Cloudflare, PHP's REMOTE_ADDR is a
 * Cloudflare edge IP. Cloudflare sends the real visitor IP in the
 * CF-Connecting-IP header. We overwrite REMOTE_ADDR with that value —
 * but ONLY when the connecting peer really is a Cloudflare address,
 * otherwise anyone could spoof the header and bypass IP rate limits
 * (login throttles) or forge audit-log IPs.
 *
 * Must be PREPENDED to the global middleware stack (before TrustProxies)
 * so every later consumer of $request->ip() — throttle keys, audit logs,
 * last_login_ip — sees the true visitor IP.
 *
 * When the site is NOT behind Cloudflare (dev container, direct origin
 * hits) the peer is not in the CF ranges and this is a no-op.
 */
class TrustCloudflare
{
    /**
     * Published Cloudflare edge ranges (https://www.cloudflare.com/ips/).
     * These are extremely stable; still, if Cloudflare ever adds a range,
     * the worst case is falling back to the edge IP (same behaviour as
     * having no middleware) — never a security hole.
     */
    private const CF_RANGES = [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
        '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $peer = $request->server->get('REMOTE_ADDR');
        $cfIp = $request->headers->get('CF-Connecting-IP');

        if ($peer && $cfIp && filter_var($cfIp, FILTER_VALIDATE_IP) && self::isCloudflareIp($peer)) {
            $request->server->set('REMOTE_ADDR', $cfIp);
            // Reset Symfony's cached client-IP resolution (if any) by
            // re-initializing headers/server derived state.
            $request->headers->set('X-Forwarded-For', $cfIp);
        }

        return $next($request);
    }

    public static function isCloudflareIp(string $ip): bool
    {
        foreach (self::CF_RANGES as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // family mismatch (v4 vs v6) or invalid input
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainder > 0) {
            $mask = 0xFF << (8 - $remainder) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
