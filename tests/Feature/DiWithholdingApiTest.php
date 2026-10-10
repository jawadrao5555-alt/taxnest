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
class DiWithholdingApiTest extends TestCase
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

    public function test_api_persists_the_actual_amount_and_keeps_the_flag_separate(): void
    {
        $this->subscription(); $payload = $this->payload('withheld-amount');
        $payload['items'][0] = array_replace($payload['items'][0], ['price' => 10000, 'tax' => 1800, 'st_withheld_at_source' => true, 'st_withheld_amount' => 500.25]);
        $response = $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(201);
        $invoice = Invoice::withoutGlobalScopes()->with(['company', 'items'])->findOrFail($response->json('invoice.id'));
        $this->assertTrue($invoice->items->first()->st_withheld_at_source);
        $this->assertSame(500.25, $invoice->items->first()->st_withheld_amount);
        $this->assertSame(500.25, (new \App\Services\FbrService())->buildPayload($invoice)['items'][0]['salesTaxWithheldAtSource']);
    }

    public function test_flag_without_amount_and_amount_in_boolean_field_are_rejected(): void
    {
        $this->subscription(); $payload = $this->payload('missing-amount'); $payload['items'][0]['st_withheld_at_source'] = true;
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(422);
        $payload['items'][0]['st_withheld_at_source'] = 500;
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(422)->assertJsonValidationErrors('items.0.st_withheld_at_source');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    public function test_panel_renders_numeric_amount_entry_without_removing_the_flag(): void
    {
        $this->subscription();
        $user = \App\Models\User::create(['name' => 'Fictional owner', 'email' => 'withheld@di-test.invalid', 'password' => 'unused', 'role' => 'company_admin', 'is_active' => true, 'company_id' => $this->company->id]);
        $this->actingAs($user)->get('/invoice/create')->assertOk()->assertSee('st_withheld_amount', false)->assertSee('st_withheld_at_source', false)->assertSee('Sales tax withheld amount in PKR', false);
    }
}
