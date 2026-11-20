<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\SignupPackageRequest;
use App\Models\Subscription;
use App\Services\ProspectiveSubscriptionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SignupPackageRequestTest extends TestCase
{
    use RefreshDatabase;

    private function signup(): SignupPackageRequest
    {
        Mail::fake();
        $plan = PricingPlan::create(['name' => 'Kaarobar', 'product_type' => 'di', 'is_trial' => false,
            'price' => 1000, 'price_quarterly' => 2500, 'invoice_limit' => 50, 'user_limit' => 2, 'branch_limit' => 1]);
        $this->post('/register', [
            'requested_plan' => $plan->name, 'requested_billing_cycle' => 'quarterly',
            'name' => 'Synthetic buyer', 'email' => 'signup-sale@example.test',
            'company_name' => 'Synthetic package test', 'company_ntn' => 'TEST-SIGNUP-01',
            'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');

        $sale = SignupPackageRequest::sole();
        $this->assertSame('pending', $sale->status);
        $this->assertSame('quarterly', $sale->billing_cycle);
        $this->assertSame('signup', $sale->quote_source);
        $this->assertSame('2500.00', $sale->quoted_amount);
        $this->assertDatabaseCount('subscription_payments', 0);
        return $sale;
    }

    private function admin(string $role = 'admin'): AdminUser
    {
        return AdminUser::create(['role' => $role, 'name' => 'Synthetic reviewer', 'email' => $role.'@example.test', 'password' => bcrypt('Synthetic123!')]);
    }

    public function test_account_confirmation_requires_payment_method_and_uses_original_package_quote(): void
    {
        $sale = $this->signup();
        $company = Company::findOrFail($sale->company_id);
        $trial = Subscription::where('company_id', $company->id)->sole();
        $trialEnd = ProspectiveSubscriptionPolicy::snapshot($trial)['trial_ends_at'];
        $this->actingAs($this->admin(), 'admin');
        $detail = route('saas.admin.package-requests.show', $sale);
        $this->post(route('saas.admin.companies.approve', $company->id))->assertRedirect($detail);
        $this->assertSame('pending', $company->fresh()->status);
        $confirm = route('saas.admin.package-requests.confirm', $sale);
        $data = ['verified_received_amount' => '2500.00', 'payment_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $this->post($confirm, $data)->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('subscription_payments', 0);
        PricingPlan::whereKey($sale->pricing_plan_id)->update(['price_quarterly' => 2700]);
        $this->post($confirm, $data + ['payment_method' => 'cash'])->assertSessionHasNoErrors()->assertRedirect($detail);
        $this->post($confirm, $data + ['payment_method' => 'cash'])->assertRedirect($detail);
        $this->post($confirm, array_merge($data, ['payment_method' => 'card', 'idempotency_key' => (string) Str::uuid()]))
            ->assertRedirect($detail)->assertSessionHas('success', fn ($message) => str_contains($message, 'No new payment was recorded.'));
        $this->get($detail)->assertOk()->assertSee('Print receipt')->assertSee('Download receipt PDF');
        $this->assertSame('approved', $company->fresh()->status);
        $this->assertSame('approved', $sale->fresh()->status);
        $this->assertNotNull($sale->fresh()->subscription_invoice_id);
        $this->assertDatabaseCount('subscription_payments', 1);
        $paid = Subscription::where('company_id', $company->id)->where('active', true)->sole();
        $this->assertEquals(2500, $paid->final_price);
        $this->assertSame($trialEnd, ProspectiveSubscriptionPolicy::snapshot($paid)['paid_starts_at']);
    }

    public function test_rejection_records_reason_and_actor_without_changing_trial_or_posting_money(): void
    {
        $sale = $this->signup();
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $reject = route('saas.admin.package-requests.reject', $sale);
        $this->post($reject)->assertSessionHasErrors('rejection_reason');
        $this->post($reject, ['rejection_reason' => 'Payment not received.'])->assertSessionHasNoErrors();
        $sale->refresh();
        $this->assertSame('rejected', $sale->status);
        $this->assertSame('Payment not received.', $sale->rejection_reason);
        $this->assertEquals($admin->id, $sale->reviewer_admin_id);
        $this->assertSame('pending', Company::findOrFail($sale->company_id)->status);
        $this->assertTrue(Subscription::where('company_id', $sale->company_id)->sole()->isTrialActive());
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_support_user_cannot_approve_or_reject_a_sale(): void
    {
        $sale = $this->signup();
        $this->actingAs($this->admin('support'), 'admin');
        $this->post(route('saas.admin.package-requests.confirm', $sale))->assertForbidden();
        $this->post(route('saas.admin.package-requests.reject', $sale))->assertForbidden();
        $this->assertSame('pending', $sale->fresh()->status);
        $this->assertDatabaseCount('subscription_payments', 0);
    }
}
