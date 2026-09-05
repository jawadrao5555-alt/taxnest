<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Services\DistributorPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Focused public-boundary tests. Detailed payment-proof/admin transition tests
 * belong beside their existing controller suites; these keep referral semantics
 * and policy defaults from regressing.
 */
class DistributorReferralProgrammeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_signup_pages_expose_a_separate_optional_distributor_field(): void
    {
        foreach (['/register', '/pos/register', '/fbr-pos/register'] as $url) {
            $this->get($url)->assertOk()->assertSee('distributor_reference_code', false);
        }
    }

    public function test_di_valid_distributor_code_attributes_and_blank_is_direct(): void
    {
        $agent = Agent::create(['name'=>'Distributor','email'=>'d@example.test','status'=>'active','is_active'=>true]);
        $base = ['name'=>'Owner','password'=>'password','password_confirmation'=>'password'];
        $this->post('/register', $base + ['email'=>'a@example.test','company_name'=>'A','company_ntn'=>'10001','distributor_reference_code'=>$agent->referral_code])
            ->assertRedirect('/login');
        $this->assertSame($agent->id, (int) Company::where('ntn','10001')->value('agent_id'));
        $this->post('/register', $base + ['email'=>'b@example.test','company_name'=>'B','company_ntn'=>'10002','distributor_reference_code'=>''])
            ->assertRedirect('/login');
        $this->assertNull(Company::where('ntn','10002')->value('agent_id'));
    }

    public function test_invalid_or_inactive_distributor_code_is_never_direct(): void
    {
        $payload=['name'=>'Owner','email'=>'bad@example.test','password'=>'password','password_confirmation'=>'password','company_name'=>'Bad','company_ntn'=>'10003','distributor_reference_code'=>'AG-NOTACTIVE'];
        $this->from('/register')->post('/register',$payload)->assertSessionHasErrors('distributor_reference_code');
        $this->assertDatabaseMissing('companies',['ntn'=>'10003']);
    }

    public function test_pos_registration_posts_apply_valid_codes_and_leave_blank_codes_direct(): void
    {
        $agent = Agent::create(['name'=>'POS Distributor','email'=>'pos-d@example.test','status'=>'active','is_active'=>true]);
        $plan = PricingPlan::create([
            'name' => 'Starter', 'product_type' => 'pos', 'price' => 10000,
            'price_yearly' => 10000, 'is_trial' => false, 'invoice_limit' => -1,
        ]);
        $payload = fn (string $suffix, string $ntn) => [
            'company_name' => 'POS '.$suffix, 'company_ntn' => $ntn,
            'name' => 'Owner '.$suffix, 'email' => "pos-{$suffix}@example.test",
            'password' => 'password', 'password_confirmation' => 'password',
            'pos_type' => 'restaurant', 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
        ];

        $this->post('/pos/register', $payload('valid', 'POS-REF-1') + ['distributor_reference_code' => $agent->referral_code])
            ->assertRedirect('/pos/invoice/create');
        $this->assertSame($agent->id, (int) Company::where('ntn', 'POS-REF-1')->value('agent_id'));

        $this->post('/pos/register', $payload('blank', 'POS-REF-2') + ['distributor_reference_code' => ''])
            ->assertRedirect('/pos/invoice/create');
        $this->assertNull(Company::where('ntn', 'POS-REF-2')->value('agent_id'));

        $this->from('/pos/register')->post('/pos/register', $payload('invalid', 'POS-REF-3') + ['distributor_reference_code' => 'NO-SUCH-D'])
            ->assertSessionHasErrors('distributor_reference_code');
        $this->assertDatabaseMissing('companies', ['ntn' => 'POS-REF-3']);
    }

    public function test_fbr_registration_posts_apply_valid_codes_and_leave_blank_codes_direct(): void
    {
        $agent = Agent::create(['name'=>'FBR Distributor','email'=>'fbr-d@example.test','status'=>'active','is_active'=>true]);
        $payload = fn (string $suffix, string $ntn) => [
            'company_name' => 'FBR '.$suffix, 'company_ntn' => $ntn,
            'name' => 'Owner '.$suffix, 'email' => "fbr-{$suffix}@example.test",
            'password' => 'password', 'password_confirmation' => 'password', 'pos_type' => 'restaurant',
        ];

        $this->post('/fbr-pos/register', $payload('valid', 'FBR-REF-1') + ['distributor_reference_code' => $agent->referral_code])
            ->assertRedirect('/fbr-pos/create');
        $this->assertSame($agent->id, (int) Company::where('ntn', 'FBR-REF-1')->value('agent_id'));

        $this->post('/fbr-pos/register', $payload('blank', 'FBR-REF-2') + ['distributor_reference_code' => ''])
            ->assertRedirect('/fbr-pos/create');
        $this->assertNull(Company::where('ntn', 'FBR-REF-2')->value('agent_id'));

        $this->from('/fbr-pos/register')->post('/fbr-pos/register', $payload('invalid', 'FBR-REF-3') + ['distributor_reference_code' => 'NO-SUCH-D'])
            ->assertSessionHasErrors('distributor_reference_code');
        $this->assertDatabaseMissing('companies', ['ntn' => 'FBR-REF-3']);
    }

    public function test_distributor_portal_is_not_available(): void
    {
        $this->get('/agent/login')->assertNotFound();
        $this->get('/agent/dashboard')->assertNotFound();
    }

    public function test_policy_defaults_and_discount_cap_are_stable(): void
    {
        $p=DistributorPolicyService::policy();
        $this->assertSame(15.0,(float)$p['year1']);
        $this->assertSame(10.0,(float)$p['max_discount']);
        $this->assertSame(15.0,(float)$p['hold_days']);
        $this->assertLessThanOrEqual(20.0, $p['year1'] + 5);
    }
}