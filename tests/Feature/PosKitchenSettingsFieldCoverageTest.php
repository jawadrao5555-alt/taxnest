<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantPosController;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1367 — "Kitchen notes wala switch save hi nahi hota".
 *
 * Kitchen Settings writes ALL of its switches through one mass assignment
 * (RestaurantPosController::updateKitchenSettings -> $company->update($updates)).
 * Eloquent silently DROPS any key that is missing from Company::$fillable —
 * no error, no warning, and the page still flashes "Saved". The shop owner
 * flips the switch, reloads, and finds the old state again.
 *
 * It has now happened twice: delivery_kot_after_payment (Task 1356) and
 * kot_show_kitchen_notes (this task — the kitchen never received the special
 * instructions typed on the bill).
 *
 * This file is the standing net so it cannot happen a THIRD time. It reads the
 * real form out of the Blade file, so a switch added tomorrow is covered the
 * moment it is added — nothing here has to be updated by hand:
 *
 *   • every submitted field is a real companies column;
 *   • every submitted field is mass-assignable (the silent-drop guard);
 *   • a save really round-trips through the DB, in BOTH directions;
 *   • every field is part of Company::posConfigRev(), so an offline /
 *     SW-cached sale screen refetches instead of obeying the old setting.
 *
 * NEVER "fix" a failure here by deleting the field from the list — add the
 * column to Company::$fillable (+ $casts + posConfigRev) instead.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosKitchenSettingsFieldCoverageTest.php --testdox
 */
class PosKitchenSettingsFieldCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Fields the page submits that are NOT company columns (framework noise). */
    private const NON_COLUMN_FIELDS = ['_token', '_method'];

    /**
     * Every field name the Kitchen Settings form POSTs to
     * pos.restaurant.kitchen-settings.update.
     *
     * The page ALSO renders the per-counter station forms; those post to other
     * routes and write pos_kitchen_stations, so only the settings form itself
     * is scanned.
     *
     * @return array<int, string>
     */
    private function kitchenSettingsFields(): array
    {
        $path = resource_path('views/pos/restaurant/kitchen-settings.blade.php');
        $blade = file_get_contents($path);
        $this->assertNotFalse($blade, "Kitchen Settings view not found at {$path}");

        $action = strpos($blade, "route('pos.restaurant.kitchen-settings.update')");
        $this->assertNotFalse(
            $action,
            'The Kitchen Settings form no longer posts to pos.restaurant.kitchen-settings.update — '
            . 'update this test to point at the new form, do not delete it.'
        );

        $open = strrpos(substr($blade, 0, $action), '<form');
        $close = strpos($blade, '</form>', $action);
        $this->assertNotFalse($open);
        $this->assertNotFalse($close);
        $form = substr($blade, $open, $close - $open);

        preg_match_all('/\bname="([A-Za-z0-9_]+)"/', $form, $m);
        $fields = array_values(array_unique(array_diff($m[1], self::NON_COLUMN_FIELDS)));

        // A form that suddenly scans to (almost) nothing would make every
        // assertion below pass vacuously.
        $this->assertGreaterThan(5, count($fields), 'Kitchen Settings form scan found suspiciously few fields');

        return $fields;
    }

    private function makeCompany(): Company
    {
        return Company::create([
            'name' => 'Kitchen Settings Coverage Co',
            'ntn' => '1234567', // NOT NULL on the real table
            'company_status' => 'approved',
            'restaurant_mode' => true,
        ]);
    }

    /** Save the given payload exactly the way the page does. */
    private function saveKitchenSettings(Company $company, array $payload): void
    {
        app()->instance('currentCompanyId', $company->id);

        $request = Request::create('/pos/restaurant/kitchen-settings', 'POST', $payload);
        app()->instance('request', $request);

        (new RestaurantPosController())->updateKitchenSettings($request);
    }

    // ── 1. The form matches the table + the model ─────────────────────────

    public function test_every_kitchen_settings_field_is_a_real_companies_column(): void
    {
        $columns = Schema::getColumnListing('companies');
        $unknown = array_values(array_diff($this->kitchenSettingsFields(), $columns));

        $this->assertSame([], $unknown, 'Kitchen Settings submits field(s) with no companies column: ' . implode(', ', $unknown));
    }

    public function test_every_kitchen_settings_field_is_mass_assignable(): void
    {
        // THE silent-drop guard. A field missing here is written by
        // updateKitchenSettings, dropped by Eloquent, and still reported as
        // "Saved" to the shop owner.
        $notFillable = array_values(array_diff($this->kitchenSettingsFields(), (new Company())->getFillable()));

        $this->assertSame(
            [],
            $notFillable,
            'Kitchen Settings switch(es) missing from Company::$fillable — the save is silently dropped: '
            . implode(', ', $notFillable)
        );
    }

    // ── 2. A save really lands in the row ─────────────────────────────────

    public function test_saving_kitchen_settings_persists_every_switch_in_both_directions(): void
    {
        $fields = $this->kitchenSettingsFields();
        $company = $this->makeCompany();

        // 1 and 0 are valid for every field on this form: the toggles are
        // boolean and kot_left_margin_mm is clamped to 0..30 mm.
        foreach (['1', '0'] as $value) {
            $this->saveKitchenSettings($company, array_fill_keys($fields, $value));

            $row = (array) DB::table('companies')->where('id', $company->id)->first();
            foreach ($fields as $field) {
                $this->assertSame(
                    (int) $value,
                    (int) $row[$field],
                    "Kitchen Settings saved {$field}={$value} but the companies row did not change "
                    . '(missing from Company::$fillable?)'
                );
            }
        }
    }

    public function test_kitchen_notes_switch_survives_a_reload(): void
    {
        // The reported bug, end to end: the KOT renderer and the settings page
        // both read this column, so a dropped write means the kitchen never
        // sees the special instructions.
        $company = $this->makeCompany();
        $fields = $this->kitchenSettingsFields();
        $this->assertContains('kot_show_kitchen_notes', $fields);

        $this->saveKitchenSettings($company, array_fill_keys($fields, '0'));
        $this->assertFalse((bool) Company::find($company->id)->kot_show_kitchen_notes);

        $this->saveKitchenSettings($company, array_merge(array_fill_keys($fields, '0'), ['kot_show_kitchen_notes' => '1']));
        $this->assertTrue(
            (bool) Company::find($company->id)->kot_show_kitchen_notes,
            'Turning "kitchen notes on the KOT" ON must survive a page reload'
        );
    }

    // ── 3. Cached sale screens must notice the change ─────────────────────

    public function test_every_kitchen_settings_field_busts_the_sale_screen_boot_fingerprint(): void
    {
        // The sale screen is served cache-first (SALE_CACHE) and bakes these
        // settings; a field outside posConfigRev() leaves an already-open or
        // offline screen obeying the OLD setting forever.
        $company = $this->makeCompany();

        foreach ($this->kitchenSettingsFields() as $field) {
            $before = Company::find($company->id)->posConfigRev();

            $current = DB::table('companies')->where('id', $company->id)->value($field);
            DB::table('companies')->where('id', $company->id)
                ->update([$field => ((int) $current === 1) ? 0 : 1]);

            $this->assertNotSame(
                $before,
                Company::find($company->id)->posConfigRev(),
                "Changing {$field} must change Company::posConfigRev()"
            );
        }
    }
}
