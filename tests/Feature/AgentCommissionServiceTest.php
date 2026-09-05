<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Services\AgentCommissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Agent commission money-math invariants:
 * - first cleared payment = 'new' rate, later ones = 'renewal' rate
 * - one decision line per proof (no double earn)
 * - payments cleared while the agent was terminated NEVER earn commission,
 *   even after reactivation + backfill (persisted 'skipped' marker)
 * - clawback capped at the original line's remaining amount (controller rule
 *   mirrored here at ledger level via signed rows)
 */
class AgentCommissionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('agent_commissions');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->string('billing_cycle')->nullable();
            $table->decimal('distributor_net_amount', 12, 2)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate_new', 5, 2)->default(0);
            $table->decimal('rate_renewal', 5, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('reactivated_at')->nullable();
            $table->text('termination_windows')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->unsignedBigInteger('payment_proof_id')->nullable();
            $table->string('type', 20);
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->date('period_month');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedTinyInteger('commission_year')->nullable();
            $table->timestamp('hold_until')->nullable();
            $table->timestamps();
        });
    }

    private function makeAgentAndCompany(): array
    {
        $agent = Agent::create(['name' => 'A One', 'rate_new' => 15, 'rate_renewal' => 7.5, 'status' => 'active']);
        $company = Company::create(['name' => 'Shop', 'agent_id' => $agent->id]);

        return [$agent, $company];
    }

    private function verifiedProof(Company $company, float $amount, string $verifiedAt): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $company->id,
            'amount' => $amount,
            'status' => 'verified',
            'verified_at' => $verifiedAt,
        ]);
    }

    public function test_first_proof_earns_new_rate_and_second_earns_renewal_rate(): void
    {
        [, $company] = $this->makeAgentAndCompany();

        AgentCommissionService::recordForProof($this->verifiedProof($company, 24000, '2026-08-02 10:00:00'));
        AgentCommissionService::recordForProof($this->verifiedProof($company, 12000, '2026-08-06 10:00:00'));

        $lines = AgentCommission::orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('new', $lines[0]->type);
        $this->assertSame(3600.00, (float) $lines[0]->amount);
        $this->assertSame('renewal', $lines[1]->type);
        $this->assertSame(900.00, (float) $lines[1]->amount);
    }

    public function test_duplicate_proof_never_earns_twice(): void
    {
        [$agent, $company] = $this->makeAgentAndCompany();
        $proof = $this->verifiedProof($company, 10000, '2026-08-02 10:00:00');

        AgentCommissionService::recordForProof($proof);
        AgentCommissionService::recordForProof($proof);
        AgentCommissionService::syncForAgent($agent->fresh());

        $this->assertSame(1, AgentCommission::where('payment_proof_id', $proof->id)->count());
    }

    public function test_terminated_period_payment_earns_nothing_even_after_reactivation_backfill(): void
    {
        [$agent, $company] = $this->makeAgentAndCompany();

        // Terminate, payment clears during the window → persisted skip.
        $agent->update(['status' => 'terminated', 'terminated_at' => '2026-08-01 00:00:00', 'reactivated_at' => null]);
        $proof = $this->verifiedProof($company, 50000, '2026-08-05 10:00:00');
        AgentCommissionService::recordForProof($proof);

        $skip = AgentCommission::where('payment_proof_id', $proof->id)->first();
        $this->assertNotNull($skip);
        $this->assertSame('skipped', $skip->type);
        $this->assertSame(0.00, (float) $skip->amount);

        // Reactivate + backfill → still no earn line.
        $agent->update(['status' => 'active', 'reactivated_at' => '2026-08-10 00:00:00']);
        AgentCommissionService::syncForAgent($agent->fresh());

        $this->assertSame(0, AgentCommission::where('payment_proof_id', $proof->id)->whereIn('type', ['new', 'renewal'])->count());
        $this->assertSame(0.0, (float) AgentCommission::sum('amount'));

        // Payment cleared AFTER reactivation earns normally.
        $after = $this->verifiedProof($company, 10000, '2026-08-12 10:00:00');
        AgentCommissionService::recordForProof($after);
        $this->assertSame(1, AgentCommission::where('payment_proof_id', $after->id)->whereIn('type', ['new', 'renewal'])->count());
    }

    public function test_backfill_skips_terminated_window_but_awards_pre_termination_proofs(): void
    {
        [$agent, $company] = $this->makeAgentAndCompany();

        $before = $this->verifiedProof($company, 20000, '2026-07-15 10:00:00'); // active period, never recorded
        $during = $this->verifiedProof($company, 30000, '2026-08-05 10:00:00'); // terminated window, never recorded

        $agent->update(['status' => 'active', 'terminated_at' => '2026-08-01 00:00:00', 'reactivated_at' => '2026-08-10 00:00:00']);
        AgentCommissionService::syncForAgent($agent->fresh());

        $this->assertSame('new', AgentCommission::where('payment_proof_id', $before->id)->value('type'));
        $this->assertSame('skipped', AgentCommission::where('payment_proof_id', $during->id)->value('type'));
    }

    public function test_earlier_termination_window_survives_later_cycles_and_late_company_link(): void
    {
        // Reviewer regression: terminate → payment clears while company UNLINKED
        // → reactivate → terminate/reactivate AGAIN → link company → backfill.
        // The payment from the FIRST terminated window must stay 'skipped'.
        $agent = Agent::create(['name' => 'A One', 'rate_new' => 15, 'rate_renewal' => 7.5, 'status' => 'active']);
        $company = Company::create(['name' => 'Late Shop']); // not linked yet

        $windows = [['from' => '2026-08-01 00:00:00', 'to' => null]];
        $agent->update(['status' => 'terminated', 'terminated_at' => '2026-08-01 00:00:00', 'termination_windows' => $windows]);

        $duringFirstWindow = $this->verifiedProof($company, 40000, '2026-08-05 10:00:00');

        // Reactivate (window 1 closes), then a second full cycle.
        $windows[0]['to'] = '2026-08-10 00:00:00';
        $agent->update(['status' => 'active', 'reactivated_at' => '2026-08-10 00:00:00', 'termination_windows' => $windows]);
        $windows[] = ['from' => '2026-09-01 00:00:00', 'to' => '2026-09-05 00:00:00'];
        $agent->update(['terminated_at' => '2026-09-01 00:00:00', 'reactivated_at' => '2026-09-05 00:00:00', 'termination_windows' => $windows]);

        // NOW link the company and backfill.
        $company->update(['agent_id' => $agent->id]);
        AgentCommissionService::syncForAgent($agent->fresh());

        $this->assertSame('skipped', AgentCommission::where('payment_proof_id', $duringFirstWindow->id)->value('type'));
        $this->assertSame(0.0, (float) AgentCommission::sum('amount'));

        // A proof cleared between the two windows earns normally on backfill.
        $between = $this->verifiedProof($company, 10000, '2026-08-20 10:00:00');
        AgentCommissionService::syncForAgent($agent->fresh());
        $this->assertContains(AgentCommission::where('payment_proof_id', $between->id)->value('type'), ['new', 'renewal']);
    }

    public function test_clawback_ledger_nets_to_zero_at_cap(): void
    {
        [$agent, $company] = $this->makeAgentAndCompany();
        $proof = $this->verifiedProof($company, 24000, '2026-08-02 10:00:00');
        AgentCommissionService::recordForProof($proof);

        $earn = AgentCommission::where('payment_proof_id', $proof->id)->first();

        // Full clawback (the controller caps at remaining = earn amount).
        AgentCommission::create([
            'agent_id' => $agent->id,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'payment_proof_id' => $proof->id,
            'type' => 'clawback',
            'base_amount' => $earn->base_amount,
            'rate_percent' => $earn->rate_percent,
            'amount' => -1 * (float) $earn->amount,
            'period_month' => '2026-08-01',
            'description' => 'Refund',
        ]);

        $this->assertSame(0.0, (float) AgentCommission::where('payment_proof_id', $proof->id)->sum('amount'));

        // Controller-side remaining computation would now be 0 → further clawback rejected.
        $alreadyClawed = (float) AgentCommission::where('type', 'clawback')->where('payment_proof_id', $proof->id)->sum('amount');
        $this->assertSame(0.0, round((float) $earn->amount + $alreadyClawed, 2));
    }

    public function test_explicit_annual_proofs_use_global_three_year_rates_and_trusted_net_base(): void
    {
        [, $company] = $this->makeAgentAndCompany();
        foreach ([['2026-01-01', 9000], ['2027-01-01', 8000], ['2028-01-01', 7000], ['2029-01-01', 6000]] as [$at, $net]) {
            $proof = PaymentProof::create([
                'company_id' => $company->id, 'amount' => 99999, 'distributor_net_amount' => $net,
                'billing_cycle' => 'annual', 'status' => 'verified', 'verified_at' => $at,
            ]);
            AgentCommissionService::recordForProof($proof);
        }

        $lines = AgentCommission::orderBy('id')->get();
        $this->assertSame([1,2,3,4], $lines->pluck('commission_year')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([15.0,10.0,5.0,0.0], $lines->pluck('rate_percent')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([1350.0,800.0,350.0,0.0], $lines->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(9000.0, (float) $lines->first()->base_amount);
        $this->assertSame('skipped', $lines->last()->type);
    }
}
