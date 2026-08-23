<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Services\DiPlanComparisonService;
use App\Services\RequestedPackageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A RETIRED PACKAGE MUST NOT BE BUYABLE (Sep 2026 DI restructure).
 *
 * Digital Invoice now sells exactly three packages. The older ones are hidden
 * rather than deleted, because live subscriptions, payment proofs and invoice
 * history all point at those rows.
 *
 * Hiding a card is NOT enforcement: the plan id travels in the request, an old
 * pricing link still carries `?plan=Retail`, and a retired row still satisfies
 * `exists:pricing_plans,id`. So one predicate decides sellability and every
 * buying path asks it — the signup resolver, the plan list, the quote
 * endpoint, checkout and the payment-proof queue.
 *
 * Also locked: the pre-migration case. On a database where the is_public
 * column has not arrived yet nothing is hidden, so every non-trial package
 * must stay sellable rather than the panel refusing to sell anything at all.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/DiSellablePackageGuardTest.php --testdox
 */
class DiSellablePackageGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->createPlansTable(withIsPublic: true);
    }

    private function createPlansTable(bool $withIsPublic): void
    {
        Schema::dropIfExists('pricing_plans');

        Schema::create('pricing_plans', function (Blueprint $t) use ($withIsPublic) {
            $t->id();
            $t->string('name');
            $t->boolean('is_trial')->default(false);
            $t->string('product_type')->default('di');
            $t->integer('invoice_limit')->nullable();
            $t->decimal('price', 12, 2)->nullable();
            if ($withIsPublic) {
                $t->boolean('is_public')->default(true);
            }
            $t->timestamps();
        });
    }

    private function plan(array $attrs): PricingPlan
    {
        return PricingPlan::forceCreate(array_merge([
            'name' => 'Kaarobar',
            'is_trial' => false,
            'product_type' => 'di',
            'invoice_limit' => 2500,
            'price' => 2499,
        ], $attrs));
    }

    // ------------------------------------------------------------- predicate

    public function test_a_current_package_is_sellable(): void
    {
        $this->assertTrue(DiPlanComparisonService::isSellablePlan($this->plan(['is_public' => true])));
    }

    public function test_a_retired_package_is_not_sellable(): void
    {
        $retired = $this->plan(['name' => 'Retail', 'is_public' => false]);

        $this->assertFalse(DiPlanComparisonService::isSellablePlan($retired));
    }

    public function test_the_trial_is_not_sellable(): void
    {
        $trial = $this->plan(['name' => 'Trial', 'is_trial' => true, 'is_public' => true]);

        $this->assertFalse(DiPlanComparisonService::isSellablePlan($trial));
    }

    public function test_another_products_package_is_not_sellable_here(): void
    {
        $pos = $this->plan(['name' => 'Business', 'product_type' => 'pos', 'is_public' => true]);

        $this->assertFalse(DiPlanComparisonService::isSellablePlan($pos));
        $this->assertFalse(DiPlanComparisonService::isSellablePlan(null));
    }

    // ----------------------------------------------------------- plan lists

    public function test_retired_packages_never_reach_a_buying_surface(): void
    {
        $this->plan(['name' => 'Asaan', 'price' => 1799, 'is_public' => true]);
        $this->plan(['name' => 'Kaarobar', 'price' => 2499, 'is_public' => true]);
        $this->plan(['name' => 'Unlimited', 'price' => 3999, 'is_public' => true]);
        $this->plan(['name' => 'Retail', 'price' => 999, 'is_public' => false]);
        $this->plan(['name' => 'Premium', 'price' => 9999, 'is_public' => false]);

        $names = DiPlanComparisonService::plans()->pluck('name')->all();

        $this->assertSame(['Asaan', 'Kaarobar', 'Unlimited'], $names);
    }

    // ------------------------------------------------------- signup resolver

    public function test_a_stale_pricing_link_cannot_bring_a_retired_package_into_signup(): void
    {
        $this->plan(['name' => 'Retail', 'is_public' => false]);

        $this->assertNull(RequestedPackageService::resolvePlan('Retail', 'di'));
        $this->assertNull(RequestedPackageService::resolvePlan('retail', 'di'));
    }

    public function test_a_current_package_still_carries_through_signup(): void
    {
        $this->plan(['name' => 'Kaarobar', 'is_public' => true]);

        $resolved = RequestedPackageService::resolvePlan('kaarobar', 'di');

        $this->assertNotNull($resolved);
        $this->assertSame('Kaarobar', $resolved->name);
    }

    public function test_an_approval_cannot_resurrect_a_package_requested_before_the_restructure(): void
    {
        $retired = $this->plan(['name' => 'Retail', 'is_public' => false]);

        // A signup sitting in the approval queue since before the restructure
        // still points at the old package; approving must not assign it.
        $this->assertFalse(DiPlanComparisonService::isSellablePlan($retired));
        $this->assertStringContainsString(
            "product_type === 'di' && !DiPlanComparisonService::isSellablePlan(\$plan)",
            file_get_contents(base_path('app/Services/SubscriptionAssignmentService.php')),
            'assignRequestedPlanOnApproval must reject retired DI packages.'
        );
    }

    /**
     * Behavioural tests cover the predicate; this one guards the WIRING, so a
     * future edit cannot quietly drop the check from a path that takes money.
     */
    public function test_every_customer_facing_buying_path_asks_the_predicate(): void
    {
        $paths = [
            'app/Http/Controllers/BillingController.php' => 2,      // checkout + price quote
            'app/Http/Controllers/PaymentProofController.php' => 1, // bank-transfer proof
            'app/Services/RequestedPackageService.php' => 1,        // signup ?plan=
        ];

        foreach ($paths as $file => $expected) {
            $source = file_get_contents(base_path($file));
            $found = substr_count($source, 'DiPlanComparisonService::isSellablePlan');

            $this->assertGreaterThanOrEqual(
                $expected,
                $found,
                "{$file} must gate its DI package on isSellablePlan() (found {$found}, expected {$expected})."
            );
        }
    }

    // ---------------------------------------------------- pre-migration case

    public function test_before_the_migration_lands_nothing_is_hidden(): void
    {
        $this->createPlansTable(withIsPublic: false);
        $plan = $this->plan(['name' => 'Business']);

        // No is_public column yet — refusing to sell anything would be worse
        // than selling the old list for one deploy window.
        $this->assertTrue(DiPlanComparisonService::isSellablePlan($plan));
        $this->assertNotNull(RequestedPackageService::resolvePlan('Business', 'di'));
        $this->assertSame(['Business'], DiPlanComparisonService::plans()->pluck('name')->all());
    }
}
