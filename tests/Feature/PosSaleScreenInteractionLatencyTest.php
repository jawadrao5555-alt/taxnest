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

    public function test_page_down_enters_latest_cart_quantity_editing_on_every_sale_view(): void
    {
        $saleViews = [
            'PRA' => resource_path('views/pos/universal.blade.php'),
            'FBR' => resource_path('views/fbr-pos/universal.blade.php'),
            'Restaurant' => resource_path('views/pos/restaurant/pos.blade.php'),
        ];

        foreach ($saleViews as $product => $path) {
            $view = file_get_contents($path);

            $this->assertStringContainsString("if (e.key === 'PageDown')", $view, "{$product} must route Page Down centrally");
            $this->assertStringContainsString("e.target?.closest?.('input, textarea, select')", $view, "{$product} must not steal Page Down from form fields");
            $this->assertStringContainsString("this.cart.length > 0", $view, "{$product} must ignore Page Down for an empty cart");
            $this->assertStringContainsString('e.preventDefault();', $view, "{$product} must prevent native Page Down scrolling when handled");
            $this->assertStringContainsString('this.mobileView = \'cart\';', $view, "{$product} must reveal the cart before focusing quantity");
            $this->assertStringContainsString("__('pos.latest_cart_quantity')", $view, "{$product} must explain the Page Down shortcut");
            $this->assertStringContainsString('>PgDn</kbd>', $view, "{$product} must display the Page Down key");
            $this->assertStringContainsString("if (e.key === 'ArrowDown') { e.preventDefault(); this.moveCartSelection(1); return; }", $view, "{$product} must preserve focused quantity down-arrow navigation");
            $this->assertStringContainsString("if (e.key === 'ArrowUp')   { e.preventDefault(); this.moveCartSelection(-1); return; }", $view, "{$product} must preserve focused quantity up-arrow navigation");
        }

        $this->assertStringContainsString("this.enterCartMode('last');", file_get_contents($saleViews['PRA']));
        $this->assertStringContainsString("this.enterCartMode('last');", file_get_contents($saleViews['FBR']));
        $this->assertStringContainsString('this.enterCartMode();', file_get_contents($saleViews['Restaurant']));
    }

    public function test_page_down_cannot_enter_cart_behind_sale_screen_overlays(): void
    {
        $expectedModalGuards = [
            resource_path('views/pos/universal.blade.php') => [
                'showHeldOrders', 'showRetailHeld', 'retailHoldNaming', 'showQuickType',
                'showManualItem', 'showCustomerPicker', 'showShortcuts', 'showManagerPinModal',
                'showLocalBills', 'showFailedBills', 'showPendingDeliveries', 'showTablePicker',
                'showReprint', 'boardMenuTable', 'boardConfirm', 'boardCancelAsk', 'boardShift',
                'heldMenu', 'tableSwitchPrompt', 'showPromoteMethod', 'riderSettleBill',
                'onlineConfirm', 'tableBoardOpen', 'showIncoming', 'showCustomerHistory',
                'showLowStockPopup', 'showNewCustomerModal', 'showNewCustomerInline',
                'quickCreating', 'showDealChoiceModal', 'showOfflineQueue', 'quickReturnOpen',
                'showPrintConfirm', 'showTerminalPicker', 'showFitMenu',
            ],
            resource_path('views/fbr-pos/universal.blade.php') => [
                'showHeldOrders', 'fbrHoldNaming', 'showQuickType', 'showManualItem',
                'showCustomerPicker', 'showShortcuts', 'showManagerPinModal', 'showLocalBills',
                'showFailedBills', 'showPendingDeliveries', 'showTablePicker', 'tableSwitchPrompt',
                'riderSettleBill', 'currentUpsell', 'qcModal', 'showDrafts',
                'showCustomerHistory', 'showLowStockPopup', 'showNewCustomerInline',
                'quickCreating', 'showDealChoiceModal', 'showOfflineQueue', 'quickReturnOpen',
                'showPrintConfirm', 'showFitMenu',
            ],
        ];

        foreach ($expectedModalGuards as $path => $guards) {
            $view = file_get_contents($path);
            $pageDownStart = strpos($view, "if (e.key === 'PageDown')");
            $qtyGateStart = strpos($view, '// CART QTY INPUT:', $pageDownStart);
            $this->assertNotFalse($pageDownStart);
            $this->assertNotFalse($qtyGateStart);
            $pageDownHandler = substr($view, $pageDownStart, $qtyGateStart - $pageDownStart);

            foreach ($guards as $guard) {
                $this->assertStringContainsString("this.{$guard}", $pageDownHandler, basename($path)." must block Page Down while {$guard} owns the screen");
            }

            $this->assertStringContainsString('if (!pageDownBlocked && !pageDownInField && this.cart.length > 0)', $pageDownHandler);
        }
    }
}
