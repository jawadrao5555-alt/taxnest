<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use Tests\TestCase;

/**
 * The admin plan card used to print the monthly figure and nothing else, so a
 * package that sells four cycles LOOKED like a monthly-only subscription and
 * nobody could see what a quarterly, half-year or yearly buyer is actually
 * charged. These tests keep every cycle on the card, and keep the create form
 * able to price them at birth (before this, the ladder invented those rates
 * until someone remembered to edit the package afterwards).
 */
class AdminPlanCycleRatesTest extends TestCase
{
    public function test_a_di_card_shows_the_rupees_charged_for_every_cycle(): void
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

        $this->assertStringContainsString('Cycle Rates', $html);
        foreach (['Monthly', 'Quarterly', 'Half-Year', 'Annual'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        foreach (['2,499', '6,999', '13,799', '24,990'] as $amount) {
            $this->assertStringContainsString($amount, $html);
        }
    }

    public function test_a_hand_set_rate_is_never_flagged_as_guesswork(): void
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

        $this->assertStringNotContainsString('cycle-discount ladder', $html);
    }

    public function test_a_cycle_with_no_rate_of_its_own_is_called_out(): void
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

        // The admin must be able to SEE that three of the four cycles are
        // being priced by a formula nobody signed off on.
        $this->assertStringContainsString('cycle-discount ladder', $html);
    }

    public function test_a_pra_pos_card_shows_its_annual_and_quarterly_rate(): void
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
        $this->assertStringContainsString('7,000', $html);
    }

    public function test_the_create_form_can_price_every_cycle_at_birth(): void
    {
        $form = file_get_contents(resource_path('views/saas-admin/plans.blade.php'));

        foreach (['price_quarterly', 'price_semi_annual', 'price_yearly'] as $field) {
            $this->assertStringContainsString(
                'name="' . $field . '"',
                $form,
                "The new-plan form must be able to set {$field}, or a new package is born monthly-only."
            );
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
