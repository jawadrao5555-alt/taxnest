<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosTopNavigationRegressionTest extends TestCase
{
    public function test_pra_and_fbr_top_navigation_stays_above_dismissible_announcement_overlays(): void
    {
        $pra = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $fbr = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));
        $decision = file_get_contents(resource_path('views/fbr-pos/partials/integration-decision-card.blade.php'));

        foreach (['pra' => $pra, 'fbr' => $fbr] as $panel => $layout) {
            $this->assertNotFalse($layout);
            $this->assertStringContainsString(
                'data-tn-topnav="'.$panel.'" class="topnav-bar',
                $layout
            );
            $this->assertStringContainsString('style="z-index: 140;"', $layout);
            $this->assertStringContainsString('data-tn-topnav-control="notification"', $layout);
            $this->assertStringContainsString('data-tn-topnav-control="theme"', $layout);
            $this->assertStringContainsString('data-tn-topnav-control="profile"', $layout);
        }

        $this->assertNotFalse($decision);
        $this->assertStringContainsString('style="z-index: 131;', $decision);
        $this->assertGreaterThan(
            131,
            $this->topNavigationZIndex($fbr),
            'The header must remain the physical hit-test target while the dismissible FBR decision card is open.'
        );
    }

    public function test_header_controls_keep_their_alpine_contract_for_normal_and_delayed_vite_startup(): void
    {
        $pra = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $fbr = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));

        foreach ([$pra, $fbr] as $layout) {
            $this->assertStringContainsString('bellOpen = !bellOpen', $layout);
            $this->assertStringContainsString('themeOpen = !themeOpen', $layout);
            $this->assertStringContainsString('profileOpen = !profileOpen', $layout);
        }

        $this->assertStringContainsString("@vite(['resources/css/app.css', 'resources/js/app.js'])", $pra);
        $this->assertStringContainsString("@vite(['resources/css/app.css', 'resources/js/app.js'])", $fbr);
        $this->assertMatchesRegularExpression(
            '/tnSafeFbrPosHeader[\\s\\S]+<div class="flex flex-col h-full" x-data="tnSafeFbrPosHeader/',
            $fbr
        );
        $this->assertStringContainsString(
            'x-data="{ profileOpen: false, mobileMenuOpen: false, themeOpen: false,',
            $pra
        );
    }

    private function topNavigationZIndex(string $layout): int
    {
        preg_match('/data-tn-topnav="fbr"[^>]+style="z-index:\\s*(\\d+);"/', $layout, $matches);

        return (int) ($matches[1] ?? 0);
    }
}