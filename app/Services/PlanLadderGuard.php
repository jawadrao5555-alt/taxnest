<?php

namespace App\Services;

use App\Models\PricingPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PACKAGE LADDER GUARD (Task 1455).
 *
 * WHY THIS EXISTS
 * ---------------
 * The SaaS-admin plan editor writes price / invoice_limit / max_terminals /
 * max_users / max_products / inventory_enabled / reports_enabled straight to
 * pricing_plans with no ladder check. An owner could therefore reprice a
 * package so the ladder reorders, cap products on a costlier package, or
 * switch Reports off on a higher plan — and the only visible effect was that
 * the public card quietly stopped saying "Everything in <previous>, plus:"
 * and the comparison table started showing a dropped tick. Nobody was told.
 * The audit that catches it (scripts/plan-gate-check.php) only runs at deploy
 * time, which may be weeks later.
 *
 * This runs the SAME audit the deploy gate runs, at the moment of the save.
 *
 * ONLY NEW PROBLEMS BLOCK
 * -----------------------
 * A ladder can already be imperfect for reasons that have nothing to do with
 * the row being edited (a known pricing decision still pending, say). Blocking
 * every save until the whole ladder is clean would make the editor unusable,
 * so the guard audits the ladder twice — as stored, and as it would be after
 * the save — and reports only what the save would ADD. The list page shows the
 * pre-existing problems separately, so an old break is never invisible either.
 *
 * FAIL-OPEN, ON PURPOSE
 * ---------------------
 * If the audit itself throws (a half-migrated column on some database), the
 * guard logs it and allows the save. The deploy gate is the backstop; an admin
 * panel that cannot save a price because an audit crashed is worse.
 */
class PlanLadderGuard
{
    /** product_type => the comparison service that owns that ladder. */
    public const SERVICES = [
        'pos'    => PosPlanComparisonService::class,
        'fbrpos' => FbrPosPlanComparisonService::class,
        'di'     => DiPlanComparisonService::class,
    ];

    /** product_type => what the admin calls it. */
    public const LABELS = [
        'pos'    => 'PRA POS',
        'fbrpos' => 'FBR POS',
        'di'     => 'Digital Invoice',
    ];

    public static function supports(?string $productType): bool
    {
        return is_string($productType) && isset(self::SERVICES[$productType]);
    }

    /**
     * Problems this ladder has right now, exactly as stored.
     *
     * @return array<int, string>
     */
    public static function currentProblems(string $productType): array
    {
        if (!self::supports($productType)) {
            return [];
        }

        $service = self::SERVICES[$productType];

        return self::run($productType, $service::plans());
    }

    /**
     * Every product ladder's current problems, keyed by product_type.
     * Empty ladders are dropped, so the caller can just check for emptiness.
     *
     * @return array<string, array<int, string>>
     */
    public static function allCurrentProblems(): array
    {
        $out = [];
        foreach (array_keys(self::SERVICES) as $type) {
            $problems = self::currentProblems($type);
            if ($problems) {
                $out[$type] = $problems;
            }
        }

        return $out;
    }

    /**
     * What would this save break that is not already broken?
     *
     * Checks the ladder the row is going INTO and, when a plan is being moved
     * between products, the ladder it is leaving — removing a middle package
     * re-pairs the ones around it, which can break inheritance too.
     *
     * @param  array<string, mixed>  $attributes  the row exactly as it would be written
     * @param  int|null  $planId  null when creating
     * @return array<int, string>
     */
    public static function newProblems(array $attributes, ?int $planId = null): array
    {
        $target = $attributes['product_type'] ?? null;
        $source = $planId !== null ? PricingPlan::find($planId)?->product_type : null;

        $problems = [];
        foreach (array_unique(array_filter([$target, $source])) as $type) {
            if (!self::supports($type)) {
                continue;
            }
            $service = self::SERVICES[$type];
            $before  = self::run($type, $service::plans());
            $after   = self::run($type, self::prospective($type, $attributes, $planId));
            foreach (array_diff($after, $before) as $problem) {
                $problems[] = count(self::SERVICES) > 1 && $source !== null && $source !== $target
                    ? (self::LABELS[$type] ?? $type) . ': ' . $problem
                    : $problem;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * The ladder as it would stand after the save: the edited row lifted out,
     * refilled with the new values, and dropped back in at its new price.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function prospective(string $productType, array $attributes, ?int $planId): Collection
    {
        $service = self::SERVICES[$productType];

        /** @var Collection<int, PricingPlan> $rows */
        $rows = $service::plans()
            ->reject(fn (PricingPlan $plan) => $planId !== null && (int) $plan->id === (int) $planId)
            ->values();

        // A row only joins THIS ladder when the save leaves it on this product.
        if (($attributes['product_type'] ?? null) === $productType) {
            $existing = $planId !== null ? PricingPlan::find($planId) : null;
            $row = $existing ? clone $existing : new PricingPlan();
            $row->forceFill($attributes);

            // Trial rows never appear on a package ladder (plans() excludes them).
            if (!$row->is_trial) {
                $rows->push($row);
            }
        }

        return $rows
            ->sortBy(fn (PricingPlan $plan) => (float) $plan->price)
            ->values();
    }

    /**
     * Every pricing_plans column the comparison service audits, read off the
     * service's own row maps so a new row can never be missed here.
     *
     * @return array<int, string>
     */
    private static function auditedColumns(string $service): array
    {
        $columns = [];
        foreach (['LIMIT_ROWS', 'FEATURE_ROWS', 'INCLUDED_ROWS'] as $constant) {
            if (!defined($service . '::' . $constant)) {
                continue;
            }
            foreach (constant($service . '::' . $constant) as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                foreach (['column', 'unlimited'] as $key) {
                    if (!empty($spec[$key]) && is_string($spec[$key])) {
                        $columns[] = $spec[$key];
                    }
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Read live every time — one column listing, no static cache. A cached
     * answer would outlive the schema it described (a migration mid-process,
     * or one test class's table shape leaking into the next) and silently
     * switch the guard off.
     */
    private static function schemaCarriesGateColumns(string $service): bool
    {
        try {
            $present = Schema::getColumnListing('pricing_plans');

            return $present && !array_diff(self::auditedColumns($service), $present);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param  Collection<int, PricingPlan>  $plans
     * @return array<int, string>
     */
    private static function run(string $productType, Collection $plans): array
    {
        $service = self::SERVICES[$productType];

        // A database that does not carry the gate columns cannot be audited:
        // every missing column reads back as null, which the audit would
        // (correctly, for a real schema) call a dropped feature. Blocking
        // every save on such a database would be a lie, so skip instead.
        if (!self::schemaCarriesGateColumns($service)) {
            return [];
        }

        try {
            return array_values(array_map('strval', $service::audit($plans)));
        } catch (\Throwable $e) {
            // Fail open — see the class docblock. The deploy gate still bites.
            Log::warning('Plan ladder audit failed for ' . $productType . ': ' . $e->getMessage());

            return [];
        }
    }
}
