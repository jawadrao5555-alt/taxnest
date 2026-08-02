<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS Annual + Quarterly pricing (Aug 2026 ladder restructure).
 *
 * Locks the money math: a POS plan with an explicit price_quarterly can be
 * bought quarterly (3-month expiry, exact hand-set price); everything else
 * (no quarterly price, fbrpos, weird cycles) stays forced-annual. DI keeps
 * its full 4-cycle discount formula untouched.
 */
class PosQuarterlyPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // drop + recreate (not hasTable-guard): an earlier test class may have
        // left a pricing_plans WITHOUT price_quarterly in the shared :memory:
        // connection — inheriting that shape would 500 every INSERT here.
        Schema::dropIfExists('pricing_plans');
        if (true) {
            Schema::create('pricing_plans', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('product_type')->default('di');
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('price_monthly', 12, 2)->nullable();
                $table->decimal('price_quarterly', 12, 2)->nullable();
                $table->decimal('compare_at_price', 12, 2)->nullable();
                $table->boolean('is_trial')->default(false);
                $table->integer('invoice_limit')->default(-1);
                $table->integer('user_limit')->nullable();
                $table->integer('branch_limit')->nullable();
                $table->text('features')->nullable();
                $table->timestamps();
            });
        }

        Schema::dropIfExists('subscriptions');
        if (true) {
            Schema::create('subscriptions', function ($table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('pricing_plan_id');
                $table->date('start_date');
                $table->date('end_date');
                $table->boolean('active')->default(true);
                $table->string('billing_cycle')->default('monthly');
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('final_price', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        Schema::dropIfExists('sale_campaigns');
        if (true) {
            Schema::create('sale_campaigns', function ($table) {
                $table->id();
                $table->string('product_type')->nullable();
                $table->decimal('percent', 5, 2)->default(0);
                $table->string('badge')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->boolean('active')->default(false);
                $table->timestamps();
            });
        }
    }

    private function makePosPlan(array $overrides = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'name' => 'Starter',
            'product_type' => 'pos',
            'price' => 14999,
            'price_quarterly' => 4299,
            'is_trial' => false,
            'invoice_limit' => 800,
        ], $overrides));
    }

    public function test_pos_quarterly_cycle_uses_exact_quarterly_price(): void
    {
        $plan = $this->makePosPlan();

        $priced = SubscriptionAssignmentService::computePrice($plan, 'quarterly');

        $this->assertSame('quarterly', $priced['cycle']);
        $this->assertSame(4299.0, $priced['final_price']);
    }

    public function test_pos_annual_cycle_still_uses_annual_price(): void
    {
        $plan = $this->makePosPlan();

        $priced = SubscriptionAssignmentService::computePrice($plan, 'annual');

        $this->assertSame('annual', $priced['cycle']);
        $this->assertSame(14999.0, $priced['final_price']);
    }

    public function test_pos_plan_without_quarterly_price_forces_annual(): void
    {
        $plan = $this->makePosPlan(['price_quarterly' => null]);

        $priced = SubscriptionAssignmentService::computePrice($plan, 'quarterly');

        $this->assertSame('annual', $priced['cycle']);
        $this->assertSame(14999.0, $priced['final_price']);
    }

    public function test_pos_monthly_and_semi_annual_force_annual(): void
    {
        $plan = $this->makePosPlan();

        foreach (['monthly', 'semi_annual', 'garbage', null] as $cycle) {
            $priced = SubscriptionAssignmentService::computePrice($plan, (string) $cycle);
            $this->assertSame('annual', $priced['cycle'], "cycle [$cycle] must force annual");
            $this->assertSame(14999.0, $priced['final_price']);
        }
    }

    public function test_fbrpos_quarterly_still_forces_annual(): void
    {
        $plan = PricingPlan::create([
            'name' => 'FBR Basic',
            'product_type' => 'fbrpos',
            'price' => 1000, // monthly base for fbrpos
            'price_quarterly' => 9999, // must be IGNORED for fbrpos
            'is_trial' => false,
            'invoice_limit' => -1,
        ]);

        $priced = SubscriptionAssignmentService::computePrice($plan, 'quarterly');

        $this->assertSame('annual', $priced['cycle']);
        $this->assertSame(round(1000 * 12 * 0.94), $priced['final_price']);
    }

    public function test_di_quarterly_formula_unchanged(): void
    {
        $plan = PricingPlan::create([
            'name' => 'DI Plan',
            'product_type' => 'di',
            'price' => 1000, // monthly base for di
            'is_trial' => false,
            'invoice_limit' => -1,
        ]);

        $priced = SubscriptionAssignmentService::computePrice($plan, 'quarterly');
        $expected = Subscription::calculateFinalPrice(1000.0, 'quarterly');

        $this->assertSame('quarterly', $priced['cycle']);
        $this->assertSame($expected['final_price'], $priced['final_price']);
    }

    public function test_assign_pos_quarterly_creates_three_month_subscription(): void
    {
        $plan = $this->makePosPlan();

        $sub = SubscriptionAssignmentService::assign(9001, $plan->id, 'quarterly');

        $this->assertSame('quarterly', $sub->billing_cycle);
        $this->assertSame(4299.0, (float) $sub->final_price);
        $this->assertSame(
            now()->addMonths(3)->toDateString(),
            \Carbon\Carbon::parse($sub->end_date)->toDateString()
        );
        $this->assertTrue((bool) $sub->active);
    }

    public function test_assign_pos_annual_creates_twelve_month_subscription(): void
    {
        $plan = $this->makePosPlan();

        $sub = SubscriptionAssignmentService::assign(9002, $plan->id, 'annual');

        $this->assertSame('annual', $sub->billing_cycle);
        $this->assertSame(14999.0, (float) $sub->final_price);
        $this->assertSame(
            now()->addMonths(12)->toDateString(),
            \Carbon\Carbon::parse($sub->end_date)->toDateString()
        );
    }

    public function test_legacy_yearly_normalizes_to_annual(): void
    {
        $this->assertSame('annual', SubscriptionAssignmentService::normalizeCycle('yearly'));
        $this->assertSame('quarterly', SubscriptionAssignmentService::normalizeCycle('quarterly'));
        $this->assertSame('monthly', SubscriptionAssignmentService::normalizeCycle(null));
    }
}
