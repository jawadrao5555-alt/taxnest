<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributorAdminFinancialControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Root',
            'email' => 'financial-controls@example.test',
            'password' => 'password',
            'role' => 'super_admin',
        ]);
    }

    private function agent(): Agent
    {
        return Agent::create([
            'name' => 'Distributor',
            'email' => 'distributor-financial@example.test',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function earnedCommission(Agent $agent, float $amount = 100): AgentCommission
    {
        $companySequence = Company::count() + 1;
        $company = Company::create([
            'name' => 'Commission Customer',
            'ntn' => sprintf('%07d-%d', 9000000 + $companySequence, ($companySequence % 9) + 1),
            'product_type' => 'di',
            'agent_id' => $agent->id,
        ]);
        $proof = PaymentProof::create([
            'company_id' => $company->id,
            'amount' => 1000,
            'proof_path' => 'proof.jpg',
            'billing_cycle' => 'annual',
            'status' => 'verified',
            'verified_at' => '2027-02-15 12:00:00',
        ]);

        return AgentCommission::create([
            'agent_id' => $agent->id,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'payment_proof_id' => $proof->id,
            'type' => 'new',
            'commission_year' => 1,
            'base_amount' => 1000,
            'rate_percent' => 10,
            'amount' => $amount,
            'period_month' => '2027-02-01',
            'description' => 'Annual commission',
        ]);
    }

    public function test_sequential_and_oversize_clawbacks_are_capped_inside_the_transaction(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();
        $earn = $this->earnedCommission($agent);

        $this->actingAs($admin, 'admin')
            ->from("/admin/agents/{$agent->id}")
            ->post(route('saas.admin.agents.clawback', $agent), [
                'commission_id' => $earn->id,
                'amount' => 60,
                'reason' => 'Partial refund',
            ])
            ->assertRedirect(route('saas.admin.agents.show', ['id' => $agent->id, 'month' => now()->format('Y-m')]));

        $this->actingAs($admin, 'admin')
            ->from("/admin/agents/{$agent->id}")
            ->post(route('saas.admin.agents.clawback', $agent), [
                'commission_id' => $earn->id,
                'amount' => 1000,
                'reason' => 'Remaining refund',
            ])
            ->assertSessionHas('success', 'Clawback line recorded — commission adjusted by Rs 40.00.');

        $this->assertSame(-100.0, (float) AgentCommission::where('agent_id', $agent->id)
            ->where('type', 'clawback')->sum('amount'));
        $this->assertSame(0.0, (float) AgentCommission::where('agent_id', $agent->id)
            ->where('payment_proof_id', $earn->payment_proof_id)->sum('amount'));
        $this->assertSame(2, AdminAuditLog::where('action', 'Agent commission clawback')->count());

        $this->actingAs($admin, 'admin')
            ->from("/admin/agents/{$agent->id}")
            ->post(route('saas.admin.agents.clawback', $agent), [
                'commission_id' => $earn->id,
                'reason' => 'Duplicate refund',
            ])
            ->assertSessionHas('error', 'This commission line is already fully clawed back.');

        $this->assertSame(2, AgentCommission::where('agent_id', $agent->id)->where('type', 'clawback')->count());
    }

    public function test_repeated_incentive_creation_only_audits_the_new_award_once(): void
    {
        Carbon::setTestNow('2027-05-02 12:00:00');
        $admin = $this->admin();
        $agent = $this->agent();
        foreach (range(1, 3) as $number) {
            $this->earnedCommission($agent, 100 + $number);
        }

        $this->actingAs($admin, 'admin')
            ->from("/admin/agents/{$agent->id}")
            ->post(route('saas.admin.agents.incentives.store', $agent), ['quarter' => '2027-Q1'])
            ->assertSessionHas('success', 'Immutable quarterly incentive award created for approval.');

        $this->actingAs($admin, 'admin')
            ->from("/admin/agents/{$agent->id}")
            ->post(route('saas.admin.agents.incentives.store', $agent), ['quarter' => '2027-Q1'])
            ->assertSessionHas('error', 'This quarterly incentive award already exists (status: pending).')
            ->assertSessionMissing('success');

        $this->assertSame(1, \App\Models\AgentIncentiveAward::where('agent_id', $agent->id)
            ->where('quarter', '2027-Q1')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'Distributor incentive awarded')->count());
    }
}