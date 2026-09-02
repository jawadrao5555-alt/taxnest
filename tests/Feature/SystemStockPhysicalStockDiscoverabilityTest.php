<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Locks the copy and entry points around the stock-count workflow without
 * exercising its deliberately separate posting semantics.
 */
class SystemStockPhysicalStockDiscoverabilityTest extends TestCase
{
    public function test_all_pos_locales_name_both_system_and_physical_stock(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            $copy = require base_path("lang/{$locale}/pos.php");

            foreach ([
                'stock_check', 'stock_check_new', 'stock_check_expected',
                'stock_check_physical', 'stock_check_dashboard_title',
                'stock_check_dashboard_cta', 'stock_check_step_open',
                'stock_check_step_count', 'stock_check_step_post',
                'stock_check_safety_note', 'fbr_stock_check_notice',
            ] as $key) {
                $this->assertArrayHasKey($key, $copy, "{$locale} is missing {$key}");
            }
        }

        $en = require base_path('lang/en/pos.php');
        $this->assertStringContainsString('System Stock', $en['stock_check']);
        $this->assertStringContainsString('Physical Stock', $en['stock_check']);
        $this->assertSame('System Stock', $en['stock_check_expected']);
        $this->assertSame('Physical Stock', $en['stock_check_physical']);
    }

    public function test_pra_dashboard_and_navigation_expose_the_comparison(): void
    {
        $dashboard = file_get_contents(base_path('resources/views/pos/inventory/dashboard.blade.php'));
        $sidebar = file_get_contents(base_path('resources/views/layouts/pos-navigation.blade.php'));
        $menu = file_get_contents(base_path('resources/views/layouts/pos-app.blade.php'));

        $this->assertStringContainsString("route('pos.inventory.stock-check.index')", $dashboard);
        $this->assertStringContainsString('stock_check_dashboard_title', $dashboard);
        $this->assertStringContainsString('stock_check_step_open', $dashboard);
        $this->assertStringContainsString('stock_check_safety_note', $dashboard);
        $this->assertStringContainsString('stock_check_nav_hint', $sidebar);
        $this->assertStringContainsString("route('pos.inventory.stock-check.index')", $menu);
    }

    public function test_fbr_stock_page_honestly_explains_why_checker_is_not_reused(): void
    {
        $stockPage = file_get_contents(base_path('resources/views/fbr-pos/stock.blade.php'));
        $en = require base_path('lang/en/pos.php');

        $this->assertStringContainsString('fbr_stock_check_title', $stockPage);
        $this->assertStringContainsString('fbr_stock_check_notice', $stockPage);
        $this->assertStringContainsString('separate from PRA POS', $en['fbr_stock_check_notice']);
        $this->assertStringContainsString('could compare the wrong products', $en['fbr_stock_check_notice']);
        $this->assertStringContainsString('Stock List, choose Edit', $en['fbr_stock_check_notice']);
    }
}