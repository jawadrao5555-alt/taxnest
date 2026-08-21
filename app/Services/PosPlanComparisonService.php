<?php

namespace App\Services;

use App\Models\PricingPlan;
use Illuminate\Support\Collection;

/**
 * PRA POS package comparison — SINGLE SOURCE OF TRUTH (Task 1350).
 *
 * WHY THIS EXISTS
 * ---------------
 * Every POS plan card used to carry its own hand-written bullet list
 * (pricing_plans.features JSON, display-only — no PHP reads it for gating).
 * Marketing copy and real enforcement drifted apart:
 *   • Unlimited's card said "unlimited branches" while branch_limit = 5.
 *   • Caller ID (Unlimited only) and WhatsApp Bill (Pro+) were enforced but
 *     appeared on NO card at all.
 *   • Starter / Business silently capped products (300 / 1000) with no mention.
 *   • Team accounts looked like TWO numbers (user_limit + max_users, which
 *     disagreed from Pro upward) with nothing saying which one was real.
 *
 * THE RULE: nothing below is a hand-typed tick. Every cell is read straight
 * off the pricing_plans row that actually opens or closes the feature, so the
 * table cannot say one thing while the gate does another. Add a gate column
 * and it must be named here (or declared COVERED_BY) or scripts/plan-gate-check.php
 * blocks the deploy.
 *
 * Labels/hints are lang keys (pos.pcmp_*) so the table reads correctly in
 * English, Roman Urdu and Urdu.
 */
class PosPlanComparisonService
{
    /**
     * Limit rows, in display order.
     *   column — the pricing_plans column holding the number
     *   hint   — a pos.pcmp_<key>_hint line exists under the label
     */
    public const LIMIT_ROWS = [
        'bills'    => ['column' => 'invoice_limit',  'hint' => true],
        'team'     => ['column' => 'user_limit',     'hint' => true],
        'branches' => ['column' => 'branch_limit',   'hint' => true],
        'counters' => ['column' => 'max_terminals',  'hint' => true],
    ];

    /**
     * Tick/cross rows, in display order. Each value is the boolean column on
     * pricing_plans that PosFeatureService::planAllows() (or, for the
     * restaurant module, PosFeatureService::restaurantAllowed()) reads.
     */
    public const FEATURE_ROWS = [
        'restaurant'     => ['column' => 'restaurant_enabled',     'hint' => true],
        'deals'          => ['column' => 'deals_enabled',          'hint' => false],
        'analytics'      => ['column' => 'analytics_enabled',      'hint' => false],
        'reports'        => ['column' => 'reports_enabled',        'hint' => true],
        'excel'          => ['column' => 'excel_enabled',          'hint' => true],
        'offline'        => ['column' => 'offline_enabled',        'hint' => true],
        'riders'         => ['column' => 'riders_enabled',         'hint' => true],
        'qr_menu'        => ['column' => 'qr_menu_enabled',        'hint' => true],
        'whatsapp'       => ['column' => 'whatsapp_enabled',       'hint' => false],
        'hazri'          => ['column' => 'hazri_enabled',          'hint' => true],
        'rider_tracking' => ['column' => 'rider_tracking_enabled', 'hint' => true],
        'custom_access'  => ['column' => 'custom_access_enabled',  'hint' => true],
        'caller_id'      => ['column' => 'caller_id_enabled',      'hint' => true],
    ];

    /**
     * "Included in every package" block. Kept OUT of the tick/cross grid on
     * purpose — a column of five ticks teaches the reader nothing and waters
     * down the rows that do differ.
     *
     *   column    — boolean plan column that must be ON for every POS plan
     *   unlimited — numeric plan column that must be unlimited on every POS plan
     *   (neither) — a product-wide fact that no plan column gates
     *
     * The verify() audit fails if a listed column is off / capped anywhere, so
     * "included" can never quietly become "not included".
     */
    public const INCLUDED_ROWS = [
        'pra_receipts' => [],
        'khata'        => ['column' => 'khata_enabled'],
        'loyalty'      => ['column' => 'loyalty_enabled'],
        'inventory'    => ['column' => 'inventory_enabled'],
        'products'     => ['unlimited' => 'max_products'],
        'thermal'      => [],
        'mobile_app'   => [],
        'languages'    => [],
    ];

    /**
     * Gate columns deliberately NOT given their own row, and the row that
     * already speaks for them. kot_enabled is the FBR POS twin of the PRA
     * restaurant module: on PRA the kitchen flags (kot/tables/kitchen/notes/
     * recipes) are masked by restaurantAllowed(), so "Restaurant / Kitchen"
     * is the customer-facing name for both.
     */
    public const COVERED_BY = [
        'kot_enabled' => 'restaurant',
    ];

    /** Business is the flagged column on both the cards and the table. */
    public const POPULAR_PLAN = 'Business';

    /** The paid POS packages, cheapest first — same query the cards use. */
    public static function plans(): Collection
    {
        return PricingPlan::where('is_trial', false)
            ->where('product_type', 'pos')
            ->orderBy('price')
            ->get();
    }

    /** null / any negative value means "no cap" everywhere in the codebase. */
    public static function isUnlimited($value): bool
    {
        return $value === null || (int) $value < 0;
    }

    /**
     * The ONE team-account number — owned by the gate, read by the table (never
     * the other way round). See PlanLimitService::teamAccountLimit().
     */
    public static function teamAccountLimit(?PricingPlan $plan): ?int
    {
        return PlanLimitService::teamAccountLimit($plan);
    }

    /** Column header data for each package. */
    public static function planColumns(Collection $plans, ?int $currentPlanId = null): array
    {
        return $plans->map(fn (PricingPlan $plan) => [
            'id'      => (int) $plan->id,
            'name'    => $plan->name,
            'price'   => 'Rs ' . number_format((float) $plan->sale_price),
            'popular' => $plan->name === self::POPULAR_PLAN,
            'current' => $currentPlanId !== null && (int) $plan->id === $currentPlanId,
        ])->all();
    }

    /**
     * The whole grid: two sections, every cell derived from the plan row.
     *
     * @return array<int, array{key:string,title:string,rows:array}>
     */
    public static function sections(Collection $plans): array
    {
        $limitRows = [];
        foreach (self::LIMIT_ROWS as $key => $spec) {
            $limitRows[] = [
                'kind'   => 'limit',
                'key'    => $key,
                'column' => $spec['column'],
                'label'  => __('pos.pcmp_' . $key),
                'hint'   => $spec['hint'] ? __('pos.pcmp_' . $key . '_hint', ['price' => number_format(BranchAddonService::PRICE_PER_YEAR)]) : null,
                'values' => $plans->map(function (PricingPlan $plan) use ($key, $spec) {
                    $raw = $key === 'team'
                        ? self::teamAccountLimit($plan)
                        : $plan->{$spec['column']};

                    return [
                        'unlimited' => self::isUnlimited($raw),
                        'text'      => self::isUnlimited($raw) ? __('pos.pcmp_unlimited') : number_format((int) $raw),
                    ];
                })->all(),
            ];
        }

        $featureRows = [];
        foreach (self::FEATURE_ROWS as $key => $spec) {
            $featureRows[] = [
                'kind'   => 'feature',
                'key'    => $key,
                'column' => $spec['column'],
                'label'  => __('pos.pcmp_' . $key),
                'hint'   => $spec['hint'] ? __('pos.pcmp_' . $key . '_hint') : null,
                'values' => $plans->map(fn (PricingPlan $plan) => (bool) $plan->{$spec['column']})->all(),
            ];
        }

        return [
            ['key' => 'limits',   'title' => __('pos.pcmp_sec_limits'),   'rows' => $limitRows],
            ['key' => 'features', 'title' => __('pos.pcmp_sec_features'), 'rows' => $featureRows],
        ];
    }

    /** Labels for the "included in every package" block. */
    public static function includedItems(): array
    {
        $items = [];
        foreach (array_keys(self::INCLUDED_ROWS) as $key) {
            $items[] = __('pos.pcmp_inc_' . $key);
        }

        return $items;
    }

    /**
     * Drift audit — called by scripts/plan-gate-check.php before every deploy.
     * Returns a list of human-readable problems; empty means table and gates
     * agree.
     *
     * @param  Collection<int, PricingPlan>  $plans
     * @param  array<string, array<string, int|string|null>>  $expectedLimits
     *         plan name => [row key => expected number, or 'Unlimited']
     * @param  array<string, array<string, bool>>  $expectedFeatures
     *         plan name => [row key => expected tick]
     */
    public static function audit(Collection $plans, array $expectedLimits = [], array $expectedFeatures = []): array
    {
        $problems = [];

        // 1. Every gate column must have a customer-facing name.
        $named = array_column(self::FEATURE_ROWS, 'column');
        foreach (self::INCLUDED_ROWS as $spec) {
            if (isset($spec['column'])) {
                $named[] = $spec['column'];
            }
        }
        $gateColumns = array_merge(PosFeatureService::PLAN_GATES, ['restaurant_enabled']);
        foreach ($gateColumns as $column) {
            if (in_array($column, $named, true) || isset(self::COVERED_BY[$column])) {
                continue;
            }
            $problems[] = "Gate column '{$column}' has no customer-facing row in PosPlanComparisonService "
                . '(add it to FEATURE_ROWS / INCLUDED_ROWS, or declare it in COVERED_BY).';
        }
        foreach (self::COVERED_BY as $column => $rowKey) {
            if (!isset(self::FEATURE_ROWS[$rowKey]) && !isset(self::INCLUDED_ROWS[$rowKey])) {
                $problems[] = "COVERED_BY maps '{$column}' to unknown row '{$rowKey}'.";
            }
        }

        // 2. Every row label (and declared hint) must resolve in all three languages.
        $rowSpecs = [];
        foreach (self::LIMIT_ROWS as $key => $spec) {
            $rowSpecs['pcmp_' . $key] = $spec['hint'];
        }
        foreach (self::FEATURE_ROWS as $key => $spec) {
            $rowSpecs['pcmp_' . $key] = $spec['hint'];
        }
        foreach (array_keys(self::INCLUDED_ROWS) as $key) {
            $rowSpecs['pcmp_inc_' . $key] = false;
        }
        $chrome = ['pcmp_sec_limits', 'pcmp_sec_features', 'pcmp_unlimited', 'pcmp_col_features',
            'pcmp_heading', 'pcmp_sub', 'pcmp_branch_note', 'pcmp_scroll_tip', 'pcmp_popular',
            'pcmp_your_plan', 'pcmp_included_title', 'pcmp_included_sub'];
        foreach ($chrome as $key) {
            $rowSpecs[$key] = false;
        }
        foreach (['en', 'rur', 'ur'] as $locale) {
            foreach ($rowSpecs as $key => $needsHint) {
                foreach ($needsHint ? [$key, $key . '_hint'] : [$key] as $fullKey) {
                    $value = __('pos.' . $fullKey, [], $locale);
                    if (!is_string($value) || trim($value) === '' || $value === 'pos.' . $fullKey) {
                        $problems[] = "Missing lang key pos.{$fullKey} in [{$locale}] — every comparison row needs a name in all three languages.";
                    }
                }
            }
        }

        // 3. "Included in every package" must actually be true on every POS plan.
        foreach (self::INCLUDED_ROWS as $key => $spec) {
            foreach ($plans as $plan) {
                if (isset($spec['column']) && empty($plan->{$spec['column']})) {
                    $problems[] = "'{$key}' is listed as included in every package but {$plan->name} has {$spec['column']} OFF.";
                }
                if (isset($spec['unlimited']) && !self::isUnlimited($plan->{$spec['unlimited']})) {
                    $problems[] = "'{$key}' is listed as unlimited in every package but {$plan->name} caps {$spec['unlimited']} at {$plan->{$spec['unlimited']}}.";
                }
            }
        }

        // 4. Team accounts: the table must never compute its own number — it
        //    has to come back from the same resolver the POS gate calls.
        foreach ($plans as $plan) {
            if (self::teamAccountLimit($plan) !== PlanLimitService::teamAccountLimit($plan)) {
                $problems[] = "{$plan->name}: the team-account row is not reading "
                    . 'PlanLimitService::teamAccountLimit() — the table and the gate would drift.';
            }
        }

        // 5. The table's numbers and ticks against the deploy script's own
        //    independently written expectations.
        $byName = $plans->keyBy('name');
        foreach ($expectedLimits as $planName => $expected) {
            $plan = $byName->get($planName);
            if (!$plan) {
                $problems[] = "Expected POS plan '{$planName}' not found.";
                continue;
            }
            foreach ($expected as $rowKey => $want) {
                $column = self::LIMIT_ROWS[$rowKey]['column'] ?? null;
                if (!$column) {
                    $problems[] = "Unknown limit row '{$rowKey}' in the expected matrix.";
                    continue;
                }
                $raw = $rowKey === 'team' ? self::teamAccountLimit($plan) : $plan->{$column};
                $got = self::isUnlimited($raw) ? 'Unlimited' : (int) $raw;
                if ($got !== $want) {
                    $problems[] = "{$planName} / {$rowKey}: table shows {$got} (from {$column}) "
                        . "but the package matrix expects {$want}.";
                }
            }
        }
        foreach ($expectedFeatures as $planName => $expected) {
            $plan = $byName->get($planName);
            if (!$plan) {
                continue;
            }
            foreach ($expected as $rowKey => $want) {
                $column = self::FEATURE_ROWS[$rowKey]['column'] ?? null;
                if (!$column) {
                    $problems[] = "Unknown feature row '{$rowKey}' in the expected matrix.";
                    continue;
                }
                $got = (bool) $plan->{$column};
                if ($got !== (bool) $want) {
                    $problems[] = "{$planName} / {$rowKey}: table would show " . ($got ? 'tick' : 'cross')
                        . " (from {$column}) but the package matrix expects " . ($want ? 'tick' : 'cross') . '.';
                }
            }
        }

        return $problems;
    }
}
