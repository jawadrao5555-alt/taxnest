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
            $this->assertStringContainsString('data-tn-topnav-scroll-actions', $layout);
            $this->assertStringContainsString('data-tn-topnav-menu-cluster', $layout);
        }
        $this->assertStringContainsString('data-tn-topnav-control="fullscreen"', $pra);

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

    public function test_fbr_mobile_impersonation_keeps_identity_controls_inside_the_hit_testable_header(): void
    {
        $fbr = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));

        $this->assertNotFalse($fbr);
        $this->assertStringContainsString(
            "{{ is_array(session('impersonation')) ? 'tn-impersonated-header' : '' }}",
            $fbr
        );
        $this->assertStringContainsString('class="tn-fbr-header-brand ', $fbr);
        $this->assertStringContainsString('data-tn-fbr-secondary-action', $fbr);
        $this->assertMatchesRegularExpression(
            '/@media \\(max-width: 639px\\)[\\s\\S]+\\.tn-impersonated-header \\.tn-fbr-header-brand,[\\s\\S]+\\.tn-impersonated-header \\[data-tn-fbr-secondary-action\\][\\s\\S]+display: none !important;/',
            $fbr
        );
        $this->assertMatchesRegularExpression(
            '/\\.tn-impersonated-header \\.tn-fbr-header-actions \\{[\\s\\S]+flex-shrink: 0;[\\s\\S]+overflow: visible;/',
            $fbr
        );
    }

    public function test_dropdown_hosts_are_outside_the_scrollable_action_strip(): void
    {
        foreach ([
            resource_path('views/layouts/pos-app.blade.php'),
            resource_path('views/layouts/fbr-pos-app.blade.php'),
        ] as $layoutPath) {
            $layout = file_get_contents($layoutPath);

            $this->assertNotFalse($layout);
            $this->assertStringContainsString('.tn-topnav-scroll-actions', $layout);
            $this->assertStringContainsString('overflow-x: auto;', $layout);
            $this->assertStringContainsString('.tn-topnav-menu-cluster', $layout);
            $this->assertMatchesRegularExpression(
                '/\\.tn-topnav-menu-cluster\\s*\\{[\\s\\S]*?overflow:\\s*visible;/',
                $layout
            );
        }
    }

    public function test_read_only_diagnosis_keeps_bell_history_but_disables_writes(): void
    {
        $pra = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $fbr = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));
        $details = file_get_contents(resource_path('views/partials/whats-new-detail-modals.blade.php'));

        foreach ([$pra, $fbr] as $layout) {
            $this->assertStringContainsString('$whatsNewReadOnly = $wnReadonlyImp;', $layout);
            $this->assertStringNotContainsString('$wnAllowed && !$wnPending && !$wnReadonlyImp', $layout);
            $this->assertStringContainsString("'seenEndpoint' => \$whatsNewReadOnly ? null", $layout);
        }
        $this->assertStringContainsString('this.wasSeen || @json(empty($seenEndpoint))', $details);
    }

    public function test_cache_first_sale_boot_fingerprint_includes_the_deployed_shell(): void
    {
        $pra = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $fbr = file_get_contents(app_path('Http/Controllers/FbrPosController.php'));
        $revision = file_get_contents(app_path('Support/PosSaleShellRevision.php'));

        $this->assertStringContainsString("PosSaleShellRevision::bootScreenRevision(\n                'pra'", $pra);
        $this->assertStringContainsString("PosSaleShellRevision::bootScreenRevision(\n                'fbr'", $fbr);
        $this->assertStringContainsString("existing 's' key", $pra);
        $this->assertStringContainsString("existing 's' key", $fbr);

        foreach ([
            'views/layouts/pos-app.blade.php',
            'views/layouts/fbr-pos-app.blade.php',
            'views/partials/alpine-runtime-loader.blade.php',
            'views/partials/whats-new-detail-modals.blade.php',
            "public_path('build/manifest.json')",
            "public_path('sw.js')",
        ] as $shellDependency) {
            $this->assertStringContainsString($shellDependency, $revision);
        }
    }

    private function topNavigationZIndex(string $layout): int
    {
        preg_match('/data-tn-topnav="fbr"[^>]+style="z-index:\\s*(\\d+);"/', $layout, $matches);

        return (int) ($matches[1] ?? 0);
    }

}
