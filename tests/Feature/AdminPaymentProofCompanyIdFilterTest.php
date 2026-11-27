<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Services\DistributorDiscountService;
use App\Support\NestErps;
use App\Support\ProductCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_product_filter_includes_legacy_erp_and_di_discriminators_and_retains_company_and_status(): void
    {
        $school = $this->company('Filter School', ProductCatalog::ERPS, NestErps::SCHOOL);
        $legacy = $this->company('Filter Healthcare', ProductCatalog::ERPS, NestErps::HEALTH);
        DB::table('companies')->where('id', $legacy->id)->update(['product_type' => 'health']);
        $di = $this->company('Filter Digital Invoice', ProductCatalog::DI);
        DB::table('companies')->where('id', $di->id)->update(['product_type' => '']);
        $this->proof($school);
        $this->proof($legacy);
        $this->proof($di);
        $this->reviewer();

        $index = route('saas.admin.payment-proofs');
        $erpFilters = ['status' => 'pending', 'product' => ProductCatalog::ERPS];
        $this->get($index)->assertOk()->assertSee('All products')->assertSee($school->name)->assertSee($di->name);
        $this->get(route('saas.admin.payment-proofs', $erpFilters))
            ->assertOk()->assertSee($school->name)->assertSee($legacy->name)->assertDontSee($di->name)
            ->assertSee(NestErps::label(NestErps::SCHOOL))->assertSee(NestErps::label(NestErps::HEALTH));
        $this->get(route('saas.admin.payment-proofs', ['product' => 'health']))
            ->assertOk()->assertSee($legacy->name)->assertSee($school->name);
        $this->get(route('saas.admin.payment-proofs', ['product' => ProductCatalog::DI]))
            ->assertOk()->assertSee($di->name)->assertDontSee($legacy->name);
        $this->get(route('saas.admin.payment-proofs', $erpFilters + ['company_id' => $school->id]))
            ->assertOk()->assertSee($school->name)->assertDontSee($legacy->name)
            ->assertSee(route('saas.admin.payment-proofs', [
                'status' => 'verified', 'company_id' => $school->id, 'product' => ProductCatalog::ERPS,
            ]));
        $this->get(route('saas.admin.payment-proofs', $erpFilters + ['company_id' => $di->id]))
            ->assertOk()->assertSee('No payment proofs found.');
    }

    public function test_each_review_lists_only_compatible_plans_and_preserves_historical_quotes(): void
    {
        $school = $this->company('Scoped School', ProductCatalog::ERPS, NestErps::SCHOOL);
        $lab = $this->company('Scoped Lab', ProductCatalog::ERPS, NestErps::HEALTH);
        $schoolA = $this->plan('School Tier One', ProductCatalog::ERPS, NestErps::SCHOOL);
        $schoolB = $this->plan('School Tier Two', ProductCatalog::ERPS, NestErps::SCHOOL);
        $labPlan = $this->plan('Lab Tier One', ProductCatalog::ERPS, NestErps::HEALTH);
        $posPlan = $this->plan('Business', ProductCatalog::POS);
        $legacy = $this->proof($school, $schoolA);
        $labProof = $this->proof($lab);
        $frozen = $this->proof($school, $schoolA, DistributorDiscountService::quote($school, $schoolA, 'annual'));
        $moved = $this->proof($lab, $posPlan);
        $schoolA->update(['price' => 9999]);
        $this->reviewer();

        $response = $this->get(route('saas.admin.payment-proofs'))->assertOk();
        $xpath = $this->document($response->getContent());
        $legacyOptions = $this->planOptions($xpath, $legacy);
        $this->assertArrayHasKey($schoolA->id, $legacyOptions);
        $this->assertArrayHasKey($schoolB->id, $legacyOptions);
        $this->assertArrayNotHasKey($labPlan->id, $legacyOptions);
        $this->assertArrayNotHasKey($posPlan->id, $legacyOptions);
        $this->assertStringContainsString(NestErps::label(NestErps::SCHOOL), $legacyOptions[$schoolA->id]);
        $this->assertStringContainsString(NestErps::label(NestErps::HEALTH), $this->planOptions($xpath, $labProof)[$labPlan->id]);
        $this->assertArrayNotHasKey($schoolB->id, $this->planOptions($xpath, $labProof));
        $this->assertSame([$schoolA->id], array_keys($this->planOptions($xpath, $frozen)));
        $this->assertStringContainsString('PKR 1,500', $this->planOptions($xpath, $frozen)[$schoolA->id]);
        $this->assertSame([], $this->planOptions($xpath, $moved));
        $response->assertSee('No compatible package can be assigned to this proof.');
    }

    private function company(string $name, string $type, ?string $vertical = null): Company
    {
        return Company::create([
            'name' => $name, 'ntn' => str_replace(' ', '-', $name),
            'product_type' => $type, NestErps::VERTICAL_COLUMN => $vertical,
        ]);
    }

    private function plan(string $name, string $type, ?string $vertical = null): PricingPlan
    {
        return PricingPlan::updateOrCreate(['name' => $name, 'product_type' => $type, NestErps::VERTICAL_COLUMN => $vertical], [
            'price' => 1500, 'invoice_limit' => 50, 'user_limit' => 2, 'branch_limit' => 1, 'is_trial' => false,
        ]);
    }

    private function proof(Company $company, ?PricingPlan $plan = null, ?array $quote = null): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan?->id, 'billing_cycle' => 'annual',
            'proof_path' => 'synthetic/fixture.png', 'status' => 'pending', 'distributor_quote_snapshot' => $quote,
        ]);
    }

    private function reviewer(): void
    {
        $this->actingAs(AdminUser::create([
            'name' => 'Synthetic Payment Reviewer', 'email' => 'proof-catalog@example.test', 'role' => 'super_admin',
            'password' => bcrypt('SyntheticOnly123!'),
        ]), 'admin');
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function planOptions(DOMXPath $xpath, PaymentProof $proof): array
    {
        $action = route('saas.admin.payment-proofs.approve', $proof->id);
        $options = [];
        foreach ($xpath->query('//form[@action="'.$action.'"]//select[@name="pricing_plan_id"]/option[@value!=""]') as $option) {
            $options[(int) $option->getAttribute('value')] = trim($option->textContent);
        }

        return $options;
    }
}
