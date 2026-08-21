<?php

namespace App\Services;

use App\Models\PricingPlan;
use Illuminate\Support\Collection;

/**
 * FBR POS package comparison — SINGLE SOURCE OF TRUTH (Task 1383).
 *
 * WHY THIS EXISTS
 * ---------------
 * PRA POS got this treatment in Task 1350; FBR POS was deliberately left out
 * and kept its hand-written bullet lists (pricing_plans.features JSON, which
 * is display-only — no PHP reads it for gating). The same drift followed:
 *   • the Starter card promised "2 Team Accounts" while user_limit = 1, and
 *     the Business card promised 5 while user_limit = 3;
 *   • riders, Hazri and WhatsApp bill sharing are gate columns the FBR panel
 *     really reads, and appeared on no card at all;
 *   • max_products (100 / 500) is a real cap on FBR POS — unlike PRA POS,
 *     which went unlimited in Aug 2026 — and nothing said so.
 *
 * THE RULE: nothing below is a hand-typed tick. Every cell is read straight
 * off the pricing_plans row that actually opens or closes the feature. Add a
 * gate column to GATE_COLUMNS and it must be named here (or declared in
 * COVERED_BY) or scripts/plan-gate-check.php blocks the deploy.
 *
 * WHY PLAIN ENGLISH AND NOT pos.* LANG KEYS
 * -----------------------------------------
 * The PRA table also renders inside the POS panel, so its labels are lang keys
 * in three languages. This one only renders on /fbr-pos-landing, a public
 * English marketing page outside the SetPosLocale prefix. Adding ~40 keys to
 * lang/{en,rur,ur}/pos.php purely for a page that never switches language
 * would be dead weight the three-way key-sync test has to carry, so the labels
 * live here as strings. audit() still refuses an empty one.
 */
class FbrPosPlanComparisonService
{
    /**
     * Limit rows, in display order.
     *   column — the pricing_plans column holding the number
     *   label / hint — customer-facing name and the one-liner under it
     */
    public const LIMIT_ROWS = [
        'bills' => [
            'column' => 'invoice_limit',
            'label'  => 'FBR bills per month',
            'hint'   => 'Counted per calendar month and reset on the 1st. Held and unpaid orders do not count.',
        ],
        'team' => [
            'column' => 'user_limit',
            'label'  => 'Team accounts',
            'hint'   => 'Cashiers, managers and waiters who get their own login. The owner account is free.',
        ],
        'branches' => [
            'column' => 'branch_limit',
            'label'  => 'Branches',
            'hint'   => 'Separate shops under one licence, each with its own stock and reports.',
        ],
        'counters' => [
            'column' => 'max_terminals',
            'label'  => 'Billing counters',
            'hint'   => 'Tills that can bill at the same time in one branch.',
        ],
        'products' => [
            'column' => 'max_products',
            'label'  => 'Products',
            'hint'   => 'Items in the catalogue. FBR POS keeps a product cap on the lower packages.',
        ],
    ];

    /**
     * Tick/cross rows, in display order. Each value is the boolean column on
     * pricing_plans that PosFeatureService::planAllows() reads for an fbrpos
     * company.
     */
    public const FEATURE_ROWS = [
        'reports' => [
            'column' => 'reports_enabled',
            'label'  => 'Sales reports & day close',
            'hint'   => 'Daily, item-wise and counter-wise reports with a printable day-close.',
        ],
        'excel' => [
            'column' => 'excel_enabled',
            'label'  => 'Excel import & export',
            'hint'   => 'Bulk-load the catalogue and pull sales out as a real .xlsx file.',
        ],
        'offline' => [
            'column' => 'offline_enabled',
            'label'  => 'Offline billing',
            'hint'   => 'Keep billing when the internet drops; bills submit to FBR once it is back.',
        ],
        'khata' => [
            'column' => 'khata_enabled',
            'label'  => 'Customer khata (udhaar)',
            'hint'   => 'Credit bills, customer ledger and wasooli against the outstanding balance.',
        ],
        'deals' => [
            'column' => 'deals_enabled',
            'label'  => 'Deals & combos',
            'hint'   => 'Fixed-price bundles that still deduct every component from stock.',
        ],
        'loyalty' => [
            'column' => 'loyalty_enabled',
            'label'  => 'Customer loyalty points',
            'hint'   => null,
        ],
        'kot' => [
            'column' => 'kot_enabled',
            'label'  => 'Kitchen slips (KOT)',
            'hint'   => 'Print or send the order to the kitchen the moment it is taken.',
        ],
        'analytics' => [
            'column' => 'analytics_enabled',
            'label'  => 'Business analytics',
            'hint'   => 'Trends, best sellers and profit views on top of the plain reports.',
        ],
    ];

    /**
     * "Included in every package" block. Kept OUT of the tick/cross grid on
     * purpose — a column of three ticks teaches the reader nothing and waters
     * down the rows that do differ.
     *
     *   column    — boolean plan column that must be ON for every FBR POS plan
     *   unlimited — numeric plan column that must be unlimited on every plan
     *   (neither) — a product-wide fact that no plan column gates
     *
     * audit() fails if a listed column is off / capped anywhere, so "included"
     * can never quietly become "not included".
     */
    public const INCLUDED_ROWS = [
        'fbr_live'      => ['label' => 'Live FBR submission — fiscal invoice number and QR on every receipt'],
        'inventory'     => ['column' => 'inventory_enabled', 'label' => 'Inventory, stock and purchase entry'],
        'riders'        => ['column' => 'riders_enabled',    'label' => 'Delivery riders and rider khata'],
        'hazri'         => ['column' => 'hazri_enabled',     'label' => 'Staff Hazri (attendance) tracking'],
        'whatsapp'      => ['column' => 'whatsapp_enabled',  'label' => 'Send the bill on WhatsApp'],
        'thermal'       => ['label' => '80mm and 58mm thermal receipts with silent printing'],
        'mobile_app'    => ['label' => 'Android app for the whole team'],
        'languages'     => ['label' => 'English, Roman Urdu and Urdu'],
    ];

    /**
     * Gate columns deliberately NOT given their own row, and why. Empty for
     * now: every column the FBR panel gates on is named above.
     */
    public const COVERED_BY = [];

    /**
     * Every pricing_plans column the FBR POS product really gates on.
     *
     * Deliberately NOT PosFeatureService::PLAN_GATES — that list is the PRA
     * superset. Columns left out because no /fbr-pos/ surface reads them:
     *   restaurant_enabled     — PRA masks its kitchen flags behind this;
     *                            FBR POS reads kot_enabled directly.
     *   qr_menu_enabled        — no FBR QR-menu route.
     *   rider_tracking_enabled — live rider tracking is a PRA-only module.
     *   caller_id_enabled      — Caller ID is wired to /pos/ routes only.
     *   custom_access_enabled  — PosAccessService honours a stored custom set
     *                            for FBR staff, but the FBR team page has no UI
     *                            to create one, so there is nothing to sell.
     * Wire any of those into an /fbr-pos/ route and it belongs in this list —
     * the deploy check then demands a customer-facing row for it.
     */
    public const GATE_COLUMNS = [
        'inventory_enabled',
        'offline_enabled',
        'excel_enabled',
        'khata_enabled',
        'reports_enabled',
        'deals_enabled',
        'loyalty_enabled',
        'kot_enabled',
        'analytics_enabled',
        'riders_enabled',
        'hazri_enabled',
        'whatsapp_enabled',
    ];

    /** Business is the flagged column on both the cards and the table. */
    public const POPULAR_PLAN = 'Business';

    /** Annual licensing: 12 months with the 6% discount the landing advertises. */
    public const ANNUAL_MONTHS = 12;
    public const ANNUAL_DISCOUNT = 0.94;

    /** The single FBR POS surface that renders package cards above this table. */
    /**
     * Empty since Task 1483: the FBR landing's package cards were deleted and
     * the comparison table became the buying surface, so there is no card view
     * left to scan. The ladder-integrity half of auditCards() (which is what
     * scripts/plan-gate-check.php really leans on) still runs, and
     * cardHighlights() still backs it. Add a view back here the day FBR grows
     * package cards again.
     */
    public const CARD_VIEWS = [];

    /**
     * Patterns a package card may NOT contain: the display-only features JSON
     * (nothing gates on it) and any limit the comparison table already prints.
     * Numbers live in exactly ONE place — the table.
     */
    public const CARD_FORBIDDEN = [
        '->features'             => 'the display-only pricing_plans.features JSON (no gate reads it)',
        'getInvoiceLimitDisplay' => 'the bills-per-month number (comparison table owns it)',
        'getUserLimitDisplay'    => 'the team-account number (comparison table owns it)',
        'getBranchLimitDisplay'  => 'the branch number (comparison table owns it)',
        'invoice_limit'          => 'the bills-per-month column',
        'user_limit'             => 'the team-account column',
        'branch_limit'           => 'the branch column',
        'max_terminals'          => 'the counters column',
        'max_products'           => 'the products column',
    ];

    /** The paid FBR POS packages, cheapest first — same query the cards use. */
    public static function plans(): Collection
    {
        return PricingPlan::where('is_trial', false)
            ->where('product_type', 'fbrpos')
            ->orderBy('price')
            ->get();
    }

    /** null / any negative value means "no cap" everywhere in the codebase. */
    public static function isUnlimited($value): bool
    {
        return $value === null || (int) $value < 0;
    }

    /**
     * The ONE team-account number — owned by the gate, read by the table
     * (never the other way round). See PlanLimitService::teamAccountLimit().
     */
    public static function teamAccountLimit(?PricingPlan $plan): ?int
    {
        return PlanLimitService::teamAccountLimit($plan);
    }

    /** The yearly figure the price cards print, so the table cannot show another. */
    public static function annualPrice(PricingPlan $plan, bool $beforeDiscount = false): int
    {
        $monthly = (float) ($beforeDiscount ? $plan->price : $plan->sale_price);

        return (int) round($monthly * self::ANNUAL_MONTHS * self::ANNUAL_DISCOUNT);
    }

    /**
     * Column header data for each package.
     *
     * $withSignup adds the landing-page buying block (Task 1483): FBR POS is
     * licensed by the YEAR (monthly price × 12 with the 6% annual discount
     * already baked in by annualPrice()), and the button carries the package
     * into /fbr-pos/register. Panel surfaces call this without the flag.
     */
    public static function planColumns(Collection $plans, ?int $currentPlanId = null, bool $withSignup = false): array
    {
        return $plans->map(function (PricingPlan $plan) use ($currentPlanId, $withSignup) {
            $col = [
                'id'      => (int) $plan->id,
                'name'    => $plan->name,
                'price'   => 'Rs ' . number_format(self::annualPrice($plan)) . '/yr',
                'popular' => $plan->name === self::POPULAR_PLAN,
                'current' => $currentPlanId !== null && (int) $plan->id === $currentPlanId,
            ];

            if (!$withSignup) {
                return $col;
            }

            // Headline drops the "/yr" suffix — the unit gets its own line.
            $col['price']        = 'Rs ' . number_format(self::annualPrice($plan));
            $col['price_period'] = '/ year';

            if ((float) $plan->sale_price < (float) $plan->price) {
                $col['price_compare'] = 'Rs ' . number_format(self::annualPrice($plan, true));
                $col['sale_badge']    = $plan->sale_badge;
            }

            $col['cta_url']   = route('fbrpos.register', ['plan' => $plan->name], false);
            $col['cta_label'] = 'Choose';

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
                'label'  => $spec['label'],
                'hint'   => $spec['hint'],
                'values' => $plans->map(function (PricingPlan $plan) use ($key, $spec) {
                    $raw = self::limitValue($plan, $key, $spec);

                    return [
                        'unlimited' => self::isUnlimited($raw),
                        'text'      => self::isUnlimited($raw) ? 'Unlimited' : number_format((int) $raw),
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
                'label'  => $spec['label'],
                'hint'   => $spec['hint'],
                'values' => $plans->map(fn (PricingPlan $plan) => (bool) $plan->{$spec['column']})->all(),
            ];
        }

        return [
            ['key' => 'limits',   'title' => 'What you get', 'rows' => $limitRows],
            ['key' => 'features', 'title' => 'Features',     'rows' => $featureRows],
        ];
    }

    /**
     * Package-card bullets — SAME source as the table.
     *
     * The FBR cards used to carry the hand-written features JSON, which is how
     * "2 Team Accounts" ended up above a plan row that grants 1. Now:
     *   • the cheapest package lists what every package includes;
     *   • every package above it lists ONLY what it newly unlocks over the
     *     package below — read off the same column the table's tick reads;
     *   • a capped limit that becomes uncapped gets a claim-free "Unlimited …"
     *     line (still read off the column, still without a number).
     * No card ever prints bill / team / branch / counter / product numbers —
     * those belong to the table alone, so the two cannot drift apart.
     *
     * The "Everything in <previous>, plus:" framing is verified at render time
     * by cardInherits(), because plan rows are live-editable from the admin
     * panel; when it no longer holds the card falls back to a standalone list.
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
                'label'  => $spec['label'],
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
            if ((int) self::limitValue($plan, $key, $spec) < (int) self::limitValue($prevPlan, $key, $spec)) {
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
     * every package really carries the floor.
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
    private static function limitValue(PricingPlan $plan, string $key, array $spec)
    {
        return $key === 'team' ? self::teamAccountLimit($plan) : $plan->{$spec['column']};
    }

    private static function cardIsUncapped(PricingPlan $plan, string $key, array $spec): bool
    {
        return self::isUnlimited(self::limitValue($plan, $key, $spec));
    }

    private static function cardFeatureRow(string $key, array $spec): array
    {
        return [
            'key'    => $key,
            'column' => $spec['column'],
            'label'  => $spec['label'],
            'hint'   => $spec['hint'],
            'source' => 'feature',
        ];
    }

    /** Wording for a cap that has lifted — deliberately number-free. */
    private static function cardLimitRow(string $key, array $spec): array
    {
        $unlimited = [
            'bills'    => 'Unlimited FBR bills every month',
            'team'     => 'Unlimited team accounts',
            'branches' => 'Unlimited branches',
            'counters' => 'Unlimited billing counters',
            'products' => 'Unlimited products',
        ];

        return [
            'key'    => $key,
            'column' => $spec['column'],
            'label'  => $unlimited[$key] ?? ('Unlimited ' . $spec['label']),
            'hint'   => null,
            'source' => 'limit',
        ];
    }

    /** Labels for the "included in every package" block. */
    public static function includedItems(): array
    {
        return array_values(array_map(fn (array $spec) => $spec['label'], self::INCLUDED_ROWS));
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
            //    tighten a limit.
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
                    $now = self::limitValue($plan, $key, $spec);
                    $was = self::limitValue($prev, $key, $spec);
                    if (self::isUnlimited($was) || (int) $now < (int) $was) {
                        $problems[] = "{$plan->name} sits above {$prev->name} but '{$key}' ({$spec['column']}) "
                            . 'drops from ' . (self::isUnlimited($was) ? 'Unlimited' : (int) $was)
                            . ' to ' . (int) $now . '.';
                    }
                }
            }

            // 2. "Included in every package" must hold on THIS plan's row.
            foreach (self::INCLUDED_ROWS as $key => $spec) {
                if (isset($spec['column']) && empty($plan->{$spec['column']})) {
                    $problems[] = "{$plan->name} card lists '{$key}' as included but {$spec['column']} is OFF on that plan.";
                }
                if (isset($spec['unlimited']) && !self::isUnlimited($plan->{$spec['unlimited']})) {
                    $problems[] = "{$plan->name} card lists '{$key}' as unlimited but {$spec['unlimited']} is capped "
                        . "at {$plan->{$spec['unlimited']}}.";
                }
            }

            // 3. Every bullet must be backed by a column that is really ON, and
            //    must never repeat a number the table already prints.
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
                if (trim((string) $row['label']) === '') {
                    $problems[] = "{$plan->name} card bullet '{$row['key']}' has no customer-facing name.";
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

        // 4. The card view itself must not grow hand-written claims back.
        foreach (self::CARD_VIEWS as $relative) {
            $path = base_path($relative);
            if (!is_file($path)) {
                $problems[] = "Package-card view '{$relative}' is missing — update FbrPosPlanComparisonService::CARD_VIEWS.";
                continue;
            }
            $source = (string) file_get_contents($path);
            foreach (self::CARD_FORBIDDEN as $needle => $why) {
                if (str_contains($source, $needle)) {
                    $problems[] = "'{$relative}' references {$needle} — a package card may not print {$why}. "
                        . 'Use FbrPosPlanComparisonService::cardHighlights().';
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
            $raw = self::limitValue($plan, $key, $spec);
            if (self::isUnlimited($raw)) {
                continue;
            }
            $numbers[] = (string) (int) $raw;
            $numbers[] = number_format((int) $raw);
        }

        return array_values(array_unique($numbers));
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
        foreach (self::GATE_COLUMNS as $column) {
            if (in_array($column, $named, true) || isset(self::COVERED_BY[$column])) {
                continue;
            }
            $problems[] = "Gate column '{$column}' has no customer-facing row in FbrPosPlanComparisonService "
                . '(add it to FEATURE_ROWS / INCLUDED_ROWS, or declare it in COVERED_BY).';
        }
        foreach (self::COVERED_BY as $column => $rowKey) {
            if (!isset(self::FEATURE_ROWS[$rowKey]) && !isset(self::INCLUDED_ROWS[$rowKey])) {
                $problems[] = "COVERED_BY maps '{$column}' to unknown row '{$rowKey}'.";
            }
        }
        // A named column that is not a real FBR gate would be a promise nothing
        // enforces — the reverse drift, just as dishonest.
        foreach (array_unique($named) as $column) {
            if (!in_array($column, self::GATE_COLUMNS, true)) {
                $problems[] = "Row column '{$column}' is not in FbrPosPlanComparisonService::GATE_COLUMNS — "
                    . 'the FBR POS panel does not gate on it, so the table must not sell it.';
            }
        }

        // 2. Every row needs a non-empty customer-facing name.
        $labels = [];
        foreach (self::LIMIT_ROWS as $key => $spec) {
            $labels['limit:' . $key] = $spec['label'];
        }
        foreach (self::FEATURE_ROWS as $key => $spec) {
            $labels['feature:' . $key] = $spec['label'];
        }
        foreach (self::INCLUDED_ROWS as $key => $spec) {
            $labels['included:' . $key] = $spec['label'] ?? '';
        }
        foreach ($labels as $key => $label) {
            if (!is_string($label) || trim($label) === '') {
                $problems[] = "Comparison row '{$key}' has no customer-facing name.";
            }
        }

        // 3. "Included in every package" must actually be true on every plan.
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
        //    has to come back from the same resolver the FBR gate calls.
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
                $problems[] = "Expected FBR POS plan '{$planName}' not found.";
                continue;
            }
            foreach ($expected as $rowKey => $want) {
                $spec = self::LIMIT_ROWS[$rowKey] ?? null;
                if (!$spec) {
                    $problems[] = "Unknown limit row '{$rowKey}' in the expected matrix.";
                    continue;
                }
                $raw = self::limitValue($plan, $rowKey, $spec);
                $got = self::isUnlimited($raw) ? 'Unlimited' : (int) $raw;
                if ($got !== $want) {
                    $problems[] = "{$planName} / {$rowKey}: table shows {$got} (from {$spec['column']}) "
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

        // 6. The package cards printed ABOVE the table — a card may only repeat
        //    what the table can prove.
        foreach (self::auditCards($plans) as $problem) {
            $problems[] = $problem;
        }

        return $problems;
    }
}
