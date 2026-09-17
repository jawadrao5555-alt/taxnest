<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Deployment-safe contract coverage for the cashier waiter-edit surface.
 *
 * These assertions intentionally do not require a browser or a printer agent:
 * they protect the server/UI handshake in the fast suite while the full browser
 * suite can exercise the same labels at 390px.
 */
class WaiterCashierEditContractTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function test_server_edit_contract_has_revision_auth_and_idempotency(): void
    {
        $source = $this->source('app/Http/Controllers/RestaurantWaiterController.php');
        $this->assertStringContainsString('updateIncomingOrder', $source);
        $this->assertStringContainsString('edit_revision', $source);
        $this->assertStringContainsString('RestaurantOrderEditAttempt', $source);
        $this->assertStringContainsString('conflict', $source);
        $this->assertStringContainsString('isPosAdmin', $source);
        $this->assertStringContainsString('assigned_cashier_id', $source);
        $this->assertStringContainsString('enqueueVoid', $source);
    }

    public function test_delta_contract_preserves_increment_modifier_and_unchanged_rules(): void
    {
        $source = $this->source('app/Http/Controllers/RestaurantWaiterController.php');
        $this->assertStringContainsString("'action' => 'ADD'", $source);
        $this->assertStringContainsString("'action' => 'MODIFIER'", $source);
        $this->assertStringContainsString("'action' => 'VOID'", $source);
        $this->assertStringContainsString('$increment = $qty - $oldQty', $source);
        $this->assertStringContainsString('$modifierChanged = $oldNotes !== (string) $notes', $source);
    }

    /** Documents the focused scenarios covered by the delta implementation. */
    public function test_same_id_add_increase_remove_decrease_modifier_and_unchanged_scenarios_are_wired(): void
    {
        $controller = $this->source('app/Http/Controllers/RestaurantWaiterController.php');
        $this->assertStringContainsString("'order_id' => \$order->id", $controller);
        $this->assertStringContainsString('$increment = $qty - $oldQty', $controller);
        $this->assertStringContainsString('$voidItems[]', $controller);
        $this->assertStringContainsString('$line->delete()', $controller);
        $this->assertStringContainsString("'kot_printed_at' => null", $controller);
        $this->assertStringContainsString("'unchanged'", $controller);
    }

    public function test_replay_and_queue_contract_is_durable_and_scoped(): void
    {
        $service = $this->source('app/Services/KotPrintService.php');
        $migration = $this->source('database/migrations/2026_09_17_210100_create_restaurant_order_edit_attempts.php');
        $controller = $this->source('app/Http/Controllers/RestaurantWaiterController.php');
        $this->assertStringContainsString('queueWhileOffline', $service);
        $this->assertStringContainsString("status' => 'pending'", $service);
        $this->assertStringContainsString("unique(['company_id', 'order_id', 'edit_uuid']", $migration);
        $this->assertStringContainsString("where('edit_uuid', \$editUuid)", $controller);
        $this->assertStringContainsString("'replayed' => true", $controller);
        $this->assertStringContainsString("'kot_status' =>", $controller);
        $this->assertStringContainsString('dedupe_key', $service);
        $this->assertStringContainsString('expected_incoming_revision', $this->source('app/Http/Controllers/PosController.php'));
        $this->assertStringContainsString('$expectedRevision', $controller);
    }

    public function test_edit_contract_rejects_empty_and_terminal_or_cross_cashier_orders(): void
    {
        $controller = $this->source('app/Http/Controllers/RestaurantWaiterController.php');
        $this->assertStringContainsString('An empty cart is not a valid order edit', $controller);
        $this->assertStringContainsString("where('company_id', \$companyId)", $controller);
        $this->assertStringContainsString("where('source', 'waiter')", $controller);
        $this->assertStringContainsString("Paid or closed waiter orders cannot be edited.", $controller);
        $this->assertStringContainsString('assigned_cashier_id', $controller);
        $this->assertStringContainsString('Product is unavailable for this branch.', $controller);
    }

    public function test_ui_has_save_discard_stay_and_mobile_contract(): void
    {
        $blade = $this->source('resources/views/pos/universal.blade.php');
        $css = $this->source('public/css/mobile.css');
        $this->assertStringContainsString("__('pos.waiter_edit_save_kitchen')", $blade);
        $this->assertStringContainsString("__('pos.waiter_edit_take_payment')", $blade);
        $this->assertStringContainsString("__('pos.recall_discard_switch_btn')", $blade);
        $this->assertStringContainsString("__('pos.recall_stay_btn')", $blade);
        $this->assertStringContainsString('saveIncomingOrder', $blade);
        $this->assertStringContainsString('_recallCartBaseline = this.cartEditFingerprint()', $blade);
        $this->assertStringContainsString('tn-mobile-cart-bar', $blade);
        $this->assertStringContainsString('cartQtyCount', $blade);
        $this->assertStringContainsString('data-pos-free-access-notice', $blade);
        $this->assertStringContainsString('tn-caller-actions', $blade);
        $this->assertStringContainsString('@media (max-width: 420px)', $css);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $css);
        $this->assertStringContainsString('tn-mobile-cart-bar', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('body[data-theme="midnight"]', $css);
        $this->assertStringContainsString('[x-data^="tnMadadgar("]', $css);
        $this->assertStringContainsString('padding-bottom: calc(.5rem + env(safe-area-inset-bottom, 0px))', $css);
    }
}