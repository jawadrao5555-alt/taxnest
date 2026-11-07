<?php

namespace Tests\Feature;

use App\Models\{Company, PricingPlan, Subscription, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiDashboardPurchasedEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_quota_and_tier_use_frozen_purchased_entitlement_after_catalogue_edit(): void
    {
        $company = Company::create([
            'name' => 'Synthetic DI dashboard buyer',
            'ntn' => 'DI-DASH-ENTITLEMENT',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
        $plan = PricingPlan::create([
            'name' => 'Kaarobar',
            'product_type' => 'di',
            'price' => 12000,
            'invoice_limit' => 7,
            'is_trial' => false,
            'is_active' => true,
        ]);
        Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'yearly',
            'final_price' => 12000,
            'start_date' => now()->startOfDay(),
            'end_date' => now()->addYear()->startOfDay(),
            'active' => true,
            'purchase_snapshot' => [
                'version' => 1,
                'company_id' => $company->id,
                'plan' => [
                    'id' => $plan->id,
                    'name' => 'Kaarobar',
                    'product_type' => 'di',
                    'is_trial' => false,
                    'invoice_limit' => 7,
                ],
            ],
        ]);
        $plan->update(['name' => 'Asaan', 'invoice_limit' => 999]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'web')->get('/dashboard')
            ->assertOk()
            ->assertViewHas('invoiceLimit', 7)
            ->assertViewHas('planTier', 'enterprise');

        $this->assertSame('Asaan', $plan->fresh()->name);
        $this->assertSame(999, (int) $plan->fresh()->invoice_limit);
        $this->assertSame(7, (int) $company->activeSubscription()->firstOrFail()->entitlementPlan()->invoice_limit);
    }
}
