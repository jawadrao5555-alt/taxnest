<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DistributorDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DistributorPaymentApprovalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = AdminUser::create([
            'name'=>'Root', 'email'=>'approval-root@example.test',
            'password'=>'password', 'role'=>'super_admin',
        ]);
    }

    private function fixture(string $suffix): array
    {
        $agent = Agent::create([
            'name'=>"Distributor {$suffix}", 'email'=>"d-{$suffix}@example.test",
            'status'=>'active', 'is_active'=>true, 'discount_percent'=>10,
        ]);
        $company = Company::create([
            'name'=>"Company {$suffix}", 'ntn'=>"APPROVAL-{$suffix}",
            'product_type'=>'di', 'agent_id'=>$agent->id,
            'status'=>'pending', 'company_status'=>'pending',
        ]);
        $plan = PricingPlan::create([
            'name'=>"Annual {$suffix}", 'product_type'=>'di', 'price'=>12000,
            'price_yearly'=>12000, 'is_trial'=>false, 'is_public'=>true, 'invoice_limit'=>-1,
        ]);
        $snapshot = DistributorDiscountService::quote($company, $plan, 'annual', false);
        return [$agent, $company, $plan, $snapshot];
    }

    private function pendingProof(Company $company, PricingPlan $plan, array $snapshot): PaymentProof
    {
        return PaymentProof::create([
            'company_id'=>$company->id, 'pricing_plan_id'=>$plan->id,
            'billing_cycle'=>'annual', 'amount'=>777, 'status'=>'pending',
            'proof_path'=>'payment-proofs/manual-fixture.pdf',
            'distributor_quote_snapshot'=>$snapshot, 'distributor_net_amount'=>null,
        ]);
    }

    private function approve(PaymentProof $proof, PricingPlan $plan, array $extra = [])
    {
        return $this->actingAs($this->admin, 'admin')->from('/admin/payment-proofs')
            ->post("/admin/payment-proofs/{$proof->id}/approve", [
                'pricing_plan_id'=>$plan->id, 'billing_cycle'=>'annual',
            ] + $extra);
    }

    public function test_snapshot_approval_requires_an_exact_independently_verified_amount(): void
    {
        [, $company, $plan, $snapshot] = $this->fixture('required');
        $proof = $this->pendingProof($company, $plan, $snapshot);

        $this->approve($proof, $plan)
            ->assertRedirect('/admin/payment-proofs')
            ->assertSessionHasErrors('verified_received_amount');
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(0, AgentCommission::where('payment_proof_id', $proof->id)->count());

        $this->approve($proof, $plan, ['verified_received_amount'=>$snapshot['net_quote'] + 1])
            ->assertRedirect('/admin/payment-proofs')->assertSessionHas('error');
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(0, AgentCommission::where('payment_proof_id', $proof->id)->count());
    }

    public function test_exact_snapshot_net_is_frozen_assigned_and_used_as_commission_base(): void
    {
        [, $company, $plan, $snapshot] = $this->fixture('success');
        $proof = $this->pendingProof($company, $plan, $snapshot);

        $this->approve($proof, $plan, ['verified_received_amount'=>$snapshot['net_quote']])
            ->assertRedirect('/admin/payment-proofs')->assertSessionHas('success');

        $fresh = $proof->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertEquals($snapshot, $fresh->distributor_quote_snapshot);
        $this->assertSame((float)$snapshot['net_quote'], (float)$fresh->distributor_net_amount);
        $this->assertNotNull($fresh->subscription_id);
        $this->assertDatabaseHas('subscriptions', [
            'id'=>$fresh->subscription_id, 'company_id'=>$company->id, 'pricing_plan_id'=>$plan->id,
        ]);
        $commission = AgentCommission::where('payment_proof_id', $proof->id)->firstOrFail();
        $this->assertSame((float)$snapshot['net_quote'], (float)$commission->base_amount);
    }

    public function test_snapshot_plan_company_and_attribution_tampering_cannot_assign_a_subscription(): void
    {
        foreach (['plan_id', 'company_id', 'agent_id'] as $field) {
            [, $company, $plan, $snapshot] = $this->fixture('tamper-'.$field);
            $snapshot[$field] = 999999;
            $proof = $this->pendingProof($company, $plan, $snapshot);

            $this->approve($proof, $plan, ['verified_received_amount'=>$snapshot['net_quote']])
                ->assertSessionHas('error');
            $this->assertSame('pending', $proof->fresh()->status);
            $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
            $this->assertSame(0, AgentCommission::where('payment_proof_id', $proof->id)->count());
        }
    }

    public function test_zero_discount_renewal_snapshot_keeps_attribution_and_validates(): void
    {
        [$agent, $company, $plan] = $this->fixture('renewal');
        PaymentProof::create([
            'company_id'=>$company->id, 'pricing_plan_id'=>$plan->id, 'billing_cycle'=>'annual',
            'amount'=>12000, 'proof_path'=>'payment-proofs/prior-annual.pdf',
            'status'=>'verified', 'verified_at'=>now()->subYear(),
        ]);

        $snapshot = DistributorDiscountService::quote($company, $plan, 'annual', false);
        $this->assertSame($agent->id, $snapshot['agent_id']);
        $this->assertSame(0.0, (float)$snapshot['discount_percent']);
        $this->assertNull(DistributorDiscountService::validateSnapshot($snapshot, $company, $plan, 'annual'));
    }

    public function test_real_submission_preserves_claim_but_keeps_server_net_pending(): void
    {
        Storage::fake('local');
        [, $company, $plan] = $this->fixture('submission');
        $user = User::create([
            'name'=>'Owner', 'email'=>'submission-owner@example.test', 'password'=>'password',
            'company_id'=>$company->id, 'role'=>'company_admin', 'is_active'=>true,
        ]);

        $this->actingAs($user)->from('/billing')->post('/payment-proof', [
            'pricing_plan_id'=>$plan->id, 'billing_cycle'=>'annual',
            'amount'=>123.45, 'proof'=>UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertRedirect('/billing')->assertSessionHas('payment_proof', 'submitted');

        $proof = PaymentProof::where('company_id', $company->id)->latest('id')->firstOrFail();
        $this->assertSame(123.45, (float)$proof->amount);
        $this->assertNotSame(123.45, (float)$proof->distributor_quote_snapshot['net_quote']);
        $this->assertNull($proof->distributor_net_amount);
        $this->assertSame($company->id, $proof->distributor_quote_snapshot['company_id']);
        $this->assertSame($plan->id, $proof->distributor_quote_snapshot['plan_id']);
    }

    public function test_di_direct_subscribe_and_later_proof_reuse_the_first_immutable_quote(): void
    {
        Storage::fake('local');
        [$agent, $company, $plan] = $this->fixture('direct-subscribe');
        $company->update(['status'=>'approved', 'company_status'=>'active']);
        $user = User::create([
            'name'=>'Direct Owner', 'email'=>'direct-subscribe@example.test', 'password'=>'password',
            'company_id'=>$company->id, 'role'=>'company_admin', 'is_active'=>true,
        ]);

        $this->actingAs($user)->post('/billing/subscribe', [
            'plan_id'=>$plan->id, 'billing_cycle'=>'annual',
        ])->assertRedirect('/dashboard');
        $first = Subscription::where('company_id', $company->id)->where('active', true)->firstOrFail();
        $snapshot = $first->distributor_quote_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(10.0, (float)$snapshot['discount_percent']);
        $firstPrice = (float)$first->final_price;

        $agent->update(['discount_percent'=>1]);
        SystemSetting::set('distributor_max_discount', 1);
        $this->actingAs($user)->post('/billing/subscribe', [
            'plan_id'=>$plan->id, 'billing_cycle'=>'annual',
        ])->assertRedirect('/dashboard');
        $repeated = Subscription::where('company_id', $company->id)->where('active', true)->firstOrFail();
        $this->assertEquals($snapshot, $repeated->distributor_quote_snapshot);
        $this->assertSame($firstPrice, (float)$repeated->final_price);

        $this->actingAs($user)->from('/billing/plans')->post('/payment-proof', [
            'pricing_plan_id'=>$plan->id, 'billing_cycle'=>'annual', 'amount'=>1,
            'proof'=>UploadedFile::fake()->create('direct-receipt.pdf', 10, 'application/pdf'),
        ])->assertRedirect('/billing/plans');
        $proof = PaymentProof::where('company_id', $company->id)->latest('id')->firstOrFail();
        $this->assertEquals($snapshot, $proof->distributor_quote_snapshot);
        $this->assertSame($firstPrice, (float)$proof->distributor_quote_snapshot['net_quote']);
    }

    public function test_legacy_proof_still_requires_verified_amount_and_freezes_it_for_commission(): void
    {
        [, $company, $plan] = $this->fixture('legacy-no-snapshot');
        $proof = PaymentProof::create([
            'company_id'=>$company->id, 'pricing_plan_id'=>$plan->id,
            'billing_cycle'=>'annual', 'amount'=>12000, 'proof_path'=>'payment-proofs/legacy.pdf',
            'status'=>'pending', 'distributor_quote_snapshot'=>null, 'distributor_net_amount'=>null,
        ]);

        $this->approve($proof, $plan)
            ->assertRedirect('/admin/payment-proofs')
            ->assertSessionHasErrors('verified_received_amount');
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());

        $this->approve($proof, $plan, ['verified_received_amount'=>11000])
            ->assertRedirect('/admin/payment-proofs')->assertSessionHas('success');
        $this->assertSame(11000.0, (float)$proof->fresh()->distributor_net_amount);
        $this->assertSame(
            11000.0,
            (float)AgentCommission::where('payment_proof_id', $proof->id)->firstOrFail()->base_amount
        );
    }
}