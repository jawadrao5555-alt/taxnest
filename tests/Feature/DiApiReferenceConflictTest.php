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
class DiApiReferenceConflictTest extends TestCase
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

    public function test_same_payload_replays_but_changed_amount_or_mode_conflicts(): void
    {
        $this->subscription();
        $payload = $this->payload('same-ref');
        $created = $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(201);
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('invoice.id', $created->json('invoice.id'));
        $payload['items'][0]['price'] = 600;
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(409)->assertJsonPath('error', 'client_reference_conflict');
        $payload = $this->payload('same-ref'); $payload['mode'] = 'submit';
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(409);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
    }

    public function test_pre_upgrade_reference_compares_actual_saved_fields(): void
    {
        $this->subscription(); $payload = $this->payload('old-ref');
        $created = $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(201);
        Invoice::withoutGlobalScopes()->findOrFail($created->json('invoice.id'))->update(['di_api_request_hash' => null]);
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $payload['buyer_name'] = 'Different buyer';
        $this->api()->postJson('/api/di/v1/invoices', $payload)->assertStatus(409);
    }

    public function test_reference_is_scoped_to_the_authenticated_company(): void
    {
        $this->subscription();
        $this->api()->postJson('/api/di/v1/invoices', $this->payload('shared-ref'))->assertStatus(201);
        $this->company = Company::create(['name' => 'Second fictional seller', 'ntn' => 'TEST-SECOND', 'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'province' => 'Punjab']);
        $this->apiKey = 'dik_' . $this->company->id . '_second';
        $this->company->forceFill(['di_api_key_hash' => hash('sha256', $this->apiKey)])->save();
        $this->subscription();
        $this->api()->postJson('/api/di/v1/invoices', $this->payload('shared-ref'))->assertStatus(201)->assertJsonPath('duplicate', false);
        $this->assertSame(2, Invoice::withoutGlobalScopes()->count());
    }
}
