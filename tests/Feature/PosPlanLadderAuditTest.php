<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Services\PosPlanComparisonService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * PACKAGE LADDER AUDIT — IN THE ORDINARY TEST SUITE (Task 1458).
 *
 * The naming half of the package-table audit already runs here
 * (PosPlanComparisonServiceNamingTest). The rest — the bills / team / branches
 * / counters numbers, the tick-vs-cross grid, the "included in every package"
 * floor and the team-account resolver check — only ran inside
 * scripts/plan-gate-check.php, which needs the MySQL staging database up and
 * is only run right before a deploy. A code change that made the table read
 * the wrong column, or that stopped the team row going through
 * PlanLimitService, therefore stayed invisible during normal development.
 *
 * This test builds the approved POS ladder as in-memory PricingPlan models
 * (no DB row is touched) and runs the same audit() with the same expected
 * matrices the deploy script writes.
 *
 * IT ALSO PROVES THE AUDIT STILL BITES: a column that contradicts the matrix,
 * an "included in every package" column switched off, and a tightened limit
 * each have to produce a failure. An audit that cannot fail is not a guard.
 *
 * scripts/plan-gate-check.php keeps running audit() against the REAL
 * pricing_plans rows — that is what catches a live admin edit, which an
 * in-memory ladder cannot. Neither replaces the other.
 */
class PosPlanLadderAuditTest extends TestCase
{
    /**
     * The expected ladders, copied from scripts/plan-gate-check.php on purpose.
     * Written independently of the service so a renamed column or a silently
     * changed default fails here instead of shipping.
     *
     * Pro Max is retired — sellable plans are Starter, Business, Pro, Unlimited.
     * Business invoice_limit is now unlimited (-1).
     * Pro now carries former Pro Max capacity: unlimited invoices, 20 team accounts,
     * 3 branches, unlimited counters; Pro retains its Pro+ gates (hazri etc.).
     */
    private const BILL_LADDER    = ['Starter' => 2000, 'Business' => 'Unlimited', 'Pro' => 'Unlimited', 'Unlimited' => 'Unlimited'];
    private const TEAM_LADDER    = ['Starter' => 2, 'Business' => 5, 'Pro' => 20, 'Unlimited' => 'Unlimited'];
    private const BRANCH_LADDER  = ['Starter' => 1, 'Business' => 1, 'Pro' => 3, 'Unlimited' => 5];
    private const COUNTER_LADDER = ['Starter' => 1, 'Business' => 3, 'Pro' => 'Unlimited', 'Unlimited' => 'Unlimited'];

    /** plan name => the tick/cross row keys that must be ON. Everything else is a cross. */
    private const FEATURES_ON = [
        'Starter'   => [],
        'Business'  => ['restaurant', 'deals', 'riders', 'qr_menu', 'analytics', 'reports', 'excel', 'offline', 'custom_access'],
        'Pro'       => ['restaurant', 'deals', 'riders', 'qr_menu', 'hazri', 'analytics', 'reports', 'excel', 'offline', 'custom_access'],
        'Unlimited' => ['restaurant', 'deals', 'riders', 'qr_menu', 'hazri', 'analytics', 'reports', 'excel', 'offline', 'custom_access'],
    ];

    /** The real PRA POS ladder, in memory — no DB row is touched. */
    private function ladder(array $overridesByPlan = []): Collection
    {
        $rows = collect(array_keys(self::FEATURES_ON))->map(function (string $name) use ($overridesByPlan) {
            $plan = new PricingPlan();

            $attributes = [
                'name' => $name,
                'product_type' => 'pos',
                'is_trial' => false,
                // Included in every package.
                'khata_enabled'   => true,
                'loyalty_enabled' => true,
                'inventory_enabled' => true,
                'max_products'    => -1,
                'kot_enabled'     => true,
                // Limits.
                'invoice_limit' => self::unlimitedAware(self::BILL_LADDER[$name]),
                'user_limit'    => self::unlimitedAware(self::TEAM_LADDER[$name]),
                'branch_limit'  => self::unlimitedAware(self::BRANCH_LADDER[$name]),
                'max_terminals' => self::unlimitedAware(self::COUNTER_LADDER[$name]),
            ];
            foreach (PosPlanComparisonService::FEATURE_ROWS as $key => $spec) {
                $attributes[$spec['column']] = in_array($key, self::FEATURES_ON[$name], true);
            }

            $plan->forceFill(array_merge($attributes, $overridesByPlan[$name] ?? []));

            return $plan;
        });

        return collect($rows->all());
    }

    private static function unlimitedAware($value): int
    {
        return $value === 'Unlimited' ? -1 : (int) $value;
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, bool>>} */
    private function expectations(): array
    {
        $limits = [];
        $features = [];
        foreach (self::FEATURES_ON as $name => $on) {
            $limits[$name] = [
                'bills'    => self::BILL_LADDER[$name],
                'team'     => self::TEAM_LADDER[$name],
                'branches' => self::BRANCH_LADDER[$name],
                'counters' => self::COUNTER_LADDER[$name],
            ];
            $row = [];
            foreach (array_keys(PosPlanComparisonService::FEATURE_ROWS) as $key) {
                $row[$key] = in_array($key, $on, true);
            }
            $features[$name] = $row;
        }

        return [$limits, $features];
    }

    private function audit(Collection $plans): array
    {
        [$limits, $features] = $this->expectations();

        return PosPlanComparisonService::audit($plans, $limits, $features);
    }

    public function test_the_approved_pos_ladder_passes_the_full_audit(): void
    {
        $problems = $this->audit($this->ladder());

        $this->assertSame([], $problems, "The approved POS ladder must audit clean:\n" . implode("\n", $problems));
    }

    public function test_a_column_that_contradicts_the_expected_matrix_fails(): void
    {
        // Pro quietly capped at 8000 bills while the matrix says Unlimited.
        $problems = $this->audit($this->ladder(['Pro' => ['invoice_limit' => 8000]]));

        $this->assertNotEmpty($problems, 'A capped bills column must not pass as Unlimited.');
        $this->assertStringContainsString('Pro', implode("\n", $problems));
        $this->assertStringContainsString('bills', implode("\n", $problems));
    }

    public function test_an_included_in_every_package_column_switched_off_fails(): void
    {
        $problems = $this->audit($this->ladder(['Pro' => ['khata_enabled' => false]]));

        $this->assertNotEmpty($problems, 'Khata is sold as included in every package.');
        $this->assertStringContainsString('khata_enabled', implode("\n", $problems));
    }

    public function test_a_tightened_limit_on_a_costlier_package_fails(): void
    {
        // Pro sits above Business but would allow FEWER counters.
        $problems = $this->audit($this->ladder(['Pro' => ['max_terminals' => 2]]));

        $this->assertNotEmpty($problems, 'A costlier package may never tighten a limit.');
        $this->assertStringContainsString('Pro', implode("\n", $problems));
    }

    public function test_a_feature_lost_going_up_the_ladder_fails(): void
    {
        $problems = $this->audit($this->ladder(['Unlimited' => ['reports_enabled' => false]]));

        $this->assertNotEmpty($problems, 'A costlier package may never lose a tick.');
        $this->assertStringContainsString('reports', implode("\n", $problems));
    }

    public function test_the_team_row_comes_from_the_gate_resolver(): void
    {
        // Section 4 of the audit: the table must read PlanLimitService, not its
        // own arithmetic. Prove the resolver and the table agree on every plan.
        foreach ($this->ladder() as $plan) {
            $this->assertSame(
                \App\Services\PlanLimitService::teamAccountLimit($plan),
                PosPlanComparisonService::teamAccountLimit($plan),
                "{$plan->name}: the team row must come from the gate's resolver."
            );
        }
    }
}
