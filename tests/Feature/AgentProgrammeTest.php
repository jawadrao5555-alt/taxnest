<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AgentSaleClaim;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentProgrammeTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $name, string $email): Agent
    {
        return Agent::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'is_active' => true,
            'status' => 'active',
            'rate_new' => 10,
            'rate_renewal' => 5,
        ]);
    }

    private function signup(string $email, string $ntn)
    {
        return $this->post('/register', [
            'name' => 'Owner',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
            'company_name' => 'Shop ' . $ntn,
            'company_ntn' => $ntn,
        ]);
    }

    public function test_agent_referral_attribution_is_first_touch_and_immutable_across_signup_flow(): void
    {
        $first = $this->agent('First Agent', 'first-agent@example.com');
        $second = $this->agent('Second Agent', 'second-agent@example.com');

        $this->get('/register?ref=' . $first->referral_code)->assertOk();
        $this->get('/register?ref=' . $second->referral_code)->assertOk();
        $this->signup('buyer@example.com', 'NTN-100')->assertRedirect('/login');

        $this->assertDatabaseHas('companies', [
            'email' => 'buyer@example.com',
            'agent_id' => $first->id,
        ]);
    }

    public function test_unknown_or_inactive_agent_referral_code_is_silently_ignored(): void
    {
        $inactive = $this->agent('Inactive', 'inactive-agent@example.com');
        $inactive->update(['is_active' => false]);

        $this->get('/register?ref=UNKNOWN')->assertOk();
        $this->get('/register?ref=' . $inactive->referral_code)->assertOk();
        $this->signup('normal@example.com', 'NTN-200')->assertRedirect('/login');

        $this->assertDatabaseHas('companies', [
            'email' => 'normal@example.com',
            'agent_id' => null,
        ]);
    }

    public function test_claim_approval_assigns_only_an_unowned_company_and_never_steals(): void
    {
        $agent = $this->agent('Claimant', 'claimant@example.com');
        $other = $this->agent('Owner Agent', 'owner-agent@example.com');
        $free = Company::create(['name' => 'Free Shop', 'ntn' => 'FREE-NTN', 'email' => 'free@example.com']);
        $owned = Company::create(['name' => 'Owned Shop', 'ntn' => 'OWNED-NTN', 'agent_id' => $other->id]);
        $admin = AdminUser::create([
            'name' => 'Super', 'email' => 'super-agent-test@example.com',
            'password' => 'password123', 'role' => 'super_admin',
        ]);

        $approve = AgentSaleClaim::create([
            'agent_id' => $agent->id, 'identifier_type' => 'ntn',
            'identifier' => 'FREE-NTN', 'status' => 'pending',
        ]);
        $steal = AgentSaleClaim::create([
            'agent_id' => $agent->id, 'identifier_type' => 'ntn',
            'identifier' => 'OWNED-NTN', 'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')->post(route('saas.admin.agent-claims.review', $approve), [
            'decision' => 'approve',
        ])->assertSessionHas('success');
        $this->actingAs($admin, 'admin')->post(route('saas.admin.agent-claims.review', $steal), [
            'decision' => 'approve',
        ])->assertSessionHas('error');

        $this->assertSame($agent->id, $free->fresh()->agent_id);
        $this->assertSame('approved', $approve->fresh()->status);
        $this->assertSame($other->id, $owned->fresh()->agent_id);
        $this->assertSame('pending', $steal->fresh()->status);
    }

    public function test_agent_portal_isolates_companies_and_commissions_by_authenticated_agent(): void
    {
        $this->get('/agent/dashboard')->assertRedirect('/agent/login');

        $agent = $this->agent('Portal Agent', 'portal-agent@example.com');
        $other = $this->agent('Other Agent', 'other-agent@example.com');
        $mine = Company::create(['name' => 'Visible Shop', 'ntn' => 'VISIBLE-NTN', 'agent_id' => $agent->id]);
        $theirs = Company::create(['name' => 'Hidden Shop', 'ntn' => 'HIDDEN-NTN', 'agent_id' => $other->id]);

        AgentCommission::create([
            'agent_id' => $agent->id, 'company_id' => $mine->id, 'company_name' => $mine->name,
            'type' => 'new', 'base_amount' => 1000, 'rate_percent' => 10, 'amount' => 100,
            'period_month' => now()->startOfMonth(), 'description' => 'Visible commission',
        ]);
        AgentCommission::create([
            'agent_id' => $other->id, 'company_id' => $theirs->id, 'company_name' => $theirs->name,
            'type' => 'new', 'base_amount' => 1000, 'rate_percent' => 10, 'amount' => 100,
            'period_month' => now()->startOfMonth(), 'description' => 'Hidden commission',
        ]);

        $this->actingAs($agent, 'agent')->get('/agent/companies')
            ->assertOk()->assertSee('Visible Shop')->assertDontSee('Hidden Shop');
        // The ledger names the company each line was earned on, so isolation is
        // asserted on the company names the view actually prints.
        $this->actingAs($agent, 'agent')->get('/agent/commissions')
            ->assertOk()->assertSee('Visible Shop')->assertDontSee('Hidden Shop');
    }
}