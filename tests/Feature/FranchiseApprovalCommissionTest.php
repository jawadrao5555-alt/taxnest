<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\FranchiseCommission;
use App\Models\PaymentProof;
use App\Services\FranchiseCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FranchiseApprovalCommissionTest extends TestCase
{
    use RefreshDatabase;

    private function franchise(string $email, float $rate = 10): Franchise
    {
        return Franchise::create(['name' => $email, 'email' => $email,
            'password' => 'Passw0rd!2026', 'status' => 'active', 'commission_rate' => $rate]);
    }

    private function company(Franchise $franchise, string $name = 'Shop'): Company
    {
        return Company::create(['name' => $name, 'ntn' => $name, 'product_type' => 'pos',
            'status' => 'pending', 'company_status' => 'pending', 'franchise_id' => $franchise->id]);
    }

    public function test_franchise_review_is_scoped_and_never_activates_company_or_package(): void
    {
        $own = $this->franchise('own@example.test');
        $other = $this->franchise('other@example.test');
        $ownCompany = $this->company($own, 'Own shop');
        $otherCompany = $this->company($other, 'Other shop');

        $foreign = $this->actingAs($own, 'franchise')->post(route('franchise.companies.approve', $otherCompany->id));
        $this->assertContains($foreign->getStatusCode(), [302, 404]);
        $this->assertDatabaseMissing('franchise_company_approvals', ['company_id' => $otherCompany->id]);
        $this->actingAs($own, 'franchise')->post(route('franchise.companies.approve', $ownCompany->id))->assertRedirect();
        $this->assertDatabaseHas('franchise_company_approvals', ['company_id' => $ownCompany->id, 'franchise_id' => $own->id]);
        $this->assertSame('pending', $ownCompany->fresh()->status);
        $this->assertSame('pending', $ownCompany->fresh()->company_status);
        $this->actingAs($own, 'franchise')->post(route('franchise.companies.approve', $ownCompany->id))->assertRedirect();
        $this->assertSame(1, DB::table('franchise_company_approvals')->where('company_id', $ownCompany->id)->count());
    }

    public function test_verified_package_payment_freezes_rate_and_attribution_without_pos_sales(): void
    {
        $franchise = $this->franchise('partner@example.test', 12.5);
        $company = $this->company($franchise);
        $proof = PaymentProof::create(['company_id' => $company->id, 'amount' => 19999,
            'proof_path' => 'test-only', 'status' => 'verified', 'request_type' => 'subscription',
            'verified_at' => now(), 'franchise_id_at_verification' => $franchise->id,
            'franchise_rate_at_verification' => 12.5]);
        FranchiseCommissionService::recordForProof($proof);
        FranchiseCommissionService::recordForProof($proof);
        $line = FranchiseCommission::where('payment_proof_id', $proof->id)->sole();
        $this->assertSame('2499.88', $line->amount);
        $franchise->update(['commission_rate' => 30]);
        $company->update(['franchise_id' => null]);
        FranchiseCommissionService::recordForProof($proof->fresh());
        $this->assertSame('2499.88', $line->fresh()->amount);
        $this->assertSame(1, FranchiseCommission::where('payment_proof_id', $proof->id)->count());

        // An old proof with no frozen franchise snapshot cannot be awarded
        // using today's company assignment or today's rate.
        $old = PaymentProof::create(['company_id' => $company->id, 'amount' => 19999,
            'proof_path' => 'old', 'status' => 'verified', 'request_type' => 'subscription']);
        $this->assertSame(0, FranchiseCommissionService::reconcile($franchise->id));
        $this->assertDatabaseMissing('franchise_commissions', ['payment_proof_id' => $old->id]);

        $addon = PaymentProof::create(['company_id' => $company->id, 'amount' => 10000,
            'proof_path' => 'addon', 'status' => 'verified', 'request_type' => 'extra_branch',
            'franchise_id_at_verification' => $franchise->id, 'franchise_rate_at_verification' => 12.5]);
        FranchiseCommissionService::recordForProof($addon);
        $this->assertSame(1, FranchiseCommission::count());
    }

    public function test_dual_agent_attribution_cannot_create_franchise_payable(): void
    {
        $franchise = $this->franchise('conflict@example.test');
        $company = $this->company($franchise);
        $proof = PaymentProof::create(['company_id' => $company->id, 'amount' => 29999,
            'proof_path' => 'test-only', 'status' => 'verified', 'request_type' => 'subscription',
            'franchise_id_at_verification' => $franchise->id, 'franchise_rate_at_verification' => 10,
            'franchise_attribution_conflict' => true]);
        FranchiseCommissionService::recordForProof($proof);
        $this->assertDatabaseHas('franchise_commissions', [
            'payment_proof_id' => $proof->id, 'amount' => 0, 'status' => 'attribution_conflict',
        ]);
    }

    public function test_admin_payout_nets_refund_adjustments_and_is_idempotent(): void
    {
        $franchise = $this->franchise('pay@example.test');
        $admin = AdminUser::create(['name' => 'Owner', 'email' => 'pay-admin@example.test',
            'password' => 'Passw0rd!2026', 'role' => 'super_admin']);
        $line = FranchiseCommission::create(['franchise_id' => $franchise->id, 'company_name' => 'Shop',
            'type' => 'earned', 'base_amount' => 10000, 'rate_percent' => 10,
            'amount' => 1000, 'status' => 'pending', 'earned_at' => now()]);
        $this->actingAs($admin, 'admin')->post(route('saas.admin.franchises.adjust', [$franchise->id, $line->id]),
            ['amount' => 300, 'reason' => 'Partial refund'])->assertRedirect();
        $this->actingAs($admin, 'admin')->post(route('saas.admin.franchises.paid', $franchise->id),
            ['reference' => 'BANK-TEST-001'])->assertRedirect();
        $this->assertDatabaseHas('franchise_payouts', ['franchise_id' => $franchise->id,
            'amount' => 700, 'reference' => 'BANK-TEST-001']);
        $this->actingAs($admin, 'admin')->post(route('saas.admin.franchises.paid', $franchise->id),
            ['reference' => 'BANK-TEST-001'])->assertRedirect();
        $this->assertSame(1, DB::table('franchise_payouts')->count());
    }

    public function test_franchise_cannot_access_admin_payout_and_admin_cannot_adjust_another_franchises_line(): void
    {
        $own = $this->franchise('partner-a@example.test');
        $other = $this->franchise('partner-b@example.test');
        $line = FranchiseCommission::create(['franchise_id' => $other->id, 'company_name' => 'Other shop',
            'type' => 'earned', 'base_amount' => 10000, 'rate_percent' => 10,
            'amount' => 1000, 'status' => 'pending', 'earned_at' => now()]);
        $this->actingAs($own, 'franchise')
            ->post(route('saas.admin.franchises.paid', $other->id), ['reference' => 'FAKE'])
            ->assertRedirect();
        $admin = AdminUser::create(['name' => 'Owner', 'email' => 'owner-a@example.test',
            'password' => 'Passw0rd!2026', 'role' => 'super_admin']);
        $this->actingAs($admin, 'admin')
            ->post(route('saas.admin.franchises.adjust', [$own->id, $line->id]),
                ['amount' => 10, 'reason' => 'Wrong attribution'])
            ->assertRedirect();
        $this->assertSame(1, FranchiseCommission::count());
    }
}
