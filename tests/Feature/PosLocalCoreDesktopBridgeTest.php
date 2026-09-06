<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosDeal;
use App\Models\PosProduct;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

class PosLocalCoreDesktopBridgeTest extends TestCase
{
    public function test_pos_layout_loads_the_shared_versioned_desktop_adapter(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));

        $this->assertStringContainsString("asset('js/nestpos-local-core.js')", $layout);
        $this->assertStringContainsString('?v=10', $layout);
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

    /**
     * A deal bill replayed from offline/desktop must carry the version of the
     * deal it was actually sold under, so a later edit to the deal can never
     * change what gets consumed or restored.
     *
     * Asserted on the payload the controller PRODUCES, never on how the value
     * is spelled in the source — the same code refactored differently must
     * still pass.
     */
    public function test_deal_version_is_stamped_on_the_offline_desktop_deal_payload(): void
    {
        $deal = new PosDeal();
        $deal->id = 77;
        $deal->name = 'Family Deal';
        $deal->price = 1450.00;
        $deal->updated_at = CarbonImmutable::create(2026, 3, 4, 10, 30, 0, 'UTC');

        $product = new PosProduct();
        $product->id = 909;
        $product->name = 'Zinger Burger';

        $component = $this->callPosController('dealComponentSnapshot', [$deal, $product, 2, 31]);

        $this->assertSame($deal->updated_at->toJSON(), $component['deal_version']);
        $this->assertNotSame('', (string) $component['deal_version']);
        $this->assertSame(77, $component['deal_id']);
        $this->assertSame('direct', $component['mode']);

        // ...and it survives the expansion that offline stock replay consumes.
        $expanded = $this->callPosController('expandDealComponentsForStock', [[[
            'type' => 'deal',
            'item_id' => 77,
            'quantity' => 1,
            'deal_snapshot' => [$component],
        ]]]);

        $derived = collect($expanded)->firstWhere('_deal_derived', true);
        $this->assertIsArray($derived, 'Deal components must expand for stock replay.');
        $this->assertSame($component['deal_version'], $derived['deal_version']);
    }

    /**
     * A deal row that predates the timestamp columns must still carry a
     * non-empty version, otherwise the snapshot comparison has nothing to pin.
     */
    public function test_legacy_deal_without_a_timestamp_still_gets_a_version(): void
    {
        $deal = new PosDeal();
        $deal->id = 5;
        $deal->name = 'Old Deal';
        $deal->price = 300.00;
        $deal->updated_at = null;

        $product = new PosProduct();
        $product->id = 12;
        $product->name = 'Fries';

        $component = $this->callPosController('dealComponentSnapshot', [$deal, $product, 1, 31]);

        $this->assertSame('legacy', $component['deal_version']);
    }

    private function callPosController(string $method, array $args): array
    {
        $reflected = new ReflectionMethod(PosController::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invokeArgs(new PosController(), $args);
    }

    public function test_unqueued_external_fallback_cannot_report_success_or_pending(): void
    {
        $client = file_get_contents(public_path('js/nestpos-local-core.js'));

        $this->assertStringContainsString("error: 'external_call_not_queued'", $client);
        $this->assertStringContainsString('ok: false, success: false, local: false, pending: false', $client);
    }
}