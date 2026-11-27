<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentProofCompanyIdFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_and_legacy_company_identifiers_filter_only_the_matching_proofs(): void
    {
        $admin = AdminUser::create(['name' => 'Synthetic Payment Reviewer', 'email' => 'proof-ids@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin']);
        $company = Company::create(['name' => 'Synthetic Proof Company', 'ntn' => 'PROOF-ID-1', 'product_type' => 'pos']);
        $previousCode = $company->product_company_id;
        $company->update(['product_type' => 'fbrpos']);
        $other = Company::create(['name' => 'Other Proof Company', 'ntn' => 'PROOF-ID-2', 'product_type' => 'pos']);
        $historicalId = 987654;

        foreach ([$company->id => 'Owned reference', $other->id => 'Other reference', $historicalId => 'Historical reference'] as $id => $reference) {
            PaymentProof::create(['company_id' => $id, 'reference' => $reference, 'proof_path' => 'synthetic/fixture.png', 'status' => 'verified']);
        }

        $this->actingAs($admin, 'admin');
        foreach ([strtolower($company->product_company_id), $previousCode, $company->account_code, $company->id] as $identifier) {
            $this->get(route('saas.admin.payment-proofs', ['company_id' => $identifier, 'status' => 'all']))
                ->assertOk()->assertSee($company->product_company_id)->assertSee('Owned reference')
                ->assertDontSee('Other reference')->assertDontSee('Historical reference')
                ->assertSee(route('saas.admin.payment-proofs', ['status' => 'pending', 'company_id' => $identifier]));
        }

        $this->get(route('saas.admin.payment-proofs', ['company_id' => $historicalId, 'status' => 'verified']))
            ->assertOk()->assertSee('Historical reference')->assertDontSee('Owned reference')->assertDontSee('Other reference');

        foreach (['NONEXISTENT99', '0', '99999999999999999999'] as $identifier) {
            $this->get(route('saas.admin.payment-proofs', ['company_id' => $identifier, 'status' => 'all']))
                ->assertOk()->assertSee('No payment proofs found.')
                ->assertDontSee('Owned reference')->assertDontSee('Other reference')->assertDontSee('Historical reference');
        }
    }
}
