<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosNavigationNewSaleTest extends TestCase
{
    public function test_desktop_and_mobile_static_new_sale_are_hidden_on_the_sale_screen(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $this->assertNotFalse($layout);

        $this->assertEquals(2, substr_count($layout, 'data-nav-new-sale="static"'));
        $this->assertEquals(
            2,
            substr_count($layout, "@unless(request()->routeIs('pos.invoice.create', 'pos.v2.invoice.create') || \$confinedRoleLayout)")
        );
        $this->assertStringContainsString(
            'id="tn-nav-sale-tools"',
            $layout
        );
    }

    public function test_sale_screen_keeps_its_own_new_sale_action_for_desktop_and_mobile(): void
    {
        $sale = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $this->assertNotFalse($sale);
        $this->assertStringContainsString('@click="newSale()"', $sale);
        $this->assertStringContainsString('flex md:hidden', $sale);
        $this->assertStringContainsString("{{ __('pos.new_sale') }}", $sale);
        $this->assertGreaterThanOrEqual(2, substr_count($sale, 'data-nav-new-sale="action"'));
    }

    public function test_legacy_sidebar_is_not_included_in_the_live_nestpos_layout(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $this->assertStringNotContainsString('pos-navigation', $layout);
    }
}
