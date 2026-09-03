<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosLocalCoreDesktopBridgeTest extends TestCase
{
    public function test_pos_layout_loads_the_shared_versioned_desktop_adapter(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));

        $this->assertStringContainsString("asset('js/nestpos-local-core.js')", $layout);
        $this->assertStringContainsString('?v=8', $layout);
    }

    public function test_desktop_scope_issues_lease_only_from_registered_device_header(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/PosController.php'));

        $this->assertStringContainsString("header('X-NestPOS-Device-Uid'", $controller);
        $this->assertStringContainsString('$leases->issue(', $controller);
        $this->assertStringContainsString("'scope_lease_denied'", $controller);
    }

    public function test_production_pos_flows_are_wired_to_shared_adapter(): void
    {
        $universal = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $this->assertStringContainsString('NestPosLocal.heldOrder.hold', $universal);
        $this->assertStringNotContainsString('NestPosLocal.heldOrder.open', $universal);
        $this->assertStringNotContainsString('NestPosLocal.heldOrder.addLine', $universal);
        $this->assertStringContainsString('consumption_snapshot:', $universal);
        $this->assertStringContainsString('NestPosLocal.heldOrder.settleWithSale', $universal);
        $this->assertStringContainsString('localHeldSaleSnapshot', $universal);
        $this->assertStringContainsString('offline_uuid: payUuid', $universal);
        $this->assertStringContainsString("'deal_snapshot' => \$i->item_type === 'deal'", $universal);
        $this->assertStringContainsString("item.type === 'deal' &&", $universal);
        $controller = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $this->assertStringContainsString("'deal_version' => \$dealVersion", $controller);
        $this->assertStringContainsString("'mode' => \$recipe ? 'recipe' : 'direct'", $controller);
        $this->assertStringContainsString('NestPosLocal.heldOrder.cancel', $universal);
        $this->assertStringContainsString('NestPosLocal.table.shift', $universal);
        $this->assertStringContainsString('NestPosLocal.printQueue.enqueue', $universal);

        $markers = [
            'pos/inventory/adjust-stock.blade.php' => 'data-local-core-command="stock.adjust"',
            'pos/customers.blade.php' => 'data-local-core-command="customer.upsert"',
            'pos/dashboard.blade.php' => 'data-local-core-command="cash.open"',
            'pos/day-close.blade.php' => 'data-local-core-command="cash.close"',
            'pos/return-form.blade.php' => 'data-local-core-command="refund.record"',
        ];
        foreach ($markers as $view => $marker) {
            $this->assertStringContainsString($marker, file_get_contents(resource_path('views/' . $view)), $view);
        }
    }

    public function test_unqueued_external_fallback_cannot_report_success_or_pending(): void
    {
        $client = file_get_contents(public_path('js/nestpos-local-core.js'));

        $this->assertStringContainsString("error: 'external_call_not_queued'", $client);
        $this->assertStringContainsString('ok: false, success: false, local: false, pending: false', $client);
    }
}