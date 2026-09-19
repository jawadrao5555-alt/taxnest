<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contracts for the sale viewport regressions.  These deliberately cover
 * both sale variants because FBR is a markup port and must not lose PRA fixes.
 */
class SaleResponsiveLayoutContractTest extends TestCase
{
    public function test_sale_roots_use_one_height_contract_and_real_cart_containment(): void
    {
        foreach (['pos/universal.blade.php', 'fbr-pos/universal.blade.php'] as $file) {
            $view = file_get_contents(resource_path('views/' . $file));

            $this->assertNotFalse($view);
            $this->assertStringContainsString(
                '.tn-sale-root { height: calc(100vh - 60px); height: calc(100dvh - 60px); max-height: calc(100dvh - 60px); }',
                $view
            );
            $this->assertStringContainsString('const fittedHeight = Math.floor(Math.max(0, window.innerHeight - shellOffset) / f);', $view);
            $this->assertStringContainsString("';height:' + fittedHeight + 'px!important;max-height:' + fittedHeight + 'px!important'", $view);
            $this->assertStringContainsString(
                '.tn-cart-main { display: flex; flex-direction: column; flex: 1 1 0%; min-width: 0; min-height: 0; }',
                $view
            );
            $this->assertStringNotContainsString('.tn-cart-main { display: contents; }', $view);
            $this->assertStringContainsString('min-width: 0; min-height: 0;', $view);
            $this->assertStringContainsString('@media (min-width: 768px) and (max-height: 760px)', $view);
            $this->assertStringContainsString('@media (min-width: 768px) and (max-width: 1023px)', $view);
            $this->assertStringContainsString('position: sticky; bottom: 0;', $view);
            $this->assertStringContainsString('safe-area-inset-bottom', $view);
            $this->assertStringContainsString('background: #f9fafb', $view);
        }

        $premiumCss = file_get_contents(public_path('css/premium-pos.css'));
        $this->assertStringContainsString(
            'height: calc(100dvh - 76px - env(safe-area-inset-bottom, 0px)) !important;',
            $premiumCss
        );
    }

    public function test_empty_cart_keeps_the_mobile_cart_reachable(): void
    {
        foreach (['pos/universal.blade.php', 'fbr-pos/universal.blade.php'] as $file) {
            $view = file_get_contents(resource_path('views/' . $file));

            $this->assertNotFalse($view);
            $this->assertStringContainsString('<button @click="mobileView = \'cart\'"', $view);
            $this->assertDoesNotMatchRegularExpression(
                '/<button[^>]+x-show="cart\.length > 0"[^>]+x-cloak[^>]+@click="mobileView = \'cart\'"/',
                $view
            );
        }
    }
}