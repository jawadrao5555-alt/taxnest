<?php

namespace Tests\Feature;

use App\Models\PosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR service-line tax authority (Task 1272).
 *
 * Service items (pos_services) sell on the FBR universal screen as
 * product_id-NULL lines. The client payload rides `service_id` so
 * FbrPosController::store() can resolve the AUTHORITATIVE tax_rate /
 * is_tax_exempt from the DB row — the same DB-wins rule as Third Schedule.
 *
 * Proves that:
 *   A. A 5% service billed with a wrong/spoofed client tax_rate (18) persists
 *      at 5% — direct, Quick Type, and Random checkout all build minimal
 *      payloads, so the server must never trust the client rate.
 *   B. An exempt service with a non-exempt client payload persists at 0 tax,
 *      is_tax_exempt=1.
 *   C. A missing, deleted/forged, inactive, or cross-company service_id is
 *      REJECTED (ValidationException) — client tax values are never trusted
 *      for service lines, and another company's rate can never leak in.
 *
 * Extends PosThirdScheduleBillingTest to reuse its full FBR store() schema +
 * callFbrStore() helper (inherited third-schedule tests re-run here; harmless).
 */
class FbrPosServiceTaxAuthorityTest extends PosThirdScheduleBillingTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function makeService(array $attrs = []): PosService
    {
        return PosService::create(array_merge([
            'company_id'    => $this->companyId,
            'name'          => 'Mobile Repair',
            'price'         => 500,
            'tax_rate'      => 5,
            'is_tax_exempt' => false,
            'is_active'     => true,
        ], $attrs));
    }

    /** Build a one-service-line store() payload with a deliberately wrong client rate. */
    private function servicePayload(int $serviceId, string $name = 'Mobile Repair', float $price = 500, array $itemOverrides = []): array
    {
        return [
            'items' => [array_merge([
                'item_name'     => $name,
                'product_id'    => null,
                'service_id'    => $serviceId,
                'quantity'      => 1,
                'unit_price'    => $price,
                'uom'           => 'U',
                'tax_rate'      => 18,    // WRONG on purpose — server must override from DB
                'is_tax_exempt' => false, // WRONG for exempt services — server must override
                'item_discount' => 0,
            ], $itemOverrides)],
            'payment_method' => 'cash',
            'cash_received'  => 10000,
            'discount_type'  => 'percentage',
            'discount_value' => 0,
            'tax_inclusive'  => false,
            'offline_uuid'   => 'svc-' . uniqid(),
        ];
    }

    /** Bill one service line through store() with a deliberately wrong client rate. */
    private function billService(PosService $svc, array $itemOverrides = []): array
    {
        $res = $this->callFbrStore($this->servicePayload($svc->id, $svc->name, (float) $svc->price, $itemOverrides));

        $data = $res->getData(true);
        $this->assertTrue($data['success'] ?? false,
            'FBR store with service line should succeed: ' . ($data['message'] ?? json_encode($data)));

        $item = DB::table('fbr_pos_transaction_items')
            ->where('transaction_id', $data['transaction_id'] ?? 0)
            ->first();
        $this->assertNotNull($item, 'Service transaction item must be persisted');

        return [$data, $item];
    }

    // ── A. 5% service — client-sent 18% must be overridden to the DB's 5% ──────

    public function test_service_rate_resolved_from_db_not_client_payload(): void
    {
        $svc = $this->makeService(['tax_rate' => 5]);

        [, $item] = $this->billService($svc);

        $this->assertNull($item->product_id, 'Service line must persist with NULL product_id');
        $this->assertEquals(5.0, (float) $item->tax_rate,
            'Persisted tax_rate must be the DB service rate (5), got: ' . $item->tax_rate);
        $this->assertEquals(25.0, (float) $item->tax_amount,
            '5% of Rs 500 must be Rs 25, got: ' . $item->tax_amount);
        $this->assertEquals(0, (int) $item->is_tax_exempt);
    }

    // ── B. Exempt service — non-exempt client payload must still bill at 0 ─────

    public function test_exempt_service_persists_zero_tax_despite_client_payload(): void
    {
        $svc = $this->makeService(['name' => 'Free Fitting', 'price' => 200, 'tax_rate' => 0, 'is_tax_exempt' => true]);

        [, $item] = $this->billService($svc);

        $this->assertEquals(0.0, (float) $item->tax_rate,
            'Exempt service must persist tax_rate 0, got: ' . $item->tax_rate);
        $this->assertEquals(0.0, (float) $item->tax_amount);
        $this->assertEquals(1, (int) $item->is_tax_exempt,
            'Exempt service must persist is_tax_exempt=1');
    }

    // ── C. Invalid service_id — sale REJECTED, never billed on client values ───

    private function assertServiceLineRejected(int $serviceId, string $why): void
    {
        $before = DB::table('fbr_pos_transactions')->count();
        try {
            $this->callFbrStore($this->servicePayload($serviceId));
            $this->fail("store() must reject a service line with $why (ValidationException expected)");
        } catch (\Illuminate\Validation\ValidationException $ve) {
            $this->assertArrayHasKey('items', $ve->errors(), 'Rejection must ride the items error bag');
        }
        $this->assertSame($before, DB::table('fbr_pos_transactions')->count(),
            "No transaction may be persisted when a service line is rejected ($why)");
    }

    public function test_cross_company_service_id_is_rejected(): void
    {
        $otherCompany = \App\Models\Company::create([
            'name' => 'Other Shop', 'fbr_reporting_enabled' => false,
            'pra_reporting_enabled' => false, 'agent_enabled' => false, 'inventory_enabled' => false,
        ]);
        $foreignSvc = PosService::create([
            'company_id' => $otherCompany->id, 'name' => 'Foreign Zero-Tax Service',
            'price' => 500, 'tax_rate' => 0, 'is_tax_exempt' => true, 'is_active' => true,
        ]);

        // Billing in OUR company with the foreign id must be rejected outright —
        // neither the foreign 0%/exempt nor the client 18% may produce a bill.
        $this->assertServiceLineRejected($foreignSvc->id, 'a cross-company service_id');
    }

    public function test_forged_nonexistent_service_id_is_rejected(): void
    {
        $this->assertServiceLineRejected(999999, 'a forged/nonexistent service_id');
    }

    public function test_deleted_service_id_is_rejected(): void
    {
        $svc = $this->makeService(['name' => 'Doomed Service']);
        $svc->delete();
        $this->assertServiceLineRejected($svc->id, 'a deleted service_id');
    }

    public function test_inactive_service_id_is_rejected(): void
    {
        $svc = $this->makeService(['name' => 'Disabled Service', 'is_active' => false]);
        $this->assertServiceLineRejected($svc->id, 'an inactive service_id');
    }

    // ── D. Exactly ONE catalog reference per line ───────────────────────────────

    public function test_line_with_both_product_and_service_id_is_rejected(): void
    {
        // A taxable 18% product + a valid exempt service id on the SAME line —
        // a crafted payload trying to bill the product at the service's 0% while
        // still moving stock. Must 422 with no transaction persisted.
        $product = $this->makeFbrProduct(['price' => 1000, 'tax_rate' => 18]);
        $svc = $this->makeService(['name' => 'Zero Tax Svc', 'tax_rate' => 0, 'is_tax_exempt' => true]);

        $before = DB::table('fbr_pos_transactions')->count();
        try {
            $this->callFbrStore($this->servicePayload($svc->id, 'Sneaky Line', 1000, [
                'product_id' => $product->id,
                'tax_rate'   => 0,
                'is_tax_exempt' => true,
            ]));
            $this->fail('store() must reject a line carrying BOTH product_id and service_id');
        } catch (\Illuminate\Validation\ValidationException $ve) {
            $this->assertArrayHasKey('items', $ve->errors());
        }
        $this->assertSame($before, DB::table('fbr_pos_transactions')->count(),
            'No transaction may be persisted for a product+service conflict line');
    }

    public function test_service_id_zero_is_rejected_by_validation(): void
    {
        // service_id: 0 must not slip past as "no service" — min:1 validation
        // rejects it before any tax resolution runs.
        $before = DB::table('fbr_pos_transactions')->count();
        try {
            $this->callFbrStore($this->servicePayload(0, 'Zero Id Line'));
            $this->fail('store() must reject service_id = 0 (min:1 validation)');
        } catch (\Illuminate\Validation\ValidationException $ve) {
            $this->assertNotEmpty($ve->errors(), 'service_id=0 must produce a validation error');
        }
        $this->assertSame($before, DB::table('fbr_pos_transactions')->count(),
            'No transaction may be persisted when service_id=0 is rejected');
    }
}
