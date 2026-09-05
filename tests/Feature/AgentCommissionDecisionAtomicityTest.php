<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Services\AgentCommissionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCommissionDecisionAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_nullable_decision_key_allows_one_decision_and_multiple_clawbacks(): void
    {
        $this->assertTrue(Schema::hasColumn('agent_commissions', 'decision_key'));
        $agent = Agent::create([
            'name'=>'Atomic Distributor', 'email'=>'atomic@example.test',
            'status'=>'active', 'is_active'=>true,
        ]);
        $company = Company::create([
            'name'=>'Atomic Shop', 'ntn'=>'ATOMIC-1', 'product_type'=>'di', 'agent_id'=>$agent->id,
        ]);
        $proof = PaymentProof::create([
            'company_id'=>$company->id, 'amount'=>10000, 'distributor_net_amount'=>9000,
            'billing_cycle'=>'annual', 'proof_path'=>'payment-proofs/atomic.pdf',
            'status'=>'verified', 'verified_at'=>now(),
        ]);

        AgentCommissionService::recordForProof($proof);
        AgentCommissionService::recordForProof($proof);
        AgentCommissionService::syncForAgent($agent->fresh());

        $decision = AgentCommission::where('payment_proof_id', $proof->id)
            ->whereIn('type', ['new','renewal','skipped'])->firstOrFail();
        $this->assertSame('proof:'.$proof->id, $decision->decision_key);
        $this->assertSame(1, AgentCommission::where('decision_key', 'proof:'.$proof->id)->count());

        try {
            AgentCommission::create([
                'agent_id'=>$agent->id, 'company_id'=>$company->id, 'payment_proof_id'=>$proof->id,
                'decision_key'=>'proof:'.$proof->id, 'type'=>'renewal', 'base_amount'=>9000,
                'rate_percent'=>10, 'amount'=>900, 'period_month'=>now()->startOfMonth(),
                'description'=>'duplicate decision',
            ]);
            $this->fail('The database unique constraint accepted a duplicate proof decision.');
        } catch (QueryException) {
            $this->assertSame(1, AgentCommission::where('decision_key', 'proof:'.$proof->id)->count());
        }

        foreach ([400, 500] as $amount) {
            AgentCommission::create([
                'agent_id'=>$agent->id, 'company_id'=>$company->id, 'payment_proof_id'=>$proof->id,
                'decision_key'=>null, 'type'=>'clawback', 'base_amount'=>9000,
                'rate_percent'=>15, 'amount'=>-$amount, 'period_month'=>now()->startOfMonth(),
                'description'=>'partial refund',
            ]);
        }
        $this->assertSame(2, AgentCommission::where('payment_proof_id', $proof->id)
            ->where('type', 'clawback')->whereNull('decision_key')->count());

        $agent->update(['status'=>'terminated', 'terminated_at'=>now()->subDay()]);
        $secondCompany = Company::create([
            'name'=>'Skipped Shop', 'ntn'=>'ATOMIC-2', 'product_type'=>'di', 'agent_id'=>$agent->id,
        ]);
        $skippedProof = PaymentProof::create([
            'company_id'=>$secondCompany->id, 'amount'=>10000, 'billing_cycle'=>'annual',
            'proof_path'=>'payment-proofs/skipped.pdf', 'status'=>'verified', 'verified_at'=>now(),
        ]);
        AgentCommissionService::recordForProof($skippedProof);
        AgentCommissionService::recordForProof($skippedProof);
        $skip = AgentCommission::where('payment_proof_id', $skippedProof->id)->firstOrFail();
        $this->assertSame('skipped', $skip->type);
        $this->assertSame('proof:'.$skippedProof->id, $skip->decision_key);
        $this->assertSame(1, AgentCommission::where('decision_key', 'proof:'.$skippedProof->id)->count());
    }
}