<?php

namespace Tests\Feature;

use App\Http\Middleware\DiApiSubscriptionAccess;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The DI push API's create endpoint mirrored the panel's monthly quota check
 * but not the panel's FIRST check — SubscriptionAccessService::hasAccess()
 * (CheckPlanLimit step 1). An approved company whose plan had expired could
 * therefore keep creating invoices through its API key while its panel was
 * locked. These tests pin the gate on the billable endpoint and keep the
 * read-only status endpoint open for reconciliation.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/DiApiSubscriptionAccessTest.php --testdox
 */
class DiFinancialSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private string $apiKey;
    private PricingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'DI API Access Co',
            'ntn' => 'DIAPI-ACCESS-1',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
            'province' => 'Punjab',
        ]);

        $this->apiKey = "dik_{$this->company->id}_access_test";
        $this->company->forceFill(['di_api_key_hash' => hash('sha256', $this->apiKey)])->save();

        $this->plan = PricingPlan::create([
            'name' => 'DI Access Plan ' . $this->company->id,
            'product_type' => 'di',
            'price' => 5000,
            'is_trial' => false,
            'invoice_limit' => -1,
        ]);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function subscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $this->company->id,
            'pricing_plan_id' => $this->plan->id,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'override_type' => 'none',
        ], $overrides));
    }

    private function api()
    {
        return $this->withHeader('Authorization', "Bearer {$this->apiKey}");
    }

    private function payload(string $reference): array
    {
        return [
            'client_reference' => $reference,
            'mode' => 'draft',
            'buyer_name' => 'API Buyer',
            'buyer_address' => 'Lahore',
            'document_type' => 'Sale Invoice',
            'destination_province' => 'Punjab',
            'items' => [[
                'hs_code' => '33049900',
                'description' => 'Shampoo 200ml',
                'quantity' => 1,
                'price' => 500,
                'tax' => 90,
                'schedule_type' => 'standard',
                'tax_rate' => 18,
            ]],
        ];
    }

    public function test_api_totals_include_further_tax_and_match_the_payload(): void
    {
        $this->subscription(); $payload = $this->payload('further-tax');
        $payload['items'][0]['further_tax'] = 20;
        $response = $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(201)->assertJsonPath('invoice.total_amount', 610);
        $invoice = Invoice::withoutGlobalScopes()->with(['company', 'items'])->findOrFail($response->json('invoice.id'));
        $fiscal = (new \App\Services\FbrService())->buildPayload($invoice);
        $this->assertSame(610.0, $fiscal['items'][0]['totalValues']);
        $pdf = \App\Services\InvoicePdfService::buildData($invoice);
        $this->assertSame(610.0, $pdf['net_receivable']);
        $this->assertSame([], \App\Services\DiInvoiceMath::snapshotErrors($invoice, $fiscal));
    }

    public function test_mrp_tax_base_does_not_replace_actual_sale_consideration(): void
    {
        $this->subscription(); $payload = $this->payload('mrp-sale');
        $payload['items'][0] = array_replace($payload['items'][0], ['price' => 80, 'tax' => 18, 'mrp' => 100, 'schedule_type' => '3rd_schedule']);
        $response = $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(201)->assertJsonPath('invoice.total_amount', 98);
        $invoice = Invoice::withoutGlobalScopes()->with(['company', 'items'])->findOrFail($response->json('invoice.id'));
        $fiscal = (new \App\Services\FbrService())->buildPayload($invoice);
        $this->assertSame(80.0, $fiscal['items'][0]['valueSalesExcludingST']);
        $this->assertSame(100.0, $fiscal['items'][0]['fixedNotifiedValueOrRetailPrice']);
        $this->assertSame(18.0, $fiscal['items'][0]['salesTaxApplicable']);
        $this->assertSame([], \App\Services\DiInvoiceMath::snapshotErrors($invoice, $fiscal));
    }

    public function test_conflicting_supplied_tax_is_rejected_before_creation(): void
    {
        $this->subscription(); $payload = $this->payload('wrong-tax'); $payload['items'][0]['tax'] = 0;
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_historical_header_mismatch_requires_review_without_rewriting_it(): void
    {
        $this->subscription(); $response = $this->api()->postJson('/api/di/v1/invoices', $this->payload('historic'))->assertStatus(201);
        $invoice = Invoice::withoutGlobalScopes()->with(['company', 'items'])->findOrFail($response->json('invoice.id'));
        $invoice->total_amount = 1; $invoice->save();
        $fiscal = (new \App\Services\FbrService())->buildPayload($invoice);
        $this->assertNotEmpty(\App\Services\DiInvoiceMath::snapshotErrors($invoice, $fiscal));
        $this->assertSame('1', (string) $invoice->fresh()->total_amount);
    }
    public function test_panel_form_and_draft_save_share_the_mrp_calculation(): void
    {
        $this->subscription();
        $user = \App\Models\User::create(['name' => 'Fictional owner', 'email' => 'panel@di-test.invalid', 'password' => 'unused', 'role' => 'company_admin', 'is_active' => true, 'company_id' => $this->company->id]);
        $form = $this->actingAs($user)->get('/invoice/create');
        $this->assertSame(200, $form->getStatusCode(), (string) $form->headers->get('Location'));
        $payload = $this->payload('panel-mrp'); unset($payload['client_reference'], $payload['mode']);
        $payload['save_as_draft'] = '1';
        $payload['items'][0] = array_replace($payload['items'][0], ['price' => 80, 'tax' => 18, 'mrp' => 100, 'schedule_type' => '3rd_schedule']);
        $this->actingAs($user)->post('/invoice/store', $payload)->assertRedirect();
        $invoice = Invoice::withoutGlobalScopes()->sole();
        $this->assertSame(98.0, (float) $invoice->total_amount);
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->fbr_invoice_number);
    }

}
