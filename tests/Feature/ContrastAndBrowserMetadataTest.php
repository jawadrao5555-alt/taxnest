<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContrastAndBrowserMetadataTest extends TestCase
{
    public function test_rendered_premium_page_subtitle_uses_aa_safe_light_muted_token(): void
    {
        $css = file_get_contents(public_path('css/taxnest-premium.css'));

        $this->assertNotFalse($css);
        $this->assertMatchesRegularExpression(
            '/\.tn-premium-shell\s+\.tn-page-subtitle\s*\{[^}]*color:\s*var\(--tn-muted\)/s',
            $css,
            'The page subtitle must resolve through the shared muted token.'
        );
        preg_match('/\.tn-premium-shell\s*\{([^}]*)\}/s', $css, $root);
        preg_match('/--tn-muted:\s*(#[0-9A-Fa-f]{6})/', $root[1] ?? '', $light);
        preg_match('/\.dark\.tn-premium-shell,\s*\.tn-premium-shell\.dark\s*\{([^}]*)\}/s', $css, $darkRoot);
        preg_match('/--tn-muted:\s*(#[0-9A-Fa-f]{6})/', $darkRoot[1] ?? '', $dark);

        $this->assertSame('#526267', strtoupper($light[1] ?? ''));
        $this->assertGreaterThanOrEqual(5.0, $this->contrastRatio($light[1], '#F4F6F3'));
        $this->assertSame('#A8BEBA', strtoupper($dark[1] ?? ''));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($dark[1], '#10272D'));
    }

    public function test_login_credentials_have_unambiguous_autocomplete_and_fbr_password_ownership(): void
    {
        foreach ([
            'admin' => resource_path('views/admin/login.blade.php'),
            'agent' => resource_path('views/agent/login.blade.php'),
            'franchise' => resource_path('views/franchise/login.blade.php'),
        ] as $name => $path) {
            $view = file_get_contents($path);
            $this->assertNotFalse($view);
            $this->assertStringContainsString('autocomplete="email"', $view, $name);
            $this->assertStringContainsString('autocomplete="current-password"', $view, $name);
        }

        $fbr = file_get_contents(resource_path('views/fbr-pos/auth/login.blade.php'));
        $this->assertMatchesRegularExpression(
            '/<form\b[^>]*action="\/fbr-pos\/login"[^>]*>.*?<input\b[^>]*id="password"[^>]*>/s',
            $fbr
        );
        $this->assertStringContainsString('autocomplete="username"', $fbr);
        $this->assertStringContainsString('autocomplete="current-password"', $fbr);
        $this->assertStringContainsString('<form id="fbrPosLoginForm"', $fbr);
        $this->assertStringContainsString('form="fbrPosLoginForm"', $fbr);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(
                static fn (int $channel): float => $channel / 255,
                sscanf(ltrim($hex, '#'), '%2x%2x%2x')
            );
            $linear = array_map(
                static fn (float $channel): float => $channel <= 0.03928
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4,
                $channels
            );

            return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
        };

        return (max($luminance($foreground), $luminance($background)) + 0.05)
            / (min($luminance($foreground), $luminance($background)) + 0.05);
    }
}