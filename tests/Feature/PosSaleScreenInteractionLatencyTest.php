<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosSaleScreenInteractionLatencyTest extends TestCase
{
    public function test_cart_interactions_keep_the_visible_feedback_immediate(): void
    {
        $saleViews = [
            'PRA' => resource_path('views/pos/universal.blade.php'),
            'FBR' => resource_path('views/fbr-pos/universal.blade.php'),
        ];

        foreach ($saleViews as $product => $path) {
            $view = file_get_contents($path);

            $this->assertStringContainsString(
                "scrollIntoView({ block: 'nearest', behavior: 'auto' })",
                $view,
                "{$product} cart selection must not wait for a smooth-scroll animation"
            );
            $this->assertStringContainsString(
                "const el = document.querySelector('input[data-qty-row=\"' + index + '\"]');",
                $view,
                "{$product} must find the live focused quantity input"
            );
            $this->assertStringContainsString(
                'if (el) el.value = next;',
                $view,
                "{$product} quantity stepper must visibly update without waiting for blur"
            );
        }
    }

    public function test_fbr_click_path_does_not_run_the_disabled_upsell_catalog_scan(): void
    {
        $fbr = file_get_contents(resource_path('views/fbr-pos/universal.blade.php'));

        $this->assertStringContainsString('Smart Upsell is disabled for retail FBR POS.', $fbr);
        $this->assertStringNotContainsString('this.triggerUpsell(item);', $fbr);
    }
}