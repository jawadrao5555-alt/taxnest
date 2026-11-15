<?php
namespace Tests\Feature;

use App\Models\PricingPlan;
use Tests\TestCase;

class ConfiguredCycleQuoteViewTest extends TestCase
{
    public function test_shared_quote_shows_only_approved_configured_periods_and_exact_payable_totals(): void
    {
        $plan = new PricingPlan(['id'=>123,'product_type'=>'fbrpos','price'=>14999,'price_quarterly'=>4299.25]);
        $html = view('billing._configured-cycle-quote', compact('plan'))->render();
        $this->assertStringContainsString('14,999.00', $html);
        $this->assertStringNotContainsString('value="quarterly"', $html);
        config(['billing.shared_configured_cycles'=>true]);
        $html = view('billing._configured-cycle-quote', compact('plan'))->render();
        $this->assertStringContainsString('value="quarterly"', $html);
        $this->assertStringContainsString('4,299.25', $html);
        $this->assertStringNotContainsString('value="monthly"', $html);
        $this->assertStringContainsString('Payable total:', $html);
        $this->assertStringContainsString('does not activate or renew', $html);
    }

    public function test_public_quote_carries_selected_cycle_to_existing_signup_route(): void
    {
        config(['billing.shared_configured_cycles'=>true]);
        $plan = new PricingPlan(['id'=>125,'name'=>'Starter','product_type'=>'fbrpos','price'=>14999,'price_quarterly'=>4299.25]);
        $html = view('billing._configured-cycle-quote', ['plan'=>$plan,'signupRoute'=>'fbrpos.register'])->render();
        $this->assertStringContainsString('billing_cycle=', $html);
        $this->assertStringContainsString('encodeURIComponent(cycle)', $html);
        $this->assertStringContainsString('plan=Starter', $html);
        $this->assertStringContainsString('continue registration', $html);
    }

    public function test_monthly_retirement_hides_new_monthly_option_without_changing_annual_total(): void
    {
        config(['billing.shared_configured_cycles'=>true,'billing.disable_fresh_monthly'=>true]);
        $plan = new PricingPlan(['id'=>124,'product_type'=>'pos','price'=>18999,'price_monthly'=>1999]);
        $html = view('billing._configured-cycle-quote', compact('plan'))->render();
        $this->assertStringNotContainsString('value="monthly"', $html);
        $this->assertStringContainsString('18,999.00', $html);
    }
}
