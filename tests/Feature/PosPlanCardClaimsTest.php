<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Services\PosPlanComparisonService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * PACKAGE CARD CLAIMS (Task 1384).
 *
 * The bullet list on a POS package card used to come from the display-only
 * pricing_plans.features JSON — hand-typed marketing copy no gate reads. A card
 * could therefore promise a feature the plan row did not grant, or print a
 * bills / team / branch number that the comparison table right under it
 * contradicted.
 *
 * Invariants locked here:
 *   1. Card bullets are GENERATED from the same plan columns the comparison
 *      table reads (the base package lists what every package includes; every
 *      package above lists only what it newly unlocks).
 *   2. A bullet only ever appears when the plan column behind it is really ON.
 *   3. No card bullet repeats a number the table prints (bills, team,
 *      branches, counters, products) — a lifted cap is spelled out in words.
 *   4. auditCards() (folded into audit(), which scripts/plan-gate-check.php
 *      runs before every deploy) FAILS on a card that claims more than the
 *      plan grants, on an "Everything in X, plus" line that is not true, and
 *      on a card view that hand-writes claims again.
 *   5. The card lines exist in all three POS languages.
 */
class PosPlanCardClaimsTest extends TestCase
{
    /** The real PRA POS ladder, in memory — no DB row is touched. */
    private function ladder(): Collection
    {
        $make = function (array $attributes): PricingPlan {
            $plan = new PricingPlan();
            $plan->forceFill(array_merge([
                // Included in every package (audited by the comparison service).
                'khata_enabled'          => true,
                'loyalty_enabled'        => true,
                'inventory_enabled'      => true,
                'max_products'           => -1,
                // Tick/cross rows — off unless the plan says otherwise.
                'restaurant_enabled'     => false,
                'deals_enabled'          => false,
                'analytics_enabled'      => false,
                'reports_enabled'        => false,
                'excel_enabled'          => false,
                'offline_enabled'        => false,
                'riders_enabled'         => false,
                'qr_menu_enabled'        => false,
                'whatsapp_enabled'       => false,
                'hazri_enabled'          => false,
                'rider_tracking_enabled' => false,
                'custom_access_enabled'  => false,
                'caller_id_enabled'      => false,
            ], $attributes));

            return $plan;
        };

        // Current package policy: Riders + QR Menu are Business+, Staff Hazri
        // is Pro+, and the remaining optional features stay paid add-ons.
        // Pro Max is retired — sellable plans are Starter, Business, Pro, Unlimited.
        return collect([
            $make(['name' => 'Starter', 'invoice_limit' => 2000, 'user_limit' => 2,
                   'branch_limit' => 1, 'max_terminals' => 1]),
            $make(['name' => 'Business', 'invoice_limit' => -1, 'user_limit' => 5,
                   'branch_limit' => 1, 'max_terminals' => 3,
                   'restaurant_enabled' => true, 'deals_enabled' => true, 'analytics_enabled' => true,
                   'reports_enabled' => true, 'excel_enabled' => true, 'offline_enabled' => true,
                    'custom_access_enabled' => true, 'riders_enabled' => true, 'qr_menu_enabled' => true]),
            $make(['name' => 'Pro', 'invoice_limit' => -1, 'user_limit' => 20,
                   'branch_limit' => 3, 'max_terminals' => -1,
                   'restaurant_enabled' => true, 'deals_enabled' => true, 'analytics_enabled' => true,
                   'reports_enabled' => true, 'excel_enabled' => true, 'offline_enabled' => true,
                    'custom_access_enabled' => true, 'riders_enabled' => true, 'qr_menu_enabled' => true,
                    'hazri_enabled' => true]),
            $make(['name' => 'Unlimited', 'invoice_limit' => -1, 'user_limit' => -1,
                   'branch_limit' => 5, 'max_terminals' => -1,
                   'restaurant_enabled' => true, 'deals_enabled' => true, 'analytics_enabled' => true,
                   'reports_enabled' => true, 'excel_enabled' => true, 'offline_enabled' => true,
                    'custom_access_enabled' => true, 'riders_enabled' => true, 'qr_menu_enabled' => true,
                    'hazri_enabled' => true]),
        ]);
    }

    private function keys(array $rows): array
    {
        return array_map(fn (array $row) => $row['key'], $rows);
    }

    public function test_base_card_lists_what_every_package_includes(): void
    {
        $rows = PosPlanComparisonService::cardHighlights($this->ladder()->first(), null);

        $this->assertSame(array_keys(PosPlanComparisonService::INCLUDED_ROWS), $this->keys($rows));
        foreach ($rows as $row) {
            $this->assertSame('included', $row['source']);
        }
    }

    public function test_higher_card_lists_only_what_it_newly_unlocks(): void
    {
        $ladder = $this->ladder()->keyBy('name');

        $business = $this->keys(PosPlanComparisonService::cardHighlights($ladder['Business'], $ladder['Starter']));
        $this->assertContains('restaurant', $business);
        $this->assertContains('analytics', $business);
        $this->assertContains('custom_access', $business);
        $this->assertContains('riders', $business);
        $this->assertContains('qr_menu', $business);
        $this->assertNotContains('caller_id', $business);

        $pro = $this->keys(PosPlanComparisonService::cardHighlights($ladder['Pro'], $ladder['Business']));
        // Already granted one step below — never repeated as a new gain.
        $this->assertNotContains('analytics', $pro);
        $this->assertNotContains('restaurant', $pro);
        $this->assertNotContains('custom_access', $pro);
        $this->assertContains('hazri', $pro);

        // Pro inherits Business and gains hazri; team and branches lift (both
        // are now higher than Business), counters was already unlimited.
        // Business now has unlimited invoices so bills does NOT lift at Pro.
        $this->assertNotContains('bills', $pro);
        $this->assertContains('counters', $pro);

        // Unlimited sits above Pro; team becomes unlimited (the only cap that lifts to ∞).
        // branches goes from 3 to 5 but stays finite so no card limit row is emitted —
        // the table's own limit cell shows the number.
        $unlimited = $this->keys(PosPlanComparisonService::cardHighlights($ladder['Unlimited'], $ladder['Pro']));
        $this->assertContains('team', $unlimited);
        $this->assertNotContains('branches', $unlimited,
            'branches stays finite on Unlimited (5) so no limit card row is emitted.');
    }

    /**
     * A paid add-on may NOT be claimed by a card whose plan row does not grant
     * it. Since 23 Aug 2026 an add-on column CAN ride a package (WhatsApp Bill
     * is included in Business and Unlimited, Caller ID in Unlimited), so the
     * old blanket "never on a card" rule became a per-row rule: the card may
     * only say it when the plan column is really on.
     */
    public function test_a_card_never_claims_an_addon_its_plan_row_does_not_grant(): void
    {
        $ladder = $this->ladder()->values();
        $addonKeys = ['whatsapp', 'rider_tracking', 'caller_id'];
        $columns = array_column(PosPlanComparisonService::FEATURE_ROWS, 'column', null);
        $rowColumns = [];
        foreach (PosPlanComparisonService::FEATURE_ROWS as $key => $spec) {
            $rowColumns[$key] = $spec['column'];
        }

        foreach ($ladder as $index => $plan) {
            $prev = $index > 0 ? $ladder[$index - 1] : null;
            $keys = $this->keys(PosPlanComparisonService::cardHighlights($plan, $prev));
            foreach ($addonKeys as $key) {
                if (!isset($rowColumns[$key])) {
                    continue; // not a comparison row at all — nothing can print it
                }
                if ($plan->{$rowColumns[$key]}) {
                    continue; // the package really includes it
                }
                $this->assertNotContains($key, $keys,
                    "{$plan->name} card may not claim add-on-sold '{$key}' — its plan column is off.");
            }
        }

        // The both-ways rule: an add-on column may sit in FEATURE_ROWS ONLY if
        // that row is marked as an add-on, so a package that does NOT include
        // it renders the "Add-on" pill instead of a bare cross — and every
        // add-on-marked row must really be a sellable add-on column.
        $addonMarked = [];
        foreach (PosPlanComparisonService::FEATURE_ROWS as $key => $spec) {
            if (!empty($spec['addon'])) {
                $addonMarked[$spec['column']] = $key;
            }
            if (isset(PosPlanComparisonService::ADDON_COLUMNS[$spec['column']])) {
                $this->assertNotEmpty($spec['addon'] ?? null,
                    "FEATURE_ROWS row '{$key}' shows add-on-sold column {$spec['column']} but is not marked "
                    . "'addon' => true — a package without it would print a plain cross instead of the Add-on pill.");
            }
        }
        foreach ($addonMarked as $column => $key) {
            $this->assertArrayHasKey($column, PosPlanComparisonService::ADDON_COLUMNS,
                "FEATURE_ROWS row '{$key}' is marked as an add-on but {$column} is not sold as one.");
        }
        $this->assertNotEmpty($columns);
    }

    public function test_a_bullet_disappears_when_the_plan_column_is_off(): void
    {
        $ladder = $this->ladder()->keyBy('name');
        $ladder['Business']->deals_enabled = false;

        $business = $this->keys(PosPlanComparisonService::cardHighlights($ladder['Business'], $ladder['Starter']));
        $this->assertNotContains('deals', $business, 'A card may never advertise a gate the plan row has switched off.');
    }

    public function test_an_included_bullet_disappears_when_its_column_is_off(): void
    {
        $ladder = $this->ladder()->keyBy('name');
        $ladder['Starter']->inventory_enabled = false;

        $starter = $this->keys(PosPlanComparisonService::cardHighlights($ladder['Starter'], null));
        $this->assertNotContains('inventory', $starter);
        $this->assertContains('khata', $starter);
    }

    /**
     * Plan rows are live-editable from the admin panel, so the ladder can slip
     * between deploys. The card must then stop claiming the package below it
     * instead of printing a promise the table disproves.
     */
    public function test_card_stops_claiming_the_package_below_when_a_feature_is_lost(): void
    {
        $ladder = $this->ladder()->keyBy('name');
        $this->assertTrue(PosPlanComparisonService::cardInherits($ladder['Unlimited'], $ladder['Pro']));

        $ladder['Unlimited']->analytics_enabled = false;
        $this->assertFalse(PosPlanComparisonService::cardInherits($ladder['Unlimited'], $ladder['Pro']));

        // Falls back to a standalone list of what Unlimited itself really gives.
        $rows = PosPlanComparisonService::cardHighlights($ladder['Unlimited'], $ladder['Pro']);
        $keys = $this->keys($rows);
        $this->assertNotContains('analytics', $keys, 'A switched-off gate may never be listed.');
        $this->assertContains('restaurant', $keys);
        $this->assertContains('custom_access', $keys);
        $this->assertContains('khata', $keys, 'A standalone card still lists what every package includes.');
        foreach ($rows as $row) {
            if ($row['source'] === 'feature') {
                $this->assertTrue((bool) $ladder['Unlimited']->{$row['column']});
            }
        }
    }

    public function test_card_stops_claiming_the_package_below_when_a_limit_tightens(): void
    {
        $ladder = $this->ladder()->keyBy('name');
        // Pro has 3 branches, Business has 1 — Pro inherits Business cleanly.
        $this->assertTrue(PosPlanComparisonService::cardInherits($ladder['Pro'], $ladder['Business']));

        // Tighten Pro branches below Business to break the ladder.
        $ladder['Pro']->branch_limit = 1;
        $ladder['Business']->branch_limit = 3;
        $this->assertFalse(PosPlanComparisonService::cardInherits($ladder['Pro'], $ladder['Business']));

        // An uncapped package below that becomes capped above is also a break.
        $ladder2 = $this->ladder()->keyBy('name');
        $ladder2['Unlimited']->invoice_limit = 5000;
        $this->assertFalse(PosPlanComparisonService::cardInherits($ladder2['Unlimited'], $ladder2['Pro']));
    }

    public function test_a_new_package_dropped_into_the_ladder_cannot_inherit_a_broken_floor(): void
    {
        $ladder = $this->ladder()->keyBy('name');
        // A freshly created package: the admin has not switched the basics on yet.
        $fresh = new \App\Models\PricingPlan();
        $fresh->forceFill(['name' => 'New', 'khata_enabled' => false, 'loyalty_enabled' => false,
            'inventory_enabled' => false, 'max_products' => 500, 'invoice_limit' => 500,
            'user_limit' => 1, 'branch_limit' => 1, 'max_terminals' => 1]);

        $this->assertFalse(PosPlanComparisonService::cardInherits($ladder['Business'], $fresh));
        $keys = $this->keys(PosPlanComparisonService::cardHighlights($ladder['Business'], $fresh));
        $this->assertContains('khata', $keys, 'Business must list its own floor when the package below has none.');
        $this->assertContains('restaurant', $keys);
    }

    public function test_no_card_bullet_repeats_a_number_the_table_prints(): void
    {
        $ladder = $this->ladder();
        $prev = null;
        foreach ($ladder as $plan) {
            $numbers = array_filter([
                $plan->invoice_limit, $plan->user_limit, $plan->branch_limit, $plan->max_terminals,
            ], fn ($value) => $value !== null && (int) $value > 0);

            foreach (PosPlanComparisonService::cardHighlights($plan, $prev) as $row) {
                foreach ($numbers as $number) {
                    foreach ([$row['label'], (string) $row['hint']] as $text) {
                        $this->assertDoesNotMatchRegularExpression(
                            '/(?<!\d)' . (int) $number . '(?!\d)/',
                            $text,
                            "{$plan->name} card bullet '{$row['key']}' repeats {$number} — numbers belong to the comparison table only."
                        );
                    }
                }
            }
            $prev = $plan;
        }
    }

    /**
     * "Every package includes:" speaks for the whole ladder, so it may only
     * appear while every package really carries the floor — one live admin
     * edit on a HIGHER package makes the cheapest card's universal claim false.
     */
    public function test_cheapest_card_drops_the_universal_claim_when_a_later_package_breaks_the_floor(): void
    {
        $ladder = $this->ladder();
        $this->assertTrue(PosPlanComparisonService::cardIncludedFloorHolds($ladder));

        $capped = $this->ladder();
        $capped->firstWhere('name', 'Business')->max_products = 500;
        $this->assertFalse(
            PosPlanComparisonService::cardIncludedFloorHolds($capped),
            'Starter may not promise "every package includes unlimited products" once Business caps them.'
        );
        // The cheapest card itself stays true — it still owns what it lists.
        $this->assertContains('products', $this->keys(PosPlanComparisonService::cardHighlights($capped->first(), null)));

        $off = $this->ladder();
        $off->firstWhere('name', 'Pro')->khata_enabled = false;
        $this->assertFalse(PosPlanComparisonService::cardIncludedFloorHolds($off));

        $this->assertFalse(PosPlanComparisonService::cardIncludedFloorHolds(collect()));
        $this->assertFalse(PosPlanComparisonService::cardIncludedFloorHolds(null));
    }

    public function test_audit_passes_on_the_real_ladder(): void
    {
        $this->assertSame([], PosPlanComparisonService::auditCards($this->ladder()));
    }

    public function test_audit_fails_when_a_higher_package_loses_a_feature(): void
    {
        $ladder = $this->ladder();
        $ladder->firstWhere('name', 'Unlimited')->analytics_enabled = false;

        $problems = implode("\n", PosPlanComparisonService::auditCards($ladder));
        $this->assertStringContainsString('Unlimited', $problems);
        $this->assertStringContainsString('analytics_enabled', $problems);
    }

    public function test_audit_fails_when_a_higher_package_tightens_a_limit(): void
    {
        $ladder = $this->ladder();
        $ladder->firstWhere('name', 'Unlimited')->branch_limit = 1;

        $problems = implode("\n", PosPlanComparisonService::auditCards($ladder));
        $this->assertStringContainsString('branch_limit', $problems);
    }

    public function test_audit_fails_when_an_included_feature_is_off_on_a_plan(): void
    {
        $ladder = $this->ladder();
        $ladder->firstWhere('name', 'Starter')->inventory_enabled = false;

        $problems = implode("\n", PosPlanComparisonService::auditCards($ladder));
        $this->assertStringContainsString('inventory_enabled is OFF', $problems);
    }

    public function test_card_views_do_not_hand_write_plan_claims(): void
    {
        $this->assertNotEmpty(
            PosPlanComparisonService::CARD_VIEWS,
            'The POS billing panel still renders package cards — it must stay in CARD_VIEWS.'
        );

        foreach (PosPlanComparisonService::CARD_VIEWS as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            foreach (array_keys(PosPlanComparisonService::CARD_FORBIDDEN) as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$relative} must not print {$needle} on a package card — use PosPlanComparisonService::cardHighlights()."
                );
            }
        }
    }

    /**
     * Task 1483 — the landing's card stack is gone; the comparison table is the
     * buying surface there. It must therefore NOT be scanned as a card view
     * (the table legitimately prints the very limits CARD_FORBIDDEN bans), and
     * it must not quietly grow a card stack back.
     */
    public function test_the_landing_is_no_longer_a_card_view(): void
    {
        $landing = 'resources/views/pos/landing.blade.php';

        $this->assertNotContains(
            $landing,
            PosPlanComparisonService::CARD_VIEWS,
            'The landing renders the comparison table, not package cards — scanning it for plan numbers would fail forever.'
        );

        $source = (string) file_get_contents(base_path($landing));
        $this->assertStringNotContainsString(
            'cardHighlights',
            $source,
            'The landing package cards were deleted in Task 1483 — put the view back in CARD_VIEWS if they ever return.'
        );
        $this->assertStringContainsString(
            '<x-pos-plan-comparison',
            $source,
            'The landing must still render the comparison table — it is the only package block on the page.'
        );
    }

    public function test_pricing_comes_before_long_feature_copy_on_all_three_product_landings(): void
    {
        $pages = [
            'resources/views/pos/landing.blade.php' => ['<!-- Pricing Section -->', '<!-- Editorial Features -->'],
            'resources/views/di-landing.blade.php' => ['<!-- Pricing Section -->', '<!-- Editorial How it Works -->'],
            'resources/views/fbr-pos/landing.blade.php' => ['<!-- Pricing -->', '<!-- Concrete Features: Asymmetrical Left-Aligned -->'],
        ];

        foreach ($pages as $relative => [$pricingMarker, $featureMarker]) {
            $source = (string) file_get_contents(base_path($relative));
            $pricingAt = strpos($source, $pricingMarker);
            $featuresAt = strpos($source, $featureMarker);

            $this->assertNotFalse($pricingAt, "{$relative} has lost its pricing marker.");
            $this->assertNotFalse($featuresAt, "{$relative} has lost its feature marker.");
            $this->assertLessThan(
                $featuresAt,
                $pricingAt,
                "{$relative} must show pricing before the long English feature detail."
            );
        }
    }

    public function test_pos_addon_strip_is_a_real_selectable_quote_not_a_static_price_list(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/components/pos-addon-strip.blade.php'));

        $this->assertStringContainsString('type="checkbox"', $source);
        $this->assertStringContainsString('x-model="picked"', $source);
        $this->assertStringContainsString('signupUrl()', $source);
        $this->assertStringContainsString("params.append('addons[]', code)", $source);
        $this->assertStringContainsString('PosAddonPricingService::catalog()', $source);
    }

    public function test_pra_paid_addon_picker_does_not_leak_onto_di_or_fbr_landings(): void
    {
        $this->assertStringNotContainsString(
            '<x-pos-addon-strip',
            (string) file_get_contents(base_path('resources/views/di-landing.blade.php'))
        );
        $this->assertStringNotContainsString(
            '<x-pos-addon-strip',
            (string) file_get_contents(base_path('resources/views/fbr-pos/landing.blade.php'))
        );
    }

    public function test_card_lines_exist_in_all_three_languages(): void
    {
        foreach (array_keys(PosPlanComparisonService::LIMIT_ROWS) as $key) {
            foreach (['en', 'rur', 'ur'] as $locale) {
                $langKey = 'pos.pcmp_card_unl_' . $key;
                $value = __($langKey, [], $locale);
                $this->assertIsString($value);
                $this->assertNotSame($langKey, $value, "Missing {$langKey} in [{$locale}].");
                $this->assertNotSame('', trim($value));
                $this->assertDoesNotMatchRegularExpression('/\d/', $value, "{$langKey} [{$locale}] must stay number-free.");
            }
        }
    }
}
