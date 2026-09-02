<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Electron shell deliberately identifies itself as NestPOSDesktop and may
 * run at its 900 CSS-pixel minimum.  That is below Tailwind's lg breakpoint,
 * even though it is wider than the old md breakpoint.  This test protects the
 * compact-layout hand-off: controls must be rendered in normal flow rather
 * than only in the constrained desktop header scroller.
 */
class DesktopAgentControlsReachabilityTest extends TestCase
{
    public function test_desktop_agent_viewport_uses_the_reachable_compact_pra_controls(): void
    {
        $agent = file_get_contents(base_path('pra-agent/src/pos-window.js'));
        $layout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $sale = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $worker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("width: 1366,", $agent);
        $this->assertStringContainsString("minWidth: 900,", $agent);
        $this->assertStringContainsString("NestPOSDesktop/", $agent);

        // At 900px, wide header-only tools are intentionally hidden and the
        // compact nav trigger is rendered.  At >=1024px the wide header returns.
        $this->assertStringContainsString(
            'id="tn-nav-sale-tools" class="hidden lg:flex',
            $layout
        );
        $this->assertStringContainsString(
            'mobileMenuOpen = !mobileMenuOpen" class="lg:hidden',
            $layout
        );

        // PRA's real ON/OFF handler remains in the compact strip; it is not a
        // duplicate rule or a UA-only CSS override.  Its 1023px wrapping rule
        // covers the shell's 900px minimum without changing wide desktops.
        $this->assertStringContainsString(
            'tn-toggles-strip flex lg:hidden',
            $sale
        );
        $this->assertStringContainsString(
            "route('pos.api.toggle-pra')",
            $sale
        );
        $this->assertStringContainsString('@media (min-width: 768px) and (max-width: 1023px)', $sale);
        $this->assertStringContainsString('.tn-toggles-strip { flex-wrap: wrap;', $sale);

        // A new worker cache namespace removes any runtime-cached authenticated
        // settings page created before the layout correction.
        $this->assertStringContainsString(
            "const CACHE_VERSION = 'taxnest-20260903-desktop-agent-controls'",
            $worker
        );
        $this->assertStringContainsString("'/pos/pra-settings'", $worker);
    }
}