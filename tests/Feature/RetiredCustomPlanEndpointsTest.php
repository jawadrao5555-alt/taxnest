<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\SecurityLog;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetiredCustomPlanEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;
    private PricingPlan $existingCustom;
    private Subscription $existingSub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Custom Plan Legacy Co',
            'ntn' => 'CUSTOM-PLAN-LEGACY-1',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
            'province' => 'Punjab',
        ]);
        $this->admin = User::create([
            'name' => 'DI Admin',
            'email' => 'custom-plan-admin@example.test',
            'password' => Hash::make('password'),
            'company_id' => $this->company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ]);
        $this->existingCustom = PricingPlan::create([
            'name' => 'Custom Plan',
            'product_type' => 'di',
            'price' => 111,
            'invoice_limit' => 80,
            'user_limit' => 2,
            'branch_limit' => 1,
            'is_trial' => false,
            'features' => ['custom' => true],
        ]);
        $this->existingSub = Subscription::create([
            'company_id' => $this->company->id,
            'pricing_plan_id' => $this->existingCustom->id,
            'billing_cycle' => 'annual',
            'discount_percent' => 6,
            'final_price' => 1000,
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'active' => true,
        ]);
    }

    public function test_retired_quote_endpoint_returns_410_and_creates_nothing(): void
    {
        $plans = PricingPlan::count();
        $subs = Subscription::count();

        $this->actingAs($this->admin)
            ->postJson('/billing/calculate-custom', [
                'invoice_limit' => 500,
                'user_count' => 9,
                'branch_count' => 3,
                'billing_cycle' => 'annual',
            ])->assertStatus(410);

        $this->assertSame($plans, PricingPlan::count());
        $this->assertSame($subs, Subscription::count());
        $this->assertLegacyCustomPlanUntouched();
    }

    public function test_retired_subscribe_redirects_without_mutating_plans_or_limits(): void
    {
        $this->actingAs($this->admin)
            ->post('/billing/subscribe-custom', [
                'invoice_limit' => 500,
                'user_count' => 9,
                'branch_count' => 3,
                'billing_cycle' => 'annual',
            ])->assertRedirect(route('billing.plans'));

        $this->assertSame(1, PricingPlan::where('name', 'Custom Plan')->count());
        $this->assertSame(1, Subscription::where('company_id', $this->company->id)->where('active', true)->count());
        $this->assertSame($this->existingCustom->id, (int) $this->existingSub->fresh()->pricing_plan_id);
        $this->assertSame(80, (int) $this->existingCustom->fresh()->invoice_limit);
        $this->assertSame(2, (int) $this->existingCustom->fresh()->user_limit);
        $this->assertLegacyCustomPlanUntouched();
    }

    public function test_get_custom_plan_redirects_to_current_packages(): void
    {
        $this->actingAs($this->admin)
            ->get('/billing/custom-plan')
            ->assertRedirect(route('billing.plans'));

        $this->assertLegacyCustomPlanUntouched();
    }

    public function test_unauthenticated_requests_cannot_use_retired_or_catalogue_billing_posts(): void
    {
        $this->get('/billing/custom-plan')->assertRedirect();
        $this->post('/billing/calculate-custom', [
            'invoice_limit' => 500,
            'user_count' => 9,
            'branch_count' => 3,
            'billing_cycle' => 'annual',
        ])->assertRedirect();
        $this->post('/billing/subscribe-custom', [
            'invoice_limit' => 500,
            'user_count' => 9,
            'branch_count' => 3,
            'billing_cycle' => 'annual',
        ])->assertRedirect();
        $this->post('/billing/subscribe', [
            'plan_id' => $this->existingCustom->id,
            'billing_cycle' => 'annual',
        ])->assertRedirect();

        $this->assertLegacyCustomPlanUntouched();
    }

    public function test_catalogue_subscribe_route_still_validates_and_does_not_touch_legacy_custom_plan(): void
    {
        $this->assertTrue(collect(Route::getRoutes())->contains(
            fn ($route) => $route->uri() === 'billing/subscribe' && in_array('POST', $route->methods(), true)
        ));

        $this->actingAs($this->admin)
            ->postJson('/billing/subscribe', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id']);

        $this->assertLegacyCustomPlanUntouched();
    }

    private function assertLegacyCustomPlanUntouched(): void
    {
        $sub = $this->existingSub->fresh();
        $plan = $this->existingCustom->fresh();
        $this->assertSame(1, (int) $sub->active);
        $this->assertSame($this->existingCustom->id, (int) $sub->pricing_plan_id);
        $this->assertSame(80, (int) $plan->invoice_limit);
        $this->assertSame(2, (int) $plan->user_limit);
        $this->assertSame(1, (int) $plan->branch_limit);
        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertSame(
            $this->existingSub->end_date->toDateString(),
            $sub->end_date->toDateString()
        );
        $this->assertSame(
            $this->existingSub->start_date->toDateString(),
            $sub->start_date->toDateString()
        );
        $this->assertSame(1, PricingPlan::where('name', 'Custom Plan')->count());
        $this->assertSame(1, Subscription::where('company_id', $this->company->id)->count());
        if (Schema::hasTable('security_logs')) {
            $this->assertSame(0, SecurityLog::count());
        }
    }
}
