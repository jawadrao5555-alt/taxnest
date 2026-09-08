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
class DiApiSubscriptionAccessTest extends TestCase
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

    /* ─────────────────────────── locked ─────────────────────────── */

    public function test_expired_subscription_cannot_create_invoices_and_gets_structured_error(): void
    {
        $this->subscription(['end_date' => now()->subDay()->toDateString()]);

        $response = $this->api()->postJson('/api/di/v1/invoices', $this->payload('locked-1'));

        $response->assertStatus(402)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error', DiApiSubscriptionAccess::ERROR_CODE)
            ->assertJsonPath('message', __('pos.tl_reason_expired'));

        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count(),
            'A locked company must not gain an invoice row through the API.');
    }

    public function test_company_with_no_subscription_at_all_is_locked(): void
    {
        $response = $this->api()->postJson('/api/di/v1/invoices', $this->payload('locked-2'));

        $response->assertStatus(402)
            ->assertJsonPath('error', DiApiSubscriptionAccess::ERROR_CODE)
            ->assertJsonPath('message', __('pos.tl_reason_no_sub'));
    }

    public function test_expired_trial_is_locked(): void
    {
        $trial = PricingPlan::create([
            'name' => 'DI Trial ' . $this->company->id,
            'product_type' => 'di',
            'price' => 0,
            'is_trial' => true,
            'invoice_limit' => 0,
        ]);
        $this->subscription([
            'pricing_plan_id' => $trial->id,
            'end_date' => null,
            'trial_ends_at' => now()->subHour(),
        ]);

        $this->api()->postJson('/api/di/v1/invoices', $this->payload('locked-3'))
            ->assertStatus(402)
            ->assertJsonPath('error', DiApiSubscriptionAccess::ERROR_CODE)
            ->assertJsonPath('message', __('pos.tl_reason_trial_expired'));
    }

    /* ─────────────────────────── allowed ────────────────────────── */

    public function test_active_subscription_can_create_invoices(): void
    {
        $this->subscription();

        $this->api()->postJson('/api/di/v1/invoices', $this->payload('active-1'))
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('invoice.client_reference', 'active-1');
    }

    public function test_lifetime_override_unlocks_an_expired_plan(): void
    {
        $this->subscription([
            'end_date' => now()->subDay()->toDateString(),
            'override_type' => 'lifetime',
        ]);

        $this->api()->postJson('/api/di/v1/invoices', $this->payload('lifetime-1'))
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok');
    }

    /* ─────────────────────────── read stays open ────────────────── */

    public function test_status_endpoint_still_works_for_a_locked_company(): void
    {
        $subscription = $this->subscription();
        $this->api()->postJson('/api/di/v1/invoices', $this->payload('before-lock'))->assertStatus(201);

        // Plan lapses AFTER the invoice was filed.
        $subscription->update(['end_date' => now()->subDay()->toDateString()]);

        $this->api()->getJson('/api/di/v1/invoices/status?client_reference=before-lock')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('invoice.client_reference', 'before-lock');

        // …while a NEW invoice is refused.
        $this->api()->postJson('/api/di/v1/invoices', $this->payload('after-lock'))
            ->assertStatus(402)
            ->assertJsonPath('error', DiApiSubscriptionAccess::ERROR_CODE);
    }

    public function test_suspended_company_is_still_refused_before_the_access_gate(): void
    {
        $this->subscription();
        $this->company->forceFill(['status' => 'suspended'])->save();

        $this->api()->postJson('/api/di/v1/invoices', $this->payload('suspended-1'))
            ->assertStatus(403)
            ->assertJsonPath('error', 'company_suspended');
    }
}
