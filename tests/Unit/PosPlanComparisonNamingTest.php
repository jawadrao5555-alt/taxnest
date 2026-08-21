<?php

namespace Tests\Unit;

use App\Services\PosFeatureService;
use App\Services\PosPlanComparisonService;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Package-table naming lint (Task 1385).
 *
 * The comparison table on the POS landing / billing pages is GENERATED from
 * the plan-gate columns, so a gate that nobody gave a customer-facing name
 * simply vanishes from the table — the shop is charged for a package whose
 * feature list never mentions it. A row whose label exists in English but not
 * in Roman Urdu or Urdu is the same bug seen by half the shops: a blank cell.
 *
 * Both checks read constants + lang files ONLY — no pricing_plans row, no
 * database. They used to run exclusively inside scripts/plan-gate-check.php,
 * which needs the staging MySQL DB up and is only run before a deploy, so a
 * missing name survived every normal test run. Here they fail the moment
 * someone adds a gate.
 *
 * The pre-deploy check still runs them (audit() calls auditNames() first) on
 * top of its own DB-backed number/tick comparison — this test does not
 * replace it.
 */
class PosPlanComparisonNamingTest extends TestCase
{
    /** The real configuration must be clean — this is the guard doing its job. */
    public function test_every_gate_has_a_name_and_every_row_reads_in_all_three_languages(): void
    {
        $this->assertSame(
            [],
            PosPlanComparisonService::auditNames(),
            "The package comparison table is out of sync with the plan gates."
        );
    }

    public function test_a_new_gate_column_with_no_customer_facing_row_fails(): void
    {
        $problems = PosPlanComparisonService::auditNames(['sparkle_enabled']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString("Gate column 'sparkle_enabled' has no customer-facing row", $problems[0]);
        $this->assertStringContainsString('COVERED_BY', $problems[0], 'The failure must say how to fix it.');
    }

    /**
     * kot_enabled is the live example: a real PLAN_GATES column with no row of
     * its own, spoken for by the "Restaurant / Kitchen" row. Declaring a gate
     * COVERED_BY an existing row is the sanctioned escape hatch — and the row
     * it names has to exist.
     */
    public function test_a_gate_declared_covered_by_an_existing_row_passes(): void
    {
        $this->assertArrayHasKey('kot_enabled', PosPlanComparisonService::COVERED_BY);
        $this->assertContains('kot_enabled', PosFeatureService::PLAN_GATES);
        $this->assertNotContains('kot_enabled', array_column(PosPlanComparisonService::FEATURE_ROWS, 'column'));

        $this->assertSame([], PosPlanComparisonService::auditNames(['kot_enabled']));

        foreach (PosPlanComparisonService::COVERED_BY as $column => $rowKey) {
            $this->assertTrue(
                isset(PosPlanComparisonService::FEATURE_ROWS[$rowKey])
                    || isset(PosPlanComparisonService::INCLUDED_ROWS[$rowKey]),
                "COVERED_BY maps '{$column}' to '{$rowKey}', which is not a row on the table."
            );
        }
    }

    public function test_a_row_label_missing_from_a_language_fails_for_every_language_it_is_missing_from(): void
    {
        $problems = PosPlanComparisonService::auditNames([], ['pcmp_no_such_row' => false]);

        $this->assertCount(3, $problems, 'A brand-new row with no lang line must fail in en, rur AND ur.');
        foreach (['en', 'rur', 'ur'] as $locale) {
            $this->assertStringContainsString("Missing lang key pos.pcmp_no_such_row in [{$locale}]", implode("\n", $problems));
        }
    }

    /**
     * The real-world shape of the bug: somebody names a new row in English and
     * forgets the two Urdu files. __() would answer the Urdu lookup with the
     * ENGLISH line (app.fallback_locale = en), so the audit has to read each
     * language on its own — otherwise a shop set to Urdu quietly reads English,
     * and shows a blank cell the day the English key is renamed.
     */
    public function test_a_row_named_only_in_english_fails_for_roman_urdu_and_urdu(): void
    {
        $this->seedLine('en', 'pcmp_english_only_row', 'English only row');

        // Sanity: the fallback really would have hidden this.
        $this->assertSame('English only row', __('pos.pcmp_english_only_row', [], 'ur'));

        $problems = PosPlanComparisonService::auditNames([], ['pcmp_english_only_row' => false]);

        $this->assertCount(2, $problems, "English is named, so only rur and ur may fail:\n" . implode("\n", $problems));
        $this->assertStringContainsString('Missing lang key pos.pcmp_english_only_row in [rur]', $problems[0]);
        $this->assertStringContainsString('Missing lang key pos.pcmp_english_only_row in [ur]', $problems[1]);
        $this->assertStringNotContainsString('[en]', implode("\n", $problems));
    }

    public function test_a_row_whose_urdu_line_was_left_blank_fails(): void
    {
        $this->seedLine('en', 'pcmp_blank_row', 'Blank row');
        $this->seedLine('rur', 'pcmp_blank_row', 'Blank row (rur)');
        $this->seedLine('ur', 'pcmp_blank_row', '   ');

        $problems = PosPlanComparisonService::auditNames([], ['pcmp_blank_row' => false]);

        $this->assertCount(1, $problems, "Only Urdu is blank:\n" . implode("\n", $problems));
        $this->assertStringContainsString('Missing lang key pos.pcmp_blank_row in [ur]', $problems[0]);
    }

    public function test_a_row_that_declares_a_hint_fails_when_the_hint_line_is_missing(): void
    {
        // pcmp_deals is a real, translated row label — but it declares no hint,
        // so pos.pcmp_deals_hint does not exist. Flip the declaration and only
        // the hint may be reported missing.
        $problems = PosPlanComparisonService::auditNames([], ['pcmp_deals' => true]);

        $this->assertCount(3, $problems);
        foreach ($problems as $problem) {
            $this->assertStringContainsString('pos.pcmp_deals_hint', $problem);
        }
    }

    /** A hint written in English only is just as broken as a missing one. */
    public function test_a_hint_written_only_in_english_fails_for_the_other_two_languages(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            $this->seedLine($locale, 'pcmp_hinted_row', 'Hinted row (' . $locale . ')');
        }
        $this->seedLine('en', 'pcmp_hinted_row_hint', 'English only hint');

        $problems = PosPlanComparisonService::auditNames([], ['pcmp_hinted_row' => true]);

        $this->assertCount(2, $problems, "The label is translated; only the hint is missing:\n" . implode("\n", $problems));
        foreach ($problems as $problem) {
            $this->assertStringContainsString('pos.pcmp_hinted_row_hint', $problem);
        }
        $this->assertStringNotContainsString('[en]', implode("\n", $problems));
    }

    /**
     * The audit builds its key list from the row constants, so a row added
     * without a lang line can only be caught if EVERY row is really checked.
     * Read with the fallback OFF — an English line must never answer for Urdu.
     */
    public function test_every_declared_row_and_hint_is_actually_looked_up(): void
    {
        $rows = [];
        foreach (PosPlanComparisonService::LIMIT_ROWS as $key => $spec) {
            $rows['pcmp_' . $key] = $spec['hint'];
        }
        foreach (PosPlanComparisonService::FEATURE_ROWS as $key => $spec) {
            $rows['pcmp_' . $key] = $spec['hint'];
        }
        foreach (array_keys(PosPlanComparisonService::INCLUDED_ROWS) as $key) {
            $rows['pcmp_inc_' . $key] = false;
        }
        $this->assertNotEmpty($rows);

        foreach ($rows as $key => $needsHint) {
            foreach ($needsHint ? [$key, $key . '_hint'] : [$key] as $fullKey) {
                foreach (['en', 'rur', 'ur'] as $locale) {
                    $this->assertTrue(
                        Lang::has('pos.' . $fullKey, $locale, false),
                        "Missing pos.{$fullKey} in [{$locale}] — an English line may not answer for another language."
                    );
                    $value = Lang::get('pos.' . $fullKey, [], $locale, false);
                    $this->assertIsString($value);
                    $this->assertNotSame('', trim($value), "Blank pos.{$fullKey} in [{$locale}].");
                }
            }
        }
    }

    /**
     * Give ONE locale a lang line the others do not have.
     *
     * addLines() writes straight into the translator's loaded cache, so the
     * pos group has to be read first in every locale — seeding an unloaded
     * group would mark it loaded and hide the real lang file.
     */
    private function seedLine(string $locale, string $key, string $value): void
    {
        foreach (['en', 'rur', 'ur'] as $warm) {
            Lang::get('pos.pcmp_bills', [], $warm, false);
        }
        Lang::addLines(['pos.' . $key => $value], $locale);
    }
}
