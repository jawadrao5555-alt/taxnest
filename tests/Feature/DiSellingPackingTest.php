<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\DiSellingPacking;
use App\Services\FbrService;
use App\Services\IntegrityHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiSellingPackingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::create(['name' => 'Synthetic packing buyer', 'ntn' => 'DIPACKTEST',
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'province' => 'Punjab']);
        $this->key = 'dik_'.$this->company->id.'_packing_test';
        $this->company->forceFill(['di_api_key_hash' => hash('sha256', $this->key)])->save();
        $plan = PricingPlan::create(['name' => 'Kaarobar', 'product_type' => 'di', 'price' => 1000,
            'invoice_limit' => -1, 'is_trial' => false]);
        Subscription::create(['company_id' => $this->company->id, 'pricing_plan_id' => $plan->id,
            'active' => true, 'start_date' => now()->subDay(), 'end_date' => now()->addYear()]);
    }

    public static function examples(): array
    {
        return [
            'DAP bag' => ['KG', 'Bori 50 KG', '50', '1', '5000', 50, 100, 5000],
            'rice bags' => ['KG', 'Bag 25 KG', '25', '2', '2500', 50, 100, 5000],
            'carton twelve half litres' => ['LTR', 'Carton 12 x 500 ml', '6', '3', '6000', 18, 1000, 18000],
            'one and half litre bottles' => ['LTR', 'Bottle 1.5 litre', '1.5', '2', '1500', 3, 1000, 3000],
            'biscuit box' => ['PCS', 'Box 12 pieces', '12', '1', '120', 12, 10, 120],
            'nonterminating base rate' => ['PCS', 'Box 12 pieces', '12', '1', '100', 12, 8.333333, 100],
        ];
    }

    #[DataProvider('examples')]
    public function test_actual_api_persists_matching_packing_base_quantity_and_fiscal_totals(string $uom, string $label,
        string $factor, string $qty, string $price, float $baseQty, float $basePrice, float $value): void
    {
        [$product, $pack] = $this->product($uom, $label, $factor);
        $input = $this->payload($product, $pack, $qty, $price);
        $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('/api/di/v1/invoices', $input)->assertCreated();
        $invoice = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();
        $item = $invoice->items()->sole();
        $this->assertEquals($baseQty, $item->quantity);
        $this->assertEquals($basePrice, $item->price);
        $this->assertEquals($value, $invoice->total_value_excluding_st);
        $this->assertSame($qty, $item->selling_snapshot['selling_quantity']);
        $this->assertSame($factor, $item->selling_snapshot['factor']);
        $payload = app(FbrService::class)->buildPayload($invoice->load('items', 'company'), true);
        $this->assertEquals($baseQty, $payload['items'][0]['quantity']);
        $this->assertEquals($value, $payload['items'][0]['valueSalesExcludingST']);
        $this->assertEquals($invoice->total_amount, $payload['items'][0]['totalValues']);
        Http::assertNothingSent();
    }

    public function test_api_retry_reuses_original_snapshot_after_pack_definition_changes_and_rejects_changed_request(): void
    {
        [$product, $pack] = $this->product('KG', '25 KG bag', '25');
        $input = $this->payload($product, $pack, '2', '2500');
        $api = $this->withHeader('Authorization', 'Bearer '.$this->key);
        $api->postJson('/api/di/v1/invoices', $input)->assertCreated();
        $invoice = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();
        $snapshot = $invoice->items()->sole()->selling_snapshot;
        $product->update(['selling_packings' => DiSellingPacking::definitions([['label' => '50 KG bag', 'factor' => '50']], 'KG')]);
        $api->postJson('/api/di/v1/invoices', $input)->assertOk();
        $this->assertSame($snapshot, $invoice->items()->sole()->selling_snapshot);
        $input['items'][0]['selling_packing_id'] = $product->selling_packings[0]['id'];
        $api->postJson('/api/di/v1/invoices', $input)->assertStatus(409);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_foreign_product_or_incompatible_fiscal_unit_is_refused_without_invoice(): void
    {
        [$product, $pack] = $this->product('KG', 'Bag', '25');
        $input = $this->payload($product, $pack, '1', '2500');
        $input['items'][0]['default_uom'] = 'Liter';
        $api = $this->withHeader('Authorization', 'Bearer '.$this->key);
        $api->postJson('/api/di/v1/invoices', $input)->assertStatus(422);
        $other = Company::create(['name' => 'Other tenant', 'ntn' => 'DIPACKOTHER', 'product_type' => 'di']);
        $product->update(['company_id' => $other->id]);
        $input['items'][0]['default_uom'] = 'KG';
        $api->postJson('/api/di/v1/invoices', $input)->assertStatus(422);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_inclusive_standard_and_third_schedule_rates_reconcile_without_live_fiscal_calls(): void
    {
        [$product, $pack] = $this->product('PCS', 'Twelve unit box', '12');
        foreach (['standard' => ['118', null, 118], '3rd_schedule' => ['143.20', '240', 143.2]] as $schedule => [$price, $mrp, $total]) {
            $input = $this->payload($product, $pack, '1', $price);
            $input['items'][0]['price_includes_tax'] = true;
            $input['items'][0]['schedule_type'] = $schedule;
            if ($mrp !== null) {
                $input['items'][0]['mrp'] = $mrp;
            }
            $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('/api/di/v1/invoices', $input)->assertCreated();
            $invoice = Invoice::withoutGlobalScopes()->where('client_reference', $input['client_reference'])->sole();
            $this->assertEquals($total, $invoice->total_amount);
            $this->assertEquals(100, $invoice->total_value_excluding_st);
            $payload = app(FbrService::class)->buildPayload($invoice->load('items', 'company'), true);
            $this->assertEquals($total, $payload['items'][0]['totalValues']);
        }
        Http::assertNothingSent();
    }

    public function test_packing_return_uses_original_conversion_after_catalogue_edit_and_blocks_excess(): void
    {
        [$product, $pack] = $this->product('KG', '25 KG bag', '25');
        $sale = $this->payload($product, $pack, '2', '2500');
        $api = $this->withHeader('Authorization', 'Bearer '.$this->key);
        $api->postJson('/api/di/v1/invoices', $sale)->assertCreated();
        $invoice = Invoice::withoutGlobalScopes()->where('client_reference', $sale['client_reference'])->sole();
        $invoice->update(['status' => 'locked']);
        $original = $invoice->items()->sole();
        $product->update(['selling_packings' => DiSellingPacking::definitions([['label' => '50 KG bag', 'factor' => '50']], 'KG')]);
        $input = $this->payload($product, $pack, '1', '2500');
        $input['document_type'] = 'Credit Note';
        $input['reference_invoice_number'] = $invoice->invoice_number;
        $input['items'][0]['source_invoice_item_id'] = $original->id;
        $input['items'][0]['mrp'] = '';
        $tampered = $input;
        $tampered['items'][0]['mrp'] = '3000';
        $api->postJson('/api/di/v1/invoices', $tampered)->assertStatus(422);
        $api->postJson('/api/di/v1/invoices', $input)->assertCreated();
        $return = Invoice::withoutGlobalScopes()->where('client_reference', $input['client_reference'])->sole();
        $this->assertEquals(25, $return->items()->sole()->quantity);
        $this->assertSame('25', $return->items()->sole()->selling_snapshot['factor']);
        $input['client_reference'] = 'excess-packing-return';
        $input['items'][0]['quantity'] = '2';
        $api->postJson('/api/di/v1/invoices', $input)->assertStatus(422);
        $this->assertSame(2, Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame($original->selling_snapshot, $invoice->items()->sole()->selling_snapshot);
    }

    public function test_current_proof_covers_packing_snapshot_and_legacy_hashes_are_preserved(): void
    {
        [$product, $pack] = $this->product('KG', '25 KG bag', '25');
        $input = $this->payload($product, $pack, '1', '2500');
        $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('/api/di/v1/invoices', $input)->assertCreated();
        $invoice = Invoice::withoutGlobalScopes()->where('client_reference', $input['client_reference'])->sole();
        $invoice->integrity_hash = IntegrityHashService::generate($invoice);
        $invoice->save();
        $hash = $invoice->integrity_hash;
        $this->assertSame('verified_current', IntegrityHashService::verificationStatus($invoice));
        $item = $invoice->items()->sole();
        $snapshot = $item->selling_snapshot;
        $snapshot['factor'] = '50';
        $item->update(['selling_snapshot' => $snapshot]);
        $this->assertSame('failed', IntegrityHashService::verificationStatus($invoice));
        $this->assertSame($hash, $invoice->fresh()->integrity_hash);
        $method = new \ReflectionMethod(IntegrityHashService::class, 'generateVersion');
        foreach ([2, 3] as $version) {
            $legacyHash = $method->invoke(null, $invoice->load('items'), $version);
            $invoice->update(['integrity_hash' => $legacyHash]);
            $this->assertSame('verified_legacy_v'.$version, IntegrityHashService::verificationStatus($invoice));
            $this->assertSame($legacyHash, $invoice->fresh()->integrity_hash);
        }
    }

    public function test_unrepresentable_precision_and_excessive_amount_are_refused(): void
    {
        [$product, $pack] = $this->product('KG', 'Micropack', '0.000001');
        $input = $this->payload($product, $pack, '0.0001', '100');
        $api = $this->withHeader('Authorization', 'Bearer '.$this->key);
        $api->postJson('/api/di/v1/invoices', $input)->assertStatus(422);
        [$product, $pack] = $this->product('KG', 'Large packing', '1');
        $input = $this->payload($product, $pack, '100', '999999999999.99');
        $api->postJson('/api/di/v1/invoices', $input)->assertStatus(422);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    private function product(string $uom, string $label, string $factor): array
    {
        $packings = DiSellingPacking::definitions([['label' => $label, 'factor' => $factor]], $uom);
        $product = Product::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Synthetic packing product',
            'hs_code' => '33049900', 'uom' => $uom, 'default_price' => 100, 'default_tax_rate' => 18,
            'schedule_type' => 'standard', 'is_active' => true, 'selling_packings' => $packings]);

        return [$product, $packings[0]];
    }

    private function payload(Product $product, array $pack, string $qty, string $price): array
    {
        return ['client_reference' => 'packing-'.bin2hex(random_bytes(8)), 'mode' => 'draft',
            'buyer_name' => 'Synthetic buyer', 'buyer_address' => 'Lahore', 'document_type' => 'Sale Invoice',
            'destination_province' => 'Punjab', 'items' => [['product_id' => $product->id,
                'selling_packing_id' => $pack['id'], 'hs_code' => $product->hs_code, 'default_uom' => $product->uom,
                'description' => $product->name, 'quantity' => $qty, 'price' => $price,
                'tax' => round((float) $qty * (float) $price * 0.18, 2), 'tax_rate' => 18, 'schedule_type' => 'standard']]];
    }
}
