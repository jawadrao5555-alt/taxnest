<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PricingPlan, SubscriptionInvoice};
use App\Services\{AiPageCreditService, BranchAddonService, DistributorDiscountService, PosAddonPricingService, SubscriptionAssignmentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;

final class ApprovedBillingRateCardTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        ['di', 'Asaan', 1799, 5299, 10394, 17990],
        ['di', 'Kaarobar', 2499, 7399, 14489, 24990],
        ['di', 'Unlimited', 3999, 11799, 23099, 39990],
        ['pos', 'Starter', 1999, 5499, 10499, 19999],
        ['pos', 'Business', 2999, 8299, 15749, 29999],
        ['pos', 'Unlimited', 3799, 10499, 19949, 37999],
        ['fbrpos', 'Starter', 1799, 4899, 9449, 17999],
        ['fbrpos', 'Business', 2799, 7699, 14699, 27999],
        ['erps', 'Clinic', 2499, 6899, 13124, 24999],
        ['erps', 'Hospital', 5999, 16499, 31499, 59999],
    ];

    public function test_apply_updates_exact_quotes_and_preserves_existing_terms_and_ai_payment_prices(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $this->prepareCatalogue();
        config(['billing.shared_configured_cycles' => true, 'billing.disable_fresh_monthly' => false]);
        $plan = PricingPlan::where('product_type', 'di')->where('name', 'Asaan')->sole();
        $legacyPlan = PricingPlan::create(['product_type' => 'standalone', 'name' => 'Starter', 'price' => 7777, 'invoice_limit' => 100]);
        $company = Company::create(['name' => 'Fictional DI customer', 'ntn' => 'RATECARD001', 'product_type' => 'di']);
        $active = SubscriptionAssignmentService::assign($company->id, $plan->id, 'annual');
        $originalTerm = $active->fresh()->getAttributes();
        $quote = DistributorDiscountService::quote($company, $plan, 'quarterly');
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'request_type' => 'ai_pages', 'amount' => 1999, 'status' => 'pending',
            'proof_path' => 'fictional-ai-proof', 'addon_quote_snapshot' => ['pages' => 500, 'price' => 1999],
        ]);

        $this->artisan('billing:approved-rate-card')->assertSuccessful();
        $this->assertEquals(4200, $plan->fresh()->price_quarterly);
        $this->assertSame(1999, AiPageCreditService::packPrice(500));

        $this->artisan('billing:approved-rate-card --apply')->assertSuccessful();
        $this->artisan('billing:approved-rate-card --apply')->assertSuccessful();
        foreach (self::EXPECTED as [$product, $name, $monthly, $quarterly, $sixMonth, $annual]) {
            $priced = PricingPlan::where('product_type', $product)->where('name', $name)->sole();
            $totals = array_map(fn ($cycle) => (int) SubscriptionAssignmentService::computePrice($priced, $cycle)['final_price'],
                ['monthly', 'quarterly', 'semi_annual', 'annual']);
            $this->assertSame([$monthly, $quarterly, $sixMonth, $annual], $totals, "$product $name");
        }

        $this->assertEquals(7777, $legacyPlan->fresh()->price);
        $this->assertSame($originalTerm, $active->fresh()->getAttributes());
        $this->assertEquals(4200, $quote['net_quote']);
        $this->assertNull(DistributorDiscountService::validateSnapshot($quote, $company, $plan->fresh(), 'quarterly'));
        $renewal = SubscriptionAssignmentService::assign($company->id, $plan->id, 'annual', paidRenewal: true);
        $this->assertEquals(17990, $renewal->final_price);
        $this->assertSame($active->end_date->copy()->addYearNoOverflow()->toDateString(), $renewal->end_date->toDateString());
        $this->assertSame(2099, AiPageCreditService::packPrice(500));
        $this->assertSame(4999, PosAddonPricingService::price('caller_id'));
        $this->assertSame(7999, PosAddonPricingService::price('rider_tracking'));

        $reviewer = AdminUser::create(['name' => 'Fictional reviewer', 'email' => 'rate-card@example.test',
            'password' => bcrypt('fictional-secret'), 'role' => 'super_admin']);
        $this->actingAs($reviewer, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id),
            ['verified_received_amount' => 1999])->assertSessionHas('success');
        $this->assertEquals(1999, SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole()->amount);
        $this->assertSame(500, (int) $company->fresh()->ai_page_balance);
        Http::assertNothingSent();
    }

    public function test_approved_federal_rates_and_existing_branch_proration_use_the_normal_quote_calculators(): void
    {
        config(['pra_di_sandbox.paid_addon_annual_rates' => ['federal_di' => 12000, 'federal_ims' => 18000]]);

        $this->assertSame(12000, PosAddonPricingService::price('federal_di'));
        $this->assertSame(18000, PosAddonPricingService::price('federal_ims'));
        $this->assertSame([833.0, 2500.0, 5000.0, 10000.0], array_map(
            fn (int $months) => BranchAddonService::priceForMonths(1, $months), [1, 3, 6, 12]));
    }

    public function test_ambiguous_plan_aborts_the_entire_update(): void
    {
        $this->prepareCatalogue();
        PricingPlan::create(['product_type' => 'erps', 'erps_vertical' => 'health', 'name' => 'Hospital',
            'price' => 60000, 'is_trial' => false, 'invoice_limit' => 100]);
        $this->artisan('billing:approved-rate-card --apply')->assertFailed();
        $this->assertEquals(4200, PricingPlan::where('product_type', 'di')->where('name', 'Asaan')->sole()->price_quarterly);
        $this->assertSame(1999, AiPageCreditService::packPrice(500));
    }

    private function prepareCatalogue(): void
    {
        foreach (self::EXPECTED as [$product, $name]) {
            PricingPlan::updateOrCreate(['product_type' => $product, 'name' => $name], [
                'price' => 14000, 'price_monthly' => 1500, 'price_quarterly' => 4200, 'price_yearly' => 14000,
                'is_trial' => false, 'is_public' => true, 'invoice_limit' => 100,
            ]);
        }
    }
}
