<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Services\DistributorIncentiveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributorIncentiveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function qualifyingLines(Agent $agent, int $count, string $verifiedAt = '2027-02-15 12:00:00'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $company = Company::create([
                'name' => "Quarterly {$agent->id}-{$i}", 'ntn' => "Q-{$agent->id}-{$i}",
                'product_type' => 'di', 'agent_id' => $agent->id,
            ]);
            $proof = PaymentProof::create([
                'company_id' => $company->id, 'amount' => 10000, 'distributor_net_amount' => 9000,
                'proof_path' => 'test-proof.jpg',
                'billing_cycle' => 'annual', 'status' => 'verified', 'verified_at' => $verifiedAt,
            ]);
            AgentCommission::create([
                'agent_id' => $agent->id, 'company_id' => $company->id, 'company_name' => $company->name,
                'payment_proof_id' => $proof->id, 'type' => 'new', 'commission_year' => 1,
                'base_amount' => 9000, 'rate_percent' => 15, 'amount' => 1350,
                'period_month' => '2027-02-01', 'description' => 'annual',
            ]);
        }
    }

    public function test_awards_use_verified_payment_quarter_and_enforce_the_thirty_day_freeze(): void
    {
        $agent = Agent::create(['name'=>'Q','email'=>'q@example.test','status'=>'active','is_active'=>true]);
        $this->qualifyingLines($agent, 3);

        Carbon::setTestNow('2027-04-30 23:59:59');
        $this->assertNull(DistributorIncentiveService::award($agent, '2027-Q1'));

        Carbon::setTestNow('2027-05-01 00:00:00');
        $award = DistributorIncentiveService::award($agent, '2027-Q1');
        $this->assertNotNull($award);
        $this->assertSame(3, $award->qualified_companies);
        $this->assertSame(2.0, (float) $award->rate_percent);
        $this->assertSame(540.0, (float) $award->amount);
        $this->assertSame($award->id, DistributorIncentiveService::award($agent, '2027-Q1')->id);
        $this->assertSame(1, \App\Models\AgentIncentiveAward::where('agent_id', $agent->id)->where('quarter', '2027-Q1')->count());
    }

    public function test_exact_three_six_and_ten_company_tiers_and_clawbacks_are_respected(): void
    {
        Carbon::setTestNow('2027-05-02');
        foreach ([[3,2.0], [6,3.0], [10,5.0]] as [$count, $rate]) {
            $agent = Agent::create(['name'=>"T{$count}",'email'=>"tier{$count}@example.test",'status'=>'active','is_active'=>true]);
            $this->qualifyingLines($agent, $count);
            $award = DistributorIncentiveService::award($agent, '2027-Q1');
            $this->assertSame($count, $award->qualified_companies);
            $this->assertSame($rate, (float) $award->rate_percent);
        }

        $agent = Agent::create(['name'=>'Refund','email'=>'refund@example.test','status'=>'active','is_active'=>true]);
        $this->qualifyingLines($agent, 3);
        $original = AgentCommission::where('agent_id', $agent->id)->first();
        AgentCommission::create([
            'agent_id'=>$agent->id, 'company_id'=>$original->company_id, 'payment_proof_id'=>$original->payment_proof_id,
            'type'=>'clawback', 'base_amount'=>9000, 'rate_percent'=>15, 'amount'=>-1350,
            'period_month'=>'2027-03-01', 'description'=>'refund',
        ]);
        $this->assertNull(DistributorIncentiveService::award($agent, '2027-Q1'));
    }
}