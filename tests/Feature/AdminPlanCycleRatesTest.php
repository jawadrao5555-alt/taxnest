<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use Tests\TestCase;

/** Admin plan surfaces expose the annual sellable rate only. */
class AdminPlanCycleRatesTest extends TestCase
{
    public function test_a_di_card_shows_only_the_annual_sellable_rate(): void
    {
        $plan = new PricingPlan([
            'name' => 'Kaarobar',
            'product_type' => 'di',
            'price' => 2499,
            'price_monthly' => 2499,
            'price_quarterly' => 6999,
            'price_semi_annual' => 13799,
            'price_yearly' => 24990,
            'invoice_limit' => 2500,
        ]);

        $html = $this->renderCard($plan);

        $this->assertStringContainsString('Annual rate', $html);
        $this->assertStringContainsString('24,990', $html);
        $this->assertStringNotContainsString('6,999', $html);
        $this->assertStringNotContainsString('13,799', $html);
    }

    public function test_a_hand_set_annual_rate_is_not_flagged_as_derived(): void
    {
        $plan = new PricingPlan([
            'name' => 'Unlimited',
            'product_type' => 'di',
            'price' => 3999,
            'price_monthly' => 3999,
            'price_quarterly' => 11299,
            'price_semi_annual' => 21999,
            'price_yearly' => 39990,
            'invoice_limit' => -1,
        ]);

        $html = $this->renderCard($plan);

        $this->assertStringNotContainsString('stored monthly base', $html);
    }

    public function test_a_derived_annual_rate_is_called_out(): void
    {
        $plan = new PricingPlan([
            'name' => 'Half Priced',
            'product_type' => 'di',
            'price' => 1799,
            'price_monthly' => 1799,
            'price_quarterly' => null,
            'price_semi_annual' => null,
            'price_yearly' => null,
            'invoice_limit' => 700,
        ]);

        $html = $this->renderCard($plan);

        $this->assertStringContainsString('stored monthly base', $html);
    }

    public function test_a_pra_pos_card_shows_its_annual_rate_only(): void
    {
        $plan = new PricingPlan([
            'name' => 'POS Business',
            'product_type' => 'pos',
            'price' => 24000,
            'price_quarterly' => 7000,
            'invoice_limit' => 5000,
        ]);

        $html = $this->renderCard($plan);

        $this->assertStringContainsString('24,000', $html);
        $this->assertStringNotContainsString('7,000', $html);
    }

    public function test_the_create_form_only_edits_the_annual_cycle_rate(): void
    {
        $form = file_get_contents(resource_path('views/saas-admin/plans.blade.php'));

        $this->assertStringContainsString('name="price_yearly"', $form);
        foreach (['price_monthly', 'price_quarterly', 'price_semi_annual'] as $field) {
            $this->assertStringNotContainsString('name="' . $field . '"', $form);
        }
    }

    private function renderCard(PricingPlan $plan): string
    {
        // The card links to the edit route, which needs a key.
        $plan->id = $plan->id ?: 999;

        return view('saas-admin.partials.plan-card', [
            'plan' => $plan,
            'color' => 'emerald',
        ])->render();
    }
}
