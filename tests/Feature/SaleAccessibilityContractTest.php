<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaleAccessibilityContractTest extends TestCase
{
    public function test_pra_and_fbr_sale_actions_remain_reachable_in_short_or_zoomed_viewports(): void
    {
        $pra = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $fbr = file_get_contents(resource_path('views/fbr-pos/universal.blade.php'));

        foreach ([$pra, $fbr] as $view) {
            $this->assertNotFalse($view);
            $this->assertStringContainsString(
                '.tn-sale-root { height: calc(100vh - 48px); height: calc(100dvh - 48px); max-height: calc(100dvh - 48px); }',
                $view
            );
            $this->assertStringContainsString('@media (min-width: 768px) and (max-height: 760px)', $view);
            $this->assertStringContainsString('.tn-cart-col { min-height: 0; overflow-y: auto; }', $view);
            $this->assertStringContainsString('class="tn-premium-sale tn-sale-root flex flex-col overflow-hidden', $view);
            $this->assertStringNotContainsString('h-[calc(100vh-48px)]', $view);
        }

        $this->assertStringContainsString('background: #f9fafb', $fbr);
        $this->assertStringNotContainsString('background: inherit', $fbr);
    }

    public function test_fbr_universal_factory_uses_utf8_safe_json_for_component_state(): void
    {
        $view = file_get_contents(resource_path('views/fbr-pos/universal.blade.php'));

        $this->assertNotFalse($view);
        $this->assertStringContainsString("kitchenSettings: {!! \$jsEnc(\$kitchenSettings, '{}') !!}", $view);
        $this->assertStringContainsString("upsellRules: {!! \$jsEnc(\$fbrUpsellRules, '{}') !!}", $view);
        $this->assertStringNotContainsString('kitchenSettings: @json($kitchenSettings)', $view);
        $this->assertStringNotContainsString('upsellRules: @json($fbrUpsellRules)', $view);
    }

    public function test_dismissible_sale_overlays_have_dialog_focus_and_restore_contracts(): void
    {
        $support = file_get_contents(resource_path('views/partials/modal-a11y-support.blade.php'));
        $praLayout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $fbrLayout = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));
        $decision = file_get_contents(resource_path('views/fbr-pos/partials/integration-decision-card.blade.php'));
        $detail = file_get_contents(resource_path('views/partials/whats-new-detail-modals.blade.php'));

        $this->assertNotFalse($support);
        $this->assertStringContainsString('trap(event, root)', $support);
        $this->assertStringContainsString('root.__tnPreviousFocus', $support);
        $this->assertStringContainsString('window.requestAnimationFrame(() => previous.focus())', $support);

        foreach ([$praLayout, $fbrLayout] as $layout) {
            $this->assertStringContainsString("@include('partials.modal-a11y-support')", $layout);
            $this->assertStringContainsString('role="dialog" aria-modal="true"', $layout);
            $this->assertStringContainsString('@keydown.tab="window.TnModalA11y.trap(', $layout);
            $this->assertStringContainsString('window.TnModalA11y.close(dialog)', $layout);
        }

        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="posSurveyTitle"', $praLayout);
        $this->assertStringContainsString('@keydown.escape.stop.prevent="svDismiss()"', $praLayout);
        $this->assertStringContainsString('data-fbr-decision-card="1" x-ref="fdDialog"', $decision);
        $this->assertStringContainsString('@keydown.escape.stop.prevent="fdChoose(\'later\')"', $decision);
        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="fbrDecisionTitle"', $decision);
        $this->assertStringContainsString("body: JSON.stringify({ choice: choice })", $decision);

        $this->assertStringContainsString('data-whats-new-detail-id=', $detail);
        $this->assertStringContainsString('role="dialog"', $detail);
        $this->assertStringContainsString('aria-modal="true"', $detail);
        $this->assertStringContainsString('openDetail()', $detail);
        $this->assertStringContainsString('window.TnModalA11y.open(this.$refs.detailDialog)', $detail);
        $this->assertStringContainsString('window.TnModalA11y.close(dialog)', $detail);
        $this->assertStringContainsString('@keydown.tab="window.TnModalA11y.trap($event, $refs.detailDialog)"', $detail);

        $this->assertStringContainsString('data-pra-elaan-popup="1" x-ref="peDialog"', $praLayout);
        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="praElaanTitle"', $praLayout);
        $this->assertStringContainsString('@keydown.tab="window.TnModalA11y.trap($event, $refs.peDialog)"', $praLayout);
        $this->assertStringContainsString('@keydown.escape.stop.prevent="peDismiss()"', $praLayout);
    }
}