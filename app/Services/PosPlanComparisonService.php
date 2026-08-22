<?php

namespace App\Services;

use App\Models\PricingPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

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
 * and it must be named here (or declared COVERED_BY) or auditNames() fails —
 * in the normal test suite (tests/Unit/PosPlanComparisonNamingTest.php) and
 * again in scripts/plan-gate-check.php, which blocks the deploy.
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
        'riders'         => ['column' => 'riders_enabled',         'hint' => true],
        'qr_menu'        => ['column' => 'qr_menu_enabled',        'hint' => true],
        'hazri'          => ['column' => 'hazri_enabled',          'hint' => true],
        'analytics'      => ['column' => 'analytics_enabled',      'hint' => false],
        'reports'        => ['column' => 'reports_enabled',        'hint' => true],
        'excel'          => ['column' => 'excel_enabled',          'hint' => true],
        'offline'        => ['column' => 'offline_enabled',        'hint' => true],
        'custom_access'  => ['column' => 'custom_access_enabled',  'hint' => true],
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

    /**
     * Gate columns sold as PAID ADD-ONS: no POS package includes them, so they
     * have no tick/cross row — their customer-facing name lives in the add-on
     * catalogue shown in the billing purchase box and comparison-table strip.
     *
     * Map: pricing_plans gate column => add-on code. auditNames() verifies
     * the code exists in the catalogue and reads in all three languages;
     * audit() fails the deploy if any POS plan row still has one of these
     * columns ON (a package would silently include a paid add-on).
     */
    public const ADDON_COLUMNS = [
        'whatsapp_enabled'       => 'whatsapp_bill',
        'rider_tracking_enabled' => 'rider_tracking',
        'caller_id_enabled'      => 'caller_id',
    ];

    /** Business is the flagged column on both the cards and the table. */
    public const POPULAR_PLAN = 'Business';

    /**
     * Strict current-selling allowlist. Retired/legacy POS rows stay in
     * pricing_plans for history but cannot become sellable merely because a
     * new row name appears in the database.
     */
    public const SELLABLE_PLAN_NAMES = ['Starter', 'Business', 'Pro', 'Unlimited'];

    /**
     * The POS surfaces that render package cards (Task 1384). auditCards()
     * scans them for hand-written claims, so a card can never grow its own
     * copy again.
     *
     * The landing dropped out in Task 1483: its card stack was deleted and the
     * comparison table became the buying surface, so the landing now prints
     * plan numbers ON PURPOSE (the table's own limit rows) and scanning it for
     * them would fail forever. The panel's upgrade cards stay guarded.
     */
    public const CARD_VIEWS = [
        'resources/views/pos/billing.blade.php',
    ];

    /**
     * Patterns a package card may NOT contain: the display-only features JSON
     * (nothing gates on it) and any limit the comparison table already prints.
     * Numbers live in exactly ONE place — the table.
     */
    public const CARD_FORBIDDEN = [
        '->features'              => 'the display-only pricing_plans.features JSON (no gate reads it)',
        'getInvoiceLimitDisplay'  => 'the bills-per-month number (comparison table owns it)',
        'getUserLimitDisplay'     => 'the team-account number (comparison table owns it)',
        'getBranchLimitDisplay'   => 'the branch number (comparison table owns it)',
        'invoice_limit'           => 'the bills-per-month column',
        'user_limit'              => 'the team-account column',
        'branch_limit'            => 'the branch column',
        'max_terminals'           => 'the counters column',
        'max_products'            => 'the products column',
    ];

    /** The paid POS packages, cheapest first — same query the cards use. */
    public static function plans(): Collection
    {
        return PricingPlan::where('is_trial', false)
            ->where('product_type', 'pos')
            ->whereIn('name', self::SELLABLE_PLAN_NAMES)
            ->orderBy('price')
            ->get();
    }

    /** Whether a row may be sold or assigned as a current PRA POS package. */
    public static function isSellablePlan(?PricingPlan $plan): bool
    {
        return $plan !== null
            && !$plan->is_trial
            && $plan->product_type === 'pos'
            && in_array($plan->name, self::SELLABLE_PLAN_NAMES, true);
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

    /**
     * Column header data for each package.
     *
     * $withSignup adds the landing-page buying block (Task 1483): PRA POS
     * bills ANNUALLY, so the headline is the yearly price with the hand-set
     * 3-month alternative underneath, and the button carries the package into
     * /pos/register. Every number still comes from the pricing_plans columns
     * the gates read — sale_price/price are accessors over the same row, never
     * the display-only features JSON. The POS billing panel calls this without
     * the flag and keeps rendering exactly as before.
     */
    public static function planColumns(Collection $plans, ?int $currentPlanId = null, bool $withSignup = false): array
    {
        return $plans->map(function (PricingPlan $plan) use ($currentPlanId, $withSignup) {
            $col = [
                'id'      => (int) $plan->id,
                'name'    => $plan->name,
                'price'   => 'Rs ' . number_format((float) $plan->sale_price),
                'popular' => $plan->name === self::POPULAR_PLAN,
                'current' => $currentPlanId !== null && (int) $plan->id === $currentPlanId,
            ];

            if (!$withSignup) {
                return $col;
            }

            $col['price_period'] = __('pos.pcmp_per_year');

            // Sale campaigns discount the ANNUAL price only (quarterly is
            // already priced at a premium) — say so rather than let a shop
            // read the badge onto the 3-month line.
            $onSale = (float) $plan->sale_price < (float) $plan->price;
            if ($onSale) {
                $col['price_compare'] = 'Rs ' . number_format((float) $plan->price);
                $col['sale_badge']    = $plan->sale_badge;
            }

            // Shorter cycles are listed as alternatives to the headline annual
            // price. Each one only appears when the plan actually carries that
            // price, which is the same condition computePrice() charges on — a
            // note can never advertise a cycle the checkout would refuse.
            $alternativeCycles = [];
            $quarterly = (float) ($plan->price_quarterly ?? 0);
            if ($quarterly > 0) {
                $alternativeCycles[] = __('pos.pcmp_or_quarterly', ['price' => number_format($quarterly)]);
            }
            $monthly = (float) ($plan->price_monthly ?? 0);
            if ($monthly > 0) {
                $alternativeCycles[] = __('pos.pcmp_or_monthly', ['price' => number_format($monthly)]);
            }
            if ($alternativeCycles !== []) {
                $col['price_note'] = implode(' · ', $alternativeCycles)
                    . ($onSale ? ' ' . __('pos.pcmp_sale_annual_only') : '');
            }

            $col['cta_url']   = route('pos.register', ['plan' => $plan->name], false);
            $col['cta_label'] = __('pos.pcmp_choose');

            return $col;
        })->all();
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

    /**
     * Package-card bullets — SAME source as the table (Task 1384).
     *
     * A card used to carry its own hand-written bullet list (the display-only
     * features JSON), so it could promise a feature the plan row did not grant
     * and contradict the table printed right under it. Now:
     *   • the cheapest package lists what every package includes;
     *   • every package above it lists ONLY what it newly unlocks over the
     *     package below — read off the same column the table's tick reads;
     *   • a capped limit that becomes uncapped gets a claim-free "Unlimited …"
     *     line (still read off the column, still without a number).
     * No card ever prints bills / team / branch / counter / product numbers —
     * those belong to the table alone, so the two cannot drift apart.
     *
     * The "Everything in <previous>, plus:" framing is NOT assumed: plan rows
     * are editable live from the admin panel (price order, a switched-off gate,
     * a tightened cap, a brand-new package dropped into the middle), so the
     * claim is verified at render time by cardInherits(). When it does not
     * hold, the card silently falls back to a standalone list of what THAT
     * package actually gives — never a stale promise.
     *
     * @return array<int, array{key:string,column:?string,label:string,hint:?string,source:string}>
     */
    public static function cardHighlights(?PricingPlan $plan, ?PricingPlan $prevPlan = null): array
    {
        if (!$plan) {
            return [];
        }

        // ── Stands on the package below it: list only the new gains ────────
        if (self::cardInherits($plan, $prevPlan)) {
            $rows = [];
            foreach (self::FEATURE_ROWS as $key => $spec) {
                if (!$plan->{$spec['column']} || $prevPlan->{$spec['column']}) {
                    continue;
                }
                $rows[] = self::cardFeatureRow($key, $spec);
            }

            // A cap that lifts is a real upgrade — say it in words, never a number.
            foreach (self::LIMIT_ROWS as $key => $spec) {
                if (!self::cardIsUncapped($plan, $key, $spec) || self::cardIsUncapped($prevPlan, $key, $spec)) {
                    continue;
                }
                $rows[] = self::cardLimitRow($key, $spec);
            }

            return $rows;
        }

        // ── Standalone card: the base package, or a ladder that no longer
        //    holds. Everything listed is checked against THIS plan's row. ───
        $rows = [];
        foreach (self::INCLUDED_ROWS as $key => $spec) {
            if (isset($spec['column']) && empty($plan->{$spec['column']})) {
                continue;
            }
            if (isset($spec['unlimited']) && !self::isUnlimited($plan->{$spec['unlimited']})) {
                continue;
            }
            $rows[] = [
                'key'    => $key,
                'column' => $spec['column'] ?? ($spec['unlimited'] ?? null),
                'label'  => __('pos.pcmp_inc_' . $key),
                'hint'   => null,
                'source' => 'included',
            ];
        }
        foreach (self::FEATURE_ROWS as $key => $spec) {
            if ($plan->{$spec['column']}) {
                $rows[] = self::cardFeatureRow($key, $spec);
            }
        }
        foreach (self::LIMIT_ROWS as $key => $spec) {
            if (self::cardIsUncapped($plan, $key, $spec)) {
                $rows[] = self::cardLimitRow($key, $spec);
            }
        }

        return $rows;
    }

    /**
     * May this card say "Everything in <previous>, plus:"?
     *
     * Only when the package below really is a subset: no tick it owns is
     * switched off here, and no limit it has is tightened here. Checked on
     * every render because pricing_plans rows are live-editable.
     */
    public static function cardInherits(?PricingPlan $plan, ?PricingPlan $prevPlan): bool
    {
        if (!$plan || !$prevPlan) {
            return false;
        }

        foreach (self::FEATURE_ROWS as $spec) {
            if ($prevPlan->{$spec['column']} && !$plan->{$spec['column']}) {
                return false;
            }
        }
        foreach (self::LIMIT_ROWS as $key => $spec) {
            if (self::cardIsUncapped($plan, $key, $spec)) {
                continue;
            }
            if (self::cardIsUncapped($prevPlan, $key, $spec)) {
                return false;
            }
            if ((int) self::cardLimitValue($plan, $key, $spec) < (int) self::cardLimitValue($prevPlan, $key, $spec)) {
                return false;
            }
        }
        // The base package's own floor must hold before anything can inherit it.
        foreach (self::INCLUDED_ROWS as $spec) {
            if (isset($spec['column']) && (empty($prevPlan->{$spec['column']}) || empty($plan->{$spec['column']}))) {
                return false;
            }
            if (isset($spec['unlimited'])
                && (!self::isUnlimited($prevPlan->{$spec['unlimited']}) || !self::isUnlimited($plan->{$spec['unlimited']}))) {
                return false;
            }
        }

        return true;
    }

    /**
     * May the cheapest card say "Every package includes:"?
     *
     * That heading speaks for the WHOLE ladder, so it is only honest while
     * every package really carries the floor. One live admin edit (capping
     * products on a higher package, switching khata off) makes it false, and
     * the card then speaks for itself instead.
     *
     * @param  Collection<int, PricingPlan>|null  $plans
     */
    public static function cardIncludedFloorHolds(?Collection $plans): bool
    {
        if (!$plans || $plans->isEmpty()) {
            return false;
        }

        foreach ($plans as $plan) {
            foreach (self::INCLUDED_ROWS as $spec) {
                if (isset($spec['column']) && empty($plan->{$spec['column']})) {
                    return false;
                }
                if (isset($spec['unlimited']) && !self::isUnlimited($plan->{$spec['unlimited']})) {
                    return false;
                }
            }
        }

        return true;
    }

    /** The limit behind a comparison row (team accounts come from the gate's resolver). */
    private static function cardLimitValue(PricingPlan $plan, string $key, array $spec)
    {
        return $key === 'team' ? self::teamAccountLimit($plan) : $plan->{$spec['column']};
    }

    private static function cardIsUncapped(PricingPlan $plan, string $key, array $spec): bool
    {
        return self::isUnlimited(self::cardLimitValue($plan, $key, $spec));
    }

    private static function cardFeatureRow(string $key, array $spec): array
    {
        return [
            'key'    => $key,
            'column' => $spec['column'],
            'label'  => __('pos.pcmp_' . $key),
            'hint'   => $spec['hint'] ? __('pos.pcmp_' . $key . '_hint') : null,
            'source' => 'feature',
        ];
    }

    private static function cardLimitRow(string $key, array $spec): array
    {
        return [
            'key'    => $key,
            'column' => $spec['column'],
            'label'  => __('pos.pcmp_card_unl_' . $key),
            'hint'   => null,
            'source' => 'limit',
        ];
    }

    /**
     * Card drift audit — folded into audit(), so scripts/plan-gate-check.php
     * blocks the deploy when a card claims something the plan does not grant.
     *
     * @param  Collection<int, PricingPlan>  $plans
     * @return array<int, string>
     */
    public static function auditCards(Collection $plans): array
    {
        $problems = [];
        $ordered = $plans->values();

        foreach ($ordered as $index => $plan) {
            $prev = $index > 0 ? $ordered[$index - 1] : null;

            // 1. The ladder itself: a costlier package may never lose a tick or
            //    tighten a limit. The card protects itself at render time
            //    (cardInherits() drops the "Everything in X, plus" line), but
            //    a ladder that slipped is still a pricing bug worth blocking.
            if ($prev) {
                foreach (self::FEATURE_ROWS as $key => $spec) {
                    if ($prev->{$spec['column']} && !$plan->{$spec['column']}) {
                        $problems[] = "{$plan->name} sits above {$prev->name} but '{$key}' ({$spec['column']}) "
                            . "is ON for {$prev->name} and OFF for {$plan->name} — the {$plan->name} card can no "
                            . "longer say \"Everything in {$prev->name}, plus\".";
                    }
                }
                foreach (self::LIMIT_ROWS as $key => $spec) {
                    if (self::cardIsUncapped($plan, $key, $spec)) {
                        continue;
                    }
                    $now = self::cardLimitValue($plan, $key, $spec);
                    $was = self::cardLimitValue($prev, $key, $spec);
                    if (self::isUnlimited($was) || (int) $now < (int) $was) {
                        $problems[] = "{$plan->name} sits above {$prev->name} but '{$key}' ({$spec['column']}) "
                            . 'drops from ' . (self::isUnlimited($was) ? 'Unlimited' : (int) $was)
                            . ' to ' . (int) $now . '.';
                    }
                }
            }

            // 2. "Included in every package" must hold on THIS plan's row — the
            //    card drops the bullet when it does not, so the drift would
            //    otherwise ship silently.
            foreach (self::INCLUDED_ROWS as $key => $spec) {
                if (isset($spec['column']) && empty($plan->{$spec['column']})) {
                    $problems[] = "{$plan->name} card lists '{$key}' as included but {$spec['column']} is OFF on that plan.";
                }
                if (isset($spec['unlimited']) && !self::isUnlimited($plan->{$spec['unlimited']})) {
                    $problems[] = "{$plan->name} card lists '{$key}' as unlimited but {$spec['unlimited']} is capped "
                        . "at {$plan->{$spec['unlimited']}}.";
                }
            }

            // 3. Every bullet must be backed by a column that is really ON.
            $numbers = self::cardBannedNumbers($plan);
            foreach (self::cardHighlights($plan, $prev) as $row) {
                if ($row['source'] === 'feature' && empty($plan->{$row['column']})) {
                    $problems[] = "{$plan->name} card claims '{$row['key']}' but {$row['column']} is OFF on that plan.";
                }
                if ($row['source'] === 'included' && $row['column'] && empty($plan->{$row['column']})) {
                    $problems[] = "{$plan->name} card lists '{$row['key']}' as included but {$row['column']} is OFF on that plan.";
                }
                if ($row['source'] === 'limit' && !self::isUnlimited($plan->{$row['column']}) && $row['key'] !== 'team') {
                    $problems[] = "{$plan->name} card claims unlimited '{$row['key']}' but {$row['column']} is capped at {$plan->{$row['column']}}.";
                }

                // 3. Labels must exist in all three languages and must never
                //    repeat one of the table's numbers.
                foreach (['en', 'rur', 'ur'] as $locale) {
                    $key = $row['source'] === 'included' ? 'pcmp_inc_' . $row['key']
                        : ($row['source'] === 'limit' ? 'pcmp_card_unl_' . $row['key'] : 'pcmp_' . $row['key']);
                    // Same no-fallback rule as the table rows: an English-only
                    // bullet must not pass as translated.
                    if (self::langLineMissing('pos.' . $key, $locale)) {
                        $problems[] = "Missing lang key pos.{$key} in [{$locale}] — every card bullet needs a name in all three languages.";
                    }
                }
                foreach ($numbers as $number) {
                    foreach ([$row['label'], (string) $row['hint']] as $text) {
                        if ($text !== '' && preg_match('/(?<!\d)' . preg_quote($number, '/') . '(?!\d)/', $text)) {
                            $problems[] = "{$plan->name} card bullet '{$row['key']}' repeats the number {$number}, "
                                . 'which the comparison table already prints — cards must stay number-free.';
                        }
                    }
                }
            }
        }

        // 4. The card views themselves must not grow hand-written claims back.
        foreach (self::CARD_VIEWS as $relative) {
            $path = base_path($relative);
            if (!is_file($path)) {
                $problems[] = "Package-card view '{$relative}' is missing — update PosPlanComparisonService::CARD_VIEWS.";
                continue;
            }
            $source = (string) file_get_contents($path);
            foreach (self::CARD_FORBIDDEN as $needle => $why) {
                if (str_contains($source, $needle)) {
                    $problems[] = "'{$relative}' references {$needle} — a package card may not print {$why}. "
                        . 'Use PosPlanComparisonService::cardHighlights().';
                }
            }
        }

        return $problems;
    }

    /** The numbers the comparison table prints for this plan — banned on its card. */
    private static function cardBannedNumbers(PricingPlan $plan): array
    {
        $numbers = [];
        foreach (self::LIMIT_ROWS as $key => $spec) {
            $raw = $key === 'team' ? self::teamAccountLimit($plan) : $plan->{$spec['column']};
            if (self::isUnlimited($raw)) {
                continue;
            }
            $numbers[] = (string) (int) $raw;
            $numbers[] = number_format((int) $raw);
        }

        return array_values(array_unique($numbers));
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
     * The half of the audit that needs NO database (Task 1385):
     *   1. every plan-gate column has a customer-facing row (or is declared
     *      COVERED_BY one), and
     *   2. every row label / declared hint resolves in English, Roman Urdu
     *      and Urdu.
     *
     * Both read constants and lang files only, so the normal test suite runs
     * them (tests/Unit/PosPlanComparisonNamingTest.php) and an unnamed new
     * gate — or a label missing from one language — fails the moment it is
     * added, instead of waiting for the staging DB and the pre-deploy check.
     * audit() still calls this first, so scripts/plan-gate-check.php keeps
     * blocking the deploy on exactly the same problems.
     *
     * The two arrays are test hooks: they let a test prove the guard really
     * bites without editing the constants. Nothing in the app passes them.
     *
     * @param  array<int, string>   $extraGateColumns  pretend these gate columns exist
     * @param  array<string, bool>  $extraRowSpecs     pretend these lang keys are rows
     *                                                 (key => does it need a _hint line)
     * @return array<int, string>
     */
    public static function auditNames(array $extraGateColumns = [], array $extraRowSpecs = []): array
    {
        $problems = [];

        // 1. Every gate column must have a customer-facing name.
        $named = array_column(self::FEATURE_ROWS, 'column');
        foreach (self::INCLUDED_ROWS as $spec) {
            if (isset($spec['column'])) {
                $named[] = $spec['column'];
            }
        }
        $gateColumns = array_merge(PosFeatureService::PLAN_GATES, ['restaurant_enabled'], $extraGateColumns);
        foreach ($gateColumns as $column) {
            if (in_array($column, $named, true) || isset(self::COVERED_BY[$column])) {
                continue;
            }
            // Add-on-sold gates: the customer-facing name is the add-on
            // catalogue entry, so demand THAT name in all three languages
            // instead of a pcmp_* row.
            if (isset(self::ADDON_COLUMNS[$column])) {
                $code = self::ADDON_COLUMNS[$column];
                if (!isset(PosAddonPricingService::ADDONS[$code])) {
                    $problems[] = "ADDON_COLUMNS maps '{$column}' to unknown add-on '{$code}' — "
                        . 'it must exist in PosAddonPricingService::ADDONS.';
                } elseif ((PosAddonPricingService::ADDONS[$code]['gate'] ?? null) !== $column) {
                    $problems[] = "ADDON_COLUMNS maps '{$column}' to add-on '{$code}' but that catalogue entry's "
                        . "gate is '" . (PosAddonPricingService::ADDONS[$code]['gate'] ?? 'NULL') . "' — the two maps disagree.";
                }
                foreach (['en', 'rur', 'ur'] as $locale) {
                    foreach (['addon_label_' . $code, 'addon_desc_' . $code] as $key) {
                        if (self::langLineMissing('pos.' . $key, $locale)) {
                            $problems[] = "Missing lang key pos.{$key} in [{$locale}] — every paid add-on needs a name in all three languages.";
                        }
                    }
                }
                continue;
            }
            $problems[] = "Gate column '{$column}' has no customer-facing row in PosPlanComparisonService "
                . '(add it to FEATURE_ROWS / INCLUDED_ROWS, declare it in COVERED_BY, or sell it via ADDON_COLUMNS).';
        }
        foreach (self::COVERED_BY as $column => $rowKey) {
            if (!isset(self::FEATURE_ROWS[$rowKey]) && !isset(self::INCLUDED_ROWS[$rowKey])) {
                $problems[] = "COVERED_BY maps '{$column}' to unknown row '{$rowKey}'.";
            }
        }
        // The catalogue side of the same coin: every sellable add-on's gate
        // column must be declared in ADDON_COLUMNS, or the audits above never
        // look at it and a re-enabled plan column would sneak past deploy.
        foreach (PosAddonPricingService::ADDONS as $code => $spec) {
            $gate = $spec['gate'] ?? null;
            if ($gate === null || (self::ADDON_COLUMNS[$gate] ?? null) !== $code) {
                $problems[] = "Add-on '{$code}' (gate '" . ($gate ?? 'NULL') . "') is missing from "
                    . 'PosPlanComparisonService::ADDON_COLUMNS — declare it so the plan-column audit covers it.';
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
        foreach ($extraRowSpecs as $key => $needsHint) {
            $rowSpecs[$key] = (bool) $needsHint;
        }
        foreach (['en', 'rur', 'ur'] as $locale) {
            foreach ($rowSpecs as $key => $needsHint) {
                foreach ($needsHint ? [$key, $key . '_hint'] : [$key] as $fullKey) {
                    if (self::langLineMissing('pos.' . $fullKey, $locale)) {
                        $problems[] = "Missing lang key pos.{$fullKey} in [{$locale}] — every comparison row needs a name in all three languages.";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Does this line really exist in THIS language?
     *
     * __() / trans() fall back to the app fallback locale (en), so a row named
     * in English and forgotten in Urdu would come back as the English text and
     * pass — the exact drift this audit exists to catch (the shop then reads an
     * English label, or a blank cell once the English line is renamed away).
     * The lookup therefore runs with fallback OFF: a locale with no line of its
     * own gets the key back instead of somebody else's translation.
     */
    private static function langLineMissing(string $key, string $locale): bool
    {
        $value = Lang::get($key, [], $locale, false);

        return !is_string($value) || trim($value) === '' || $value === $key;
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
        // 1 + 2. Gate coverage and three-language row names — no DB needed, so
        //        the normal test suite runs them too (Task 1385).
        $problems = self::auditNames();

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

        // 3b. Add-on-sold features may never ride a package: the table has no
        //     row for them, so a plan column left ON would grant a paid add-on
        //     silently — the shop pays for a package that includes something
        //     no surface admits to.
        foreach (self::ADDON_COLUMNS as $column => $code) {
            foreach ($plans as $plan) {
                if (!empty($plan->{$column})) {
                    $problems[] = "'{$code}' is sold as a paid add-on but {$plan->name} has {$column} ON — "
                        . 'switch it off on the plan row (add-ons never ride a package) or move it back into FEATURE_ROWS.';
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
        // 6. The package cards printed ABOVE the table (Task 1384) — a card
        //    may only repeat what the table can prove.
        foreach (self::auditCards($plans) as $problem) {
            $problems[] = $problem;
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
