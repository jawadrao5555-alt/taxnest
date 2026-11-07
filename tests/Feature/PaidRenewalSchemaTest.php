<?php

namespace Tests\Feature;

use App\Models\{AdminAuditLog, AdminUser, Company, PaymentProof, PricingPlan, Subscription};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;

class PaidRenewalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_renewal_uses_the_real_schema_and_retains_a_leap_day_term_once(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-07 10:00:00'));
        $admin = AdminUser::create([
            'name' => 'Synthetic reviewer', 'email' => 'paid-reviewer@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin',
        ]);
        $company = Company::create([
            'name' => 'Synthetic renewal', 'ntn' => 'RCRENEW1', 'product_type' => 'pos',
            'email' => 'renewal@example.test', 'status' => 'approved', 'company_status' => 'active',
        ]);
        $plan = PricingPlan::create([
            'name' => 'Starter', 'product_type' => 'pos', 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100,
        ]);
        $old = Subscription::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'billing_cycle' => 'annual', 'final_price' => 1000,
            'start_date' => '2026-03-01', 'end_date' => '2028-02-29',
        ]);
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'amount' => 1000, 'proof_path' => 'synthetic-paid-renewal', 'status' => 'pending',
        ]);
        $input = ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => 1000];
        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)
            ->assertRedirect()->assertSessionHas('success');
        $new = Subscription::where('company_id', $company->id)->where('active', true)->sole();
        $this->assertSame('2029-02-28', $new->end_date->toDateString());
        $this->assertSame('2028-02-29', $old->fresh()->end_date->toDateString());
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        $this->assertSame(2, Subscription::where('company_id', $company->id)->count());
        $event = AdminAuditLog::where('target_type', 'PaymentProof')->where('target_id', $proof->id)
            ->where('action', 'Payment proof approved')->sole();
        $this->assertSame('2029-02-28', $event->metadata['period_end']);
        $this->travelBack();
    }
}
