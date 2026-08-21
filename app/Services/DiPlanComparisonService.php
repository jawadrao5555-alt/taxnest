<?php

namespace App\Services;

use App\Models\PricingPlan;
use Illuminate\Support\Collection;

/**
 * Digital Invoice package comparison — SINGLE SOURCE OF TRUTH (Task 1383).
 *
 * WHY THIS EXISTS
 * ---------------
 * PRA POS got this treatment in Task 1350; Digital Invoice was left out and
 * kept its hand-written bullet lists (pricing_plans.features JSON, which is
 * display-only — no PHP reads it for gating). Two things were invisible to a
 * buyer comparing the packages:
 *   • the four premium gates live in DiFeatureService::PLAN_FEATURES, and
 *     which package opens which was written nowhere the customer could see;
 *   • max_products is a real cap on the DI catalogue and appeared on no card.
 *
 * THE RULE: nothing below is a hand-typed tick.
 *   • Feature ticks come from DiFeatureService::planIncludes() — the same
 *     matrix lookup DiFeatureService::planAllows() ends on, so the table
 *     cannot promise a gate the panel refuses.
 *   • Limit numbers come from the pricing_plans columns the DI middleware
 *     (CheckPlanLimit) and PlanLimitService really read.
 * Add a key to DiFeatureService::GATES and it must be named here or
 * scripts/plan-gate-check.php blocks the deploy.
 *
 * DI HAS NO COLUMN GATES. PosFeatureService reads boolean plan columns; the DI
 * premium tier is a name-keyed matrix instead, so FEATURE_ROWS below carries
 * gate KEYS, not column names. That is the only structural difference from
 * PosPlanComparisonService / FbrPosPlanComparisonService.
 *
 * WHY PLAIN ENGLISH AND NOT LANG KEYS: /digital-invoice is a public English
 * marketing page and the DI panel has no language switcher, so labels live
 * here as strings. audit() still refuses an empty one.
 */
class DiPlanComparisonService
{
    /**
     * Limit rows, in display order.
     *   column — the pricing_plans column holding the number
     *   label / hint — customer-facing name and the one-liner under it
     */
    public const LIMIT_ROWS = [
        'invoices' => [
            'column' => 'invoice_limit',
            'label'  => 'Invoices',
            // Deliberately not "per month": PlanLimitService::canCreateInvoice()
            // compares the plan cap against every invoice the company has ever
            // created, with no date window. Saying "per month" here would be
            // exactly the drift this table exists to remove.
            'hint'   => 'Counted over the life of the account, not per month — the panel stops new invoices once the total is reached.',
        ],
        'users' => [
            'column' => 'user_limit',
            'label'  => 'User accounts',
            'hint'   => 'Staff who get their own login. The owner account is free.',
        ],
        'branches' => [
            'column' => 'branch_limit',
            'label'  => 'Branches',
            'hint'   => 'Separate business locations invoicing under one account.',
        ],
        'products' => [
            'column' => 'max_products',
            'label'  => 'Products',
            'hint'   => 'Saved items you can pick from when raising an invoice.',
        ],
    ];

    /**
     * Tick/cross rows, in display order. Each 'gate' is a key in
     * DiFeatureService::GATES — resolved through planIncludes(), never by
     * re-reading the matrix here.
     */
    public const FEATURE_ROWS = [
        'recurring_invoices' => [
            'gate'  => 'recurring_invoices',
            'label' => 'Recurring invoices',
            'hint'  => 'Set an invoice to repeat on a schedule instead of raising it by hand every time.',
        ],
        'white_label' => [
            'gate'  => 'white_label',
            'label' => 'White-label branding',
            'hint'  => 'Your own logo and colours on invoice PDFs and share pages.',
        ],
        'ai_reader' => [
            'gate'  => 'ai_reader',
            'label' => 'AI document reader',
            'hint'  => 'Drop in a purchase order or a scanned bill and let it fill the invoice for you.',
        ],
        'public_api' => [
            'gate'  => 'public_api',
            'label' => 'Public API access',
            'hint'  => 'Raise invoices straight from your own software.',
        ],
    ];

    /**
     * "Included in every package" block — product-wide facts that no plan
     * column and no DI gate restricts. Nothing here may carry a 'column' or a
     * 'gate' unless it is genuinely on for every package; audit() checks it.
     */
    public const INCLUDED_ROWS = [
        'fbr_submission' => ['label' => 'Live FBR submission with the invoice number and QR on every invoice'],
        'validation'     => ['label' => 'Validation and compliance scoring before anything reaches FBR'],
        'sandbox'        => ['label' => 'Sandbox mode to test the full payload before going live'],
        'pdf_share'      => ['label' => 'Invoice PDF, print and a share link for the buyer'],
        'audit_pack'     => ['label' => 'Downloadable FBR audit pack of your submitted invoices'],
        'import'         => ['label' => 'Bulk invoice import from Excel'],
        'consultant'     => ['label' => 'Consultant console access for your tax advisor'],
        'debit_credit'   => ['label' => 'Debit and credit notes against a submitted invoice'],
    ];

    /** Premium is the flagged column on both the cards and the table. */
    public const POPULAR_PLAN = 'Premium';

    /** The paid DI packages, cheapest first — same query the cards use. */
    public static function plans(): Collection
    {
        return PricingPlan::where('is_trial', false)
            ->where('product_type', 'di')
            ->orderBy('price')
            ->get();
    }

    /** null / any negative value means "no cap" everywhere in the codebase. */
    public static function isUnlimited($value): bool
    {
        return $value === null || (int) $value < 0;
    }

    /**
     * The DI seat cap the customer actually hits.
     *
     * POST /company/users is guarded TWICE and the two guards read different
     * columns, so neither one on its own is the honest number:
     *   • CheckPlanLimit (plan.limit:users route middleware) reads max_users
     *     and counts every user row;
     *   • CompanyUserController::store then calls
     *     PlanLimitService::canAddUser(), which reads user_limit and counts
     *     the ACTIVE users.
     * Whichever is tighter is the one that refuses the seat, so the table
     * prints the tighter of the two. A NULL / negative column is no cap.
     */
    public static function userSeatLimit(?PricingPlan $plan): ?int
    {
        if (!$plan) {
            return null;
        }

        $caps = [];
        foreach (['user_limit', 'max_users'] as $column) {
            $raw = $plan->{$column};
            if (!self::isUnlimited($raw)) {
                $caps[] = (int) $raw;
            }
        }

        return $caps === [] ? null : min($caps);
    }

    /**
     * Column header data for each package.
     *
     * $withSignup adds the landing-page buying block (Task 1483). Digital
     * Invoice quotes a MONTHLY price and lets the visitor switch the billing
     * cycle, so the three price strings also ship an Alpine expression that
     * the landing's cycle switch re-evaluates (calcMonthly / calcPrice live in
     * the x-data the table is rendered inside). The server-rendered string is
     * the monthly default, so the heading is correct before Alpine boots and
     * on the panel surface, which never passes the flag.
     */
    public static function planColumns(Collection $plans, ?int $currentPlanId = null, bool $withSignup = false): array
    {
        return $plans->map(function (PricingPlan $plan) use ($currentPlanId, $withSignup) {
            $col = [
                'id'      => (int) $plan->id,
                'name'    => $plan->name,
                'price'   => 'Rs ' . number_format((float) $plan->sale_price) . '/mo',
                'popular' => $plan->name === self::POPULAR_PLAN,
                'current' => $currentPlanId !== null && (int) $plan->id === $currentPlanId,
            ];

            if (!$withSignup) {
                return $col;
            }

            $sale = (float) $plan->sale_price;
            $full = (float) $plan->price;

            $col['price']        = 'Rs ' . number_format($sale);
            $col['price_period'] = '/ month';
            $col['price_x']      = "'Rs ' + calcMonthly({$sale}).toLocaleString()";

            if ($sale < $full) {
                $col['price_compare']   = 'Rs ' . number_format($full);
                $col['price_compare_x'] = "'Rs ' + calcMonthly({$full}).toLocaleString()";
                $col['sale_badge']      = $plan->sale_badge;
            }

            // Monthly billing has nothing extra to say; the other three cycles
            // show what actually leaves the bank each time.
            $col['price_note']   = '';
            $col['price_note_x'] = "cycle === 'monthly' ? '' : 'Billed Rs ' + calcPrice({$sale}).toLocaleString()";

            $col['cta_url']   = route('register', ['plan' => $plan->name], false);
            $col['cta_label'] = 'Choose';

            return $col;
        })->all();
    }

    /**
     * The whole grid: two sections, every cell derived from the plan row or
     * the DI gate matrix.
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
                'gate'   => $spec['gate'],
                'label'  => $spec['label'],
                'hint'   => $spec['hint'],
                'values' => $plans->map(fn (PricingPlan $plan) => DiFeatureService::planIncludes($plan, $spec['gate']))->all(),
            ];
        }

        return [
            ['key' => 'limits',   'title' => 'What you get', 'rows' => $limitRows],
            ['key' => 'features', 'title' => 'Features',     'rows' => $featureRows],
        ];
    }

    /** Labels for the "included in every package" block. */
    public static function includedItems(): array
    {
        return array_values(array_map(fn (array $spec) => $spec['label'], self::INCLUDED_ROWS));
    }

    /** The limit behind a comparison row (seats come from both DI guards). */
    private static function limitValue(PricingPlan $plan, string $key, array $spec)
    {
        return $key === 'users' ? self::userSeatLimit($plan) : $plan->{$spec['column']};
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

        // 1. Every DI gate must have a customer-facing name...
        $named = array_column(self::FEATURE_ROWS, 'gate');
        foreach (DiFeatureService::GATES as $gate) {
            if (!in_array($gate, $named, true)) {
                $problems[] = "DI gate '{$gate}' has no customer-facing row in DiPlanComparisonService "
                    . '(add it to FEATURE_ROWS).';
            }
        }
        // ...and no row may sell something that is not a gate at all.
        foreach ($named as $gate) {
            if (!in_array($gate, DiFeatureService::GATES, true)) {
                $problems[] = "Row gate '{$gate}' is not in DiFeatureService::GATES — nothing enforces it, "
                    . 'so the table must not sell it.';
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
                if (isset($spec['gate']) && !DiFeatureService::planIncludes($plan, $spec['gate'])) {
                    $problems[] = "'{$key}' is listed as included in every package but {$plan->name} does not open the '{$spec['gate']}' gate.";
                }
                if (isset($spec['column']) && empty($plan->{$spec['column']})) {
                    $problems[] = "'{$key}' is listed as included in every package but {$plan->name} has {$spec['column']} OFF.";
                }
                if (isset($spec['unlimited']) && !self::isUnlimited($plan->{$spec['unlimited']})) {
                    $problems[] = "'{$key}' is listed as unlimited in every package but {$plan->name} caps {$spec['unlimited']} at {$plan->{$spec['unlimited']}}.";
                }
            }
        }

        // 4. Seats: the table must never print a number one of the two DI
        //    guards would still refuse.
        foreach ($plans as $plan) {
            $shown = self::userSeatLimit($plan);
            if ($shown === null) {
                foreach (['user_limit', 'max_users'] as $column) {
                    if (!self::isUnlimited($plan->{$column})) {
                        $problems[] = "{$plan->name}: the user row says Unlimited but {$column} caps it at {$plan->{$column}}.";
                    }
                }
                continue;
            }
            foreach (['user_limit', 'max_users'] as $column) {
                if (!self::isUnlimited($plan->{$column}) && (int) $plan->{$column} < $shown) {
                    $problems[] = "{$plan->name}: the user row shows {$shown} but {$column} refuses at {$plan->{$column}}.";
                }
            }
        }

        // 5. The table's numbers and ticks against the deploy script's own
        //    independently written expectations.
        $byName = $plans->keyBy('name');
        foreach ($expectedLimits as $planName => $expected) {
            $plan = $byName->get($planName);
            if (!$plan) {
                $problems[] = "Expected DI plan '{$planName}' not found.";
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
                    $problems[] = "{$planName} / {$rowKey}: table shows {$got} but the package matrix expects {$want}.";
                }
            }
        }
        foreach ($expectedFeatures as $planName => $expected) {
            $plan = $byName->get($planName);
            if (!$plan) {
                continue;
            }
            foreach ($expected as $rowKey => $want) {
                $gate = self::FEATURE_ROWS[$rowKey]['gate'] ?? null;
                if (!$gate) {
                    $problems[] = "Unknown feature row '{$rowKey}' in the expected matrix.";
                    continue;
                }
                $got = DiFeatureService::planIncludes($plan, $gate);
                if ($got !== (bool) $want) {
                    $problems[] = "{$planName} / {$rowKey}: table would show " . ($got ? 'tick' : 'cross')
                        . " (from the '{$gate}' gate) but the package matrix expects " . ($want ? 'tick' : 'cross') . '.';
                }
            }
        }

        return $problems;
    }
}
