<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PRA taxes SERVICES only.
 *
 * Punjab Sales Tax on Services Act 2012, s.2(38) defines a service as
 * "anything which is not goods"; supply of goods is federal (Sales Tax Act
 * 1990) and belongs to the FBR panel. The PRA panel used to offer eight goods
 * categories (retail, pharmacy, grocery, clothing, electronics, hardware, auto
 * parts, bakery) that legally could never be PRA cases.
 *
 * These tests lock the two halves of that correction:
 *   - no NEW PRA shop can register on, or switch to, a goods category, and the
 *     service category it does pick survives into Customize; and
 *   - no EXISTING shop sitting on a retired goods category is broken by it —
 *     it keeps a real label, a working preset, and the ability to re-save.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/PraServiceCategoriesTest.php --testdox
 */
class PraServiceCategoriesTest extends TestCase
{
    /** Every goods category that must never be offered on the PRA panel again. */
    private const GOODS = [
        'retail', 'pharmacy', 'grocery', 'wholesale', 'hybrid_cafe_retail',
        'clothing', 'electronics', 'hardware', 'autoparts', 'bakery',
    ];

    private int $companyId;
    private int $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();
        // The restaurant-access memo is static and keyed by company id, so an
        // earlier test class in the same process can answer for OUR company.
        $memo = new \ReflectionProperty(PosFeatureService::class, 'restaurantAllowedCache');
        $memo->setAccessible(true);
        $memo->setValue(null, []);
        Schema::dropAllTables();
        $this->buildSchema();
        $this->seedShop();
    }

    private function actAsAdmin(): void
    {
        $this->actingAs(User::find($this->adminUserId), 'pos');
        app()->instance('currentCompanyId', $this->companyId);
    }

    private function company(): Company
    {
        return Company::find($this->companyId);
    }

    /** Save the SaaS admin's POS-features override page for this company. */
    private function adminSavesFeatures(array $flags): void
    {
        app(\App\Http\Controllers\AdminController::class)->updatePosFeatures(
            Request::create('/admin/company/' . $this->companyId . '/pos-features', 'PUT', [
                'fs_present'    => '1',
                'feature_flags' => $flags,
            ]),
            $this->company()
        );
    }

    /** Start from a shop that tracks no stock at all — column AND flag off. */
    private function clearInventory(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'feature_flags'     => json_encode([]),
            'inventory_enabled' => false,
        ]);
    }

    /**
     * An inventory switch has really stuck: both surfaces agree, and the shop's
     * next Features save — which re-derives the column FROM the stored map,
     * carrying exactly what the page renders — leaves it alone.
     */
    private function assertInventoryStuck(string $what): void
    {
        $company = $this->company();
        $this->assertTrue((bool) $company->inventory_enabled, "$what must switch the module on.");
        $this->assertTrue((bool) ($company->feature_flags['inventory'] ?? false),
            "$what must write the feature flag, not just the master column.");

        $this->shopResavesItsFeatures();

        $this->assertTrue((bool) $this->company()->inventory_enabled,
            "$what must survive the next features save.");
    }

    /** The shop re-saves its Features page exactly as it renders today. */
    private function shopResavesItsFeatures(): void
    {
        $posted = [];
        foreach ((array) ($this->company()->feature_flags ?? []) as $flag => $on) {
            if ($on) {
                $posted[$flag] = '1';
            }
        }
        $this->actAsAdmin();
        $this->post('/pos/features', [
            '_token'        => csrf_token(),
            'fs_present'    => '1',
            'feature_flags' => $posted,
        ])->assertSessionHasNoErrors();
    }

    private function setCategory(?string $category, ?string $posType = null): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'business_category' => $category,
            'pos_type'          => $posType,
        ]);
    }

    // ── what the panel offers ───────────────────────────────────────────────

    /** The offered list is services only — not one goods category among them. */
    public function test_pra_offers_service_categories_only(): void
    {
        $offered = PosFeatureService::categories('pra');

        $this->assertNotEmpty($offered);
        foreach (self::GOODS as $goods) {
            $this->assertNotContains(
                $goods,
                $offered,
                "PRA cannot tax goods, so '$goods' must never be offered on the PRA panel."
            );
        }
    }

    /** The FBR panel keeps the goods businesses — the split works both ways. */
    public function test_fbr_still_offers_the_goods_categories(): void
    {
        $offered = PosFeatureService::categories('fbr');

        foreach (['retail', 'pharmacy', 'grocery', 'clothing', 'electronics', 'hardware', 'autoparts', 'bakery'] as $goods) {
            $this->assertContains($goods, $offered,
                "'$goods' files with the FBR, so the FBR panel must offer it.");
        }
        foreach ($offered as $category) {
            $this->assertTrue(PosFeatureService::isKnownCategory($category),
                "FBR category '$category' must resolve to a real preset.");
        }
    }

    /** Every retired goods category still resolves. */
    public function test_every_retired_goods_category_still_resolves(): void
    {
        foreach (self::GOODS as $goods) {
            $this->assertTrue(
                PosFeatureService::isKnownCategory($goods),
                "'$goods' must stay resolvable for shops already sitting on it."
            );

            $meta = PosFeatureService::presetMeta($goods);
            $this->assertNotSame('', trim((string) $meta['label']),
                "'$goods' must keep a real label, never a raw slug.");
            $this->assertNotSame('', trim((string) $meta['icon']));

            $defaults = PosFeatureService::defaultsForCategory($goods);
            $this->assertNotEmpty($defaults,
                "'$goods' must keep working preset defaults.");
        }
    }

    /** A legacy PRA shop sees the services PLUS its own card, pinned on the end. */
    public function test_legacy_shop_gets_its_own_category_pinned(): void
    {
        foreach (self::GOODS as $goods) {
            $this->setCategory($goods, $goods);
            $list = PosFeatureService::categoriesForCompany($this->company());

            $this->assertContains($goods, $list,
                "A shop on '$goods' must still see its own card.");
            $this->assertSame($goods, end($list),
                'The off-panel card belongs at the end, after the offered services.');
            $this->assertTrue(PosFeatureService::isOffPanelCategory($this->company()),
                "'$goods' is a goods business, so a PRA shop on it is off-panel.");
        }

        $this->setCategory('salon', 'salon');
        $this->assertSame(
            PosFeatureService::categories('pra'),
            PosFeatureService::categoriesForCompany($this->company()),
            'A service shop must see exactly the offered list — nothing pinned.'
        );
        $this->assertFalse(PosFeatureService::isOffPanelCategory($this->company()));
    }

    /** An FBR company is offered goods categories, not PRA services. */
    public function test_an_fbr_company_gets_the_goods_list(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'product_type'      => 'fbrpos',
            'business_category' => 'pharmacy',
            'pos_type'          => 'pharmacy',
        ]);

        $this->assertSame('fbr', PosFeatureService::panelFor($this->company()));
        $this->assertSame(
            PosFeatureService::categories('fbr'),
            PosFeatureService::categoriesForCompany($this->company()),
            'A pharmacy on the FBR panel is exactly where it belongs — nothing pinned.'
        );
        $this->assertFalse(PosFeatureService::isOffPanelCategory($this->company()),
            'A pharmacy is a goods business, so on the FBR panel it is NOT off-panel.');
    }

    // ── resolving what a shop is on ─────────────────────────────────────────

    /** business_category wins; a pre-split shop falls back to its pos_type. */
    public function test_category_resolution_falls_back_to_pos_type(): void
    {
        $this->setCategory('salon', 'restaurant');
        $this->assertSame('salon', PosFeatureService::resolveCategory($this->company()),
            'The stored business_category is the answer when it is a real preset.');

        // Registered before the split: only pos_type was ever written.
        $this->setCategory(null, 'pharmacy');
        $this->assertSame('pharmacy', PosFeatureService::resolveCategory($this->company()),
            'A shop with no business_category must fall back to its registration pos_type.');

        // 'general' is the catch-all, but it resolves to ITSELF — a shop nobody
        // classified must not silently read as a restaurant.
        $this->setCategory(null, 'general');
        $this->assertSame('general', PosFeatureService::resolveCategory($this->company()));
        $this->assertFalse(PosFeatureService::belongsToOtherPanel($this->company()),
            'The catch-all belongs to neither regulator, so it must raise no notice.');
        $defaults = PosFeatureService::defaultsForCategory('general');
        $this->assertEmpty(array_filter([
            $defaults['kitchen'] ?? false, $defaults['kot'] ?? false, $defaults['tables'] ?? false,
        ]), 'A shop that picked nothing must not be handed a kitchen.');

        // Only a shop with NOTHING to go on falls back.
        $this->setCategory(null, null);
        $this->assertSame('restaurant', PosFeatureService::resolveCategory($this->company()));
    }

    // ── the shop's own Customize page ───────────────────────────────────────

    /**
     * A shop may not re-file itself as a different kind of business.
     *
     * The category decides which regulator the shop reports to, so it is chosen
     * once at registration and changed afterwards by SaaS admins only. A POST
     * carrying a category is ignored, not obeyed and not rejected — a stale or
     * scripted form must never move a shop between tax authorities.
     */
    public function test_a_shop_cannot_change_its_own_business_category(): void
    {
        $this->setCategory('salon', 'salon');
        $this->actAsAdmin();

        $this->post('/pos/features', [
            '_token'            => csrf_token(),
            'fs_present'        => '1',
            'business_category' => 'grocery',
            'feature_flags'     => ['inventory' => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('salon', $this->company()->business_category,
            'Only a SaaS admin may move a shop onto another business category.');
    }

    /**
     * Customize POS knows ONE business type: the shop's own.
     *
     * The card has always been read-only, but the page still shipped the whole
     * catalogue in its own embedded wizard data — every other type's key,
     * label, description, icon and default module set was readable from the
     * shop's own page. The category picks the shop's REGULATOR, so nothing
     * about any other type belongs there.
     */
    public function test_customize_page_carries_only_the_shops_own_business_type(): void
    {
        $this->setCategory('salon', 'salon');
        $this->actAsAdmin();

        $html = $this->get('/pos/features')->assertOk()->getContent();

        $own = PosFeatureService::presetMeta('salon');
        $this->assertStringContainsString($own['label'], $html,
            'The shop must still see its OWN business type.');

        foreach (PosFeatureService::allCategoryDefaults() as $key => $ignored) {
            if ($key === 'salon') {
                continue;
            }
            $meta = PosFeatureService::presetMeta($key);

            $this->assertStringNotContainsString('"' . $key . '"', $html,
                "Customize POS must not carry the category key '$key'.");
            $this->assertStringNotContainsString($meta['label'], $html,
                "Customize POS must not name another business type ('{$meta['label']}').");
            $this->assertStringNotContainsString($meta['description'], $html,
                "Customize POS must not describe another business type ('$key').");
        }
    }

    /** A shop on a retired category still gets a usable page, not a blank card. */
    public function test_customize_page_still_renders_for_a_retired_category(): void
    {
        $this->setCategory('pharmacy', 'pharmacy');
        $this->actAsAdmin();

        $html = $this->get('/pos/features')->assertOk()->getContent();

        $this->assertStringContainsString(PosFeatureService::presetMeta('pharmacy')['label'], $html,
            'A pre-split shop must still see a real business-type card.');
        $this->assertStringContainsString(__('pos.legacy_goods_category_title'), $html,
            'The off-panel notice must survive the catalogue removal.');
    }

    /** A legacy shop can still save its features — the page is never unusable. */
    public function test_legacy_shop_can_still_save_its_features(): void
    {
        foreach (self::GOODS as $goods) {
            $this->setCategory($goods, $goods);
            $this->actAsAdmin();

            $this->post('/pos/features', [
                '_token'        => csrf_token(),
                'fs_present'    => '1',
                'feature_flags' => ['inventory' => '1'],
            ])->assertSessionHasNoErrors();

            $company = $this->company();
            $this->assertSame($goods, $company->business_category,
                "A shop on '$goods' must keep its category through a save.");
            $this->assertTrue((bool) $company->inventory_enabled);
        }
    }

    // ── restaurant mode is a feature, not an identity ───────────────────────

    /** A non-restaurant shop that switches a kitchen on IS in restaurant mode. */
    public function test_restaurant_mode_follows_the_kitchen_switches(): void
    {
        $this->setCategory('gym', 'gym');
        DB::table('companies')->where('id', $this->companyId)->update(['restaurant_mode' => false]);
        $this->actAsAdmin();

        $this->post('/pos/features', [
            '_token'        => csrf_token(),
            'fs_present'    => '1',
            'feature_flags' => ['kitchen' => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->company()->restaurant_mode,
            'A gym that opens a kitchen must get the kitchen module, category or not.');

        // ...and the same shop can drop it again.
        $this->post('/pos/features', [
            '_token'        => csrf_token(),
            'fs_present'    => '1',
            'feature_flags' => ['inventory' => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->company()->restaurant_mode,
            'Switching the kitchen off must clear restaurant mode too.');
    }

    /** A restaurant that switches its kitchen off is no longer in that mode. */
    public function test_a_restaurant_may_switch_its_kitchen_off(): void
    {
        $this->setCategory('restaurant', 'restaurant');
        DB::table('companies')->where('id', $this->companyId)->update(['restaurant_mode' => true]);
        $this->actAsAdmin();

        $this->post('/pos/features', [
            '_token'        => csrf_token(),
            'fs_present'    => '1',
            'feature_flags' => ['customer_profile' => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->company()->restaurant_mode,
            'restaurant_mode must never outlive the switches it stands for.');
    }

    /** Reset-to-defaults keeps the shop's own category and re-derives the mode. */
    public function test_reset_to_defaults_keeps_a_legacy_shop_on_its_category(): void
    {
        $this->setCategory('pharmacy', 'pharmacy');
        DB::table('companies')->where('id', $this->companyId)->update(['restaurant_mode' => true]);
        $this->actAsAdmin();

        $this->post('/pos/features/reset', [
            '_token'            => csrf_token(),
            // Even an explicit category on the wire must not move the shop.
            'business_category' => 'restaurant',
        ])->assertSessionHasNoErrors();

        $company = $this->company();
        $this->assertSame('pharmacy', $company->business_category,
            'Reset must not silently move a shop onto a different business.');
        $flags = $company->feature_flags ?? [];
        $this->assertTrue((bool) ($flags['prescription'] ?? false),
            'The pharmacy preset must still apply its own defaults.');
        $this->assertFalse((bool) $company->restaurant_mode,
            'A pharmacy has no kitchen, so reset must clear restaurant mode.');
    }

    // ── what a brand-new shop starts on ─────────────────────────────────────

    /** The category chosen at signup arrives with its own modules switched on. */
    public function test_registration_configures_the_shop_from_its_category(): void
    {
        $salon = PosFeatureService::registrationAttributes('salon');
        $this->assertSame('salon', $salon['business_category']);
        $this->assertTrue((bool) $salon['feature_flags']['service_jobs'],
            'A salon must open with service jobs already on.');
        $this->assertFalse($salon['restaurant_mode'],
            'A salon is not a restaurant.');

        $hotel = PosFeatureService::registrationAttributes('hotel');
        $this->assertTrue($hotel['restaurant_mode'],
            'A hotel runs a kitchen, so it gets the restaurant module — not just restaurants do.');

        $pharmacy = PosFeatureService::registrationAttributes('pharmacy');
        $this->assertTrue((bool) $pharmacy['feature_flags']['prescription'],
            'A pharmacy must open with prescriptions on, whichever panel it signed up on.');
        $this->assertTrue((bool) $pharmacy['inventory_enabled'],
            'The master inventory switch must mirror the preset.');

        $general = PosFeatureService::registrationAttributes('general');
        $this->assertSame('general', $general['business_category'],
            'The catch-all must still be STORED, or the shop resolves to a restaurant.');
        $this->assertFalse($general['restaurant_mode']);

        $this->assertSame([], PosFeatureService::registrationAttributes(null),
            'Nothing at all to go on configures nothing.');
        $this->assertSame([], PosFeatureService::registrationAttributes('not_a_business'));
    }

    // ── registration ────────────────────────────────────────────────────────

    /**
     * The master COLUMNS are derived from the flags we store — on BOTH panels.
     *
     * The FBR sale screen computes its own restaurant mode from exactly these
     * flags, so an FBR-only exception would just let the column drift away from
     * the screen. (FBR's per-item Store notes ride 'kitchen_notes', which is
     * deliberately not one of them.)
     */
    public function test_master_columns_are_derived_from_the_stored_flags(): void
    {
        $on = PosFeatureService::masterSwitches(['kitchen' => true, 'inventory' => true]);
        $this->assertTrue($on['restaurant_mode']);
        $this->assertTrue($on['inventory_enabled']);

        $off = PosFeatureService::masterSwitches(['kitchen_notes' => true]);
        $this->assertFalse($off['restaurant_mode'],
            'Store notes are not a kitchen.');
        $this->assertFalse($off['inventory_enabled']);

        foreach (['kot', 'tables'] as $flag) {
            $this->assertTrue(PosFeatureService::masterSwitches([$flag => true])['restaurant_mode'],
                "'$flag' alone is enough to be running a restaurant floor.");
        }
    }

    // ── one derivation entry point, whichever surface flips the modules ─────

    /**
     * The SaaS admin's override page must resolve dependencies BEFORE deriving.
     *
     * It used to derive the master columns from the RAW ticked boxes: ticking
     * KOT without Kitchen marked the shop as a restaurant while the runtime
     * (which always resolves) switched every restaurant feature back off — a
     * restaurant dashboard and restaurant-only report blocks for a shop with
     * no kitchen anywhere.
     */
    public function test_an_admin_save_resolves_dependencies_before_deriving_the_masters(): void
    {
        $this->setCategory('gym', 'gym');
        DB::table('companies')->where('id', $this->companyId)->update(['restaurant_mode' => false]);

        $this->adminSavesFeatures(['kot' => '1']);

        $company = $this->company();
        $flags = $company->feature_flags ?? [];
        $this->assertFalse((bool) ($flags['kot'] ?? false),
            'KOT cannot be stored ON while its kitchen is OFF.');
        $this->assertFalse((bool) $company->restaurant_mode,
            'A shop the runtime gives no restaurant feature must not be marked a restaurant.');

        // Ticking the parent too is honoured exactly as before.
        $this->adminSavesFeatures(['kot' => '1', 'kitchen' => '1']);

        $company = $this->company();
        $this->assertTrue((bool) ($company->feature_flags['kot'] ?? false));
        $this->assertTrue((bool) $company->restaurant_mode);
    }

    // ── the inventory switches keep the feature map in step ────────────────

    /**
     * The SaaS admin company page's "Inventory module" button must stick.
     *
     * It wrote only the master column, so the very next features save — which
     * re-derives that column FROM the stored feature map — flipped it straight
     * back (the dual-switch trap).
     */
    public function test_the_admin_company_page_inventory_switch_survives_a_features_save(): void
    {
        $this->clearInventory();

        app(\App\Http\Controllers\AdminController::class)->toggleInventory(
            Request::create('/admin/company/' . $this->companyId . '/toggle-inventory', 'POST'),
            $this->company()
        );

        $this->assertInventoryStuck('The admin company page switch');
    }

    /** The PRA inventory page's own toggle endpoint must stick the same way. */
    public function test_the_pra_inventory_toggle_survives_a_features_save(): void
    {
        $this->clearInventory();
        $this->actAsAdmin();

        app(\App\Http\Controllers\PosInventoryController::class)->toggleInventory(
            Request::create('/pos/inventory/toggle', 'POST')
        );

        $this->assertInventoryStuck('The PRA inventory page switch');
    }

    /** …and it refuses a cashier, exactly like its sibling on /pos/settings. */
    public function test_a_cashier_cannot_flip_the_pra_inventory_toggle(): void
    {
        $cashierId = DB::table('users')->insertGetId([
            'name'       => 'Cashier',
            'email'      => 'cashier@pracategories.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->companyId,
            'role'       => 'company_user',
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs(User::find($cashierId), 'pos');
        app()->instance('currentCompanyId', $this->companyId);

        $before = (bool) $this->company()->inventory_enabled;

        try {
            app(\App\Http\Controllers\PosInventoryController::class)->toggleInventory(
                Request::create('/pos/inventory/toggle', 'POST')
            );
            $this->fail('A cashier must not be able to switch the inventory module.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame($before, (bool) $this->company()->inventory_enabled,
            'A refused toggle must change nothing at all.');
    }

    /**
     * The FBR Features card's Store Slip switch canonicalizes too.
     *
     * Turning the slip off clears the per-item store-notes flag; storing that
     * map WITHOUT resolving it left a historically inconsistent shop (KOT on
     * with no kitchen) still deriving restaurant_mode = true, while the runtime
     * resolved every one of its restaurant features back off.
     */
    public function test_switching_the_store_slip_off_stores_a_canonical_map(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'product_type'            => 'fbrpos',
            'kitchen_printer_enabled' => true,
            'feature_flags'           => json_encode([
                'kot' => true, 'kitchen' => false, 'kitchen_notes' => true,
            ]),
            'restaurant_mode'         => true,
        ]);
        $this->actingAs(User::find($this->adminUserId), 'fbrpos');
        app()->instance('currentCompanyId', $this->companyId);

        $response = app(\App\Http\Controllers\FbrPosController::class)->updateFbrFeatureToggle(
            Request::create('/fbr-pos/settings/feature-toggle', 'POST', [
                'feature' => 'store_slip',
                'enabled' => '0',
            ])
        );
        $this->assertSame(200, $response->getStatusCode());

        $company = $this->company();
        $flags = $company->feature_flags ?? [];
        $this->assertFalse((bool) ($flags['kitchen_notes'] ?? false),
            'Store notes die with the slip they print on.');
        $this->assertFalse((bool) ($flags['kot'] ?? false),
            'A stored map must never keep a child ON while its parent is OFF.');
        $this->assertFalse((bool) $company->restaurant_mode,
            'The master column follows the map that was actually stored.');
    }

    /** The FBR Stock page's "Stock tracking" toggle writes both surfaces too. */
    public function test_the_fbr_stock_toggle_keeps_the_feature_map_in_step(): void
    {
        $this->clearInventory();
        DB::table('companies')->where('id', $this->companyId)->update(['product_type' => 'fbrpos']);
        $this->actingAs(User::find($this->adminUserId), 'fbrpos');

        app(\App\Http\Controllers\FbrPosStockController::class)->toggle(
            Request::create('/fbr-pos/stock/toggle', 'POST', ['enabled' => '1'])
        );

        $company = $this->company();
        $this->assertTrue((bool) $company->inventory_enabled);
        $this->assertTrue((bool) ($company->feature_flags['inventory'] ?? false),
            'The FBR Stock switch must write the feature flag, not just the column.');
        // The shared derivation every feature save runs must agree with it.
        $this->assertTrue(
            PosFeatureService::featureUpdates($company->feature_flags ?? [])['inventory_enabled'],
            'The next save that re-derives the column from the map must keep stock tracking ON.'
        );
    }

    // ── a plan-locked shop may still switch its kitchen OFF ────────────────

    /**
     * Switching a feature OFF is always allowed — locking only blocks ON.
     *
     * The save PRESERVED the stored restaurant flags in both directions, so a
     * shop whose plan no longer carries the module could never close its
     * kitchen: restaurant_mode stayed on for good and every surface reading
     * that column (dashboard, day-close, transactions filter, receipt
     * settings, Customize cards, survey audience) kept treating it as a
     * restaurant while the features themselves were masked off.
     */
    public function test_a_plan_locked_shop_can_switch_its_kitchen_off_but_not_on(): void
    {
        $this->setCategory('restaurant', 'restaurant');
        DB::table('companies')->where('id', $this->companyId)->update([
            'is_internal_account' => false,
            'feature_flags'       => json_encode(['kitchen' => true, 'kot' => true, 'tables' => true]),
            'restaurant_mode'     => true,
        ]);
        PosFeatureService::flushGateCaches();
        $this->assertFalse(PosFeatureService::restaurantAllowed($this->company()),
            'A shop with no plan and no internal flag must be restaurant-locked.');

        $this->actAsAdmin();
        $this->post('/pos/features', [
            '_token'        => csrf_token(),
            'fs_present'    => '1',
            'feature_flags' => ['inventory' => '1'],
        ])->assertSessionHasNoErrors();

        $company = $this->company();
        foreach (['kitchen', 'kot', 'tables'] as $flag) {
            $this->assertFalse((bool) ($company->feature_flags[$flag] ?? false),
                "A locked shop must be able to switch '$flag' off.");
        }
        $this->assertFalse((bool) $company->restaurant_mode,
            'The master column follows, so the shop genuinely stops being a restaurant.');

        // …but it still cannot switch one back ON while the plan lacks the module.
        $this->post('/pos/features', [
            '_token'        => csrf_token(),
            'fs_present'    => '1',
            'feature_flags' => ['kitchen' => '1'],
        ])->assertSessionHasNoErrors();

        $company = $this->company();
        $this->assertFalse((bool) ($company->feature_flags['kitchen'] ?? false),
            'A locked shop must never be able to switch a restaurant feature ON.');
        $this->assertFalse((bool) $company->restaurant_mode);
    }

    /** An untouched locked shop keeps its configuration for a later upgrade. */
    public function test_a_locked_shop_that_saves_nothing_keeps_its_kitchen(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'is_internal_account' => false,
            'feature_flags'       => json_encode(['kitchen' => true, 'kot' => true]),
            'restaurant_mode'     => true,
        ]);
        PosFeatureService::flushGateCaches();
        $this->actAsAdmin();

        // No fs_present and no feature_flags = this request never carried the
        // block (stale form / another block's save): the stored map stands.
        $this->post('/pos/features', [
            '_token'         => csrf_token(),
            'auto_print_kot' => '1',
        ])->assertSessionHasNoErrors();

        $company = $this->company();
        $this->assertTrue((bool) ($company->feature_flags['kitchen'] ?? false),
            'A save that never carried the features block must not wipe them.');
        $this->assertTrue((bool) $company->restaurant_mode);
    }

    // ── nothing reads a master COLUMN off the features object ──────────────

    /**
     * restaurant_mode is a COLUMN, not a feature flag.
     *
     * forCompany() only ever carries the individual flags, so
     * `$features->restaurant_mode` was permanently false — on the PRA
     * universal sale screen that silently hid the whole Order Sound control
     * (the chime for a new waiter order) for every restaurant, forever.
     */
    public function test_no_surface_reads_restaurant_mode_off_the_features_object(): void
    {
        $roots = [app_path(), resource_path('views')];
        $hits  = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }
                if (preg_match('/features(\s*)?->restaurant_mode/i', (string) file_get_contents($file->getPathname()))) {
                    $hits[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $hits,
            'The features object has no restaurant_mode — decide from kitchen/kot/tables instead: '
            . implode(', ', $hits));

        // The Order Sound control is back on the real switches.
        $screen = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $this->assertMatchesRegularExpression(
            '/@if\(\(\$features->kitchen \?\? false\) \|\| \(\$features->kot \?\? false\) \|\| \(\$features->tables \?\? false\)\)/',
            $screen,
            'The Order Sound block must render for a shop that actually runs a kitchen.'
        );
    }

    /** Every restaurant surface asks the same three switches. */
    public function test_the_waiter_gate_uses_the_real_switches(): void
    {
        $gate = file_get_contents(
            (new \ReflectionClass(\App\Http\Controllers\RestaurantWaiterController::class))->getFileName()
        );
        $this->assertDoesNotMatchRegularExpression('/\$features->restaurant_mode/', $gate,
            'The waiter gate must read the resolved kitchen/kot/tables switches only.');
        foreach (['tables', 'kot', 'kitchen'] as $flag) {
            $this->assertStringContainsString('$features->' . $flag, $gate,
                "The waiter gate must keep deciding on '$flag'.");
        }
    }

    /** Neither signup page may hard-code its own category list. */
    public function test_the_signup_pickers_are_generated_from_the_panel_lists(): void
    {
        $pages = [
            'pra' => resource_path('views/pos/auth/register.blade.php'),
            'fbr' => resource_path('views/fbr-pos/auth/register.blade.php'),
        ];

        foreach ($pages as $panel => $path) {
            $blade = file_get_contents($path);
            $this->assertStringContainsString("categories('$panel')", $blade,
                "The $panel signup picker must be generated from PANEL_CATEGORIES.");

            foreach (PosFeatureService::allCategoryDefaults() as $key => $ignored) {
                $this->assertStringNotContainsString("posType = '$key'", $blade,
                    "The $panel picker must not hard-code '$key' — that is how the "
                    . 'offered list drifted from the validator in the first place.');
            }
        }
    }

    /**
     * Each panel's signup validates against ITS OWN offered list.
     *
     * Hard-coded `in:` lists were how the two panels drifted apart in the first
     * place, so what the page offers and what the validator accepts must come
     * from the same place.
     */
    public function test_each_panel_validates_signup_against_its_own_list(): void
    {
        $pra = file_get_contents((new \ReflectionClass(\App\Http\Controllers\PosAuthController::class))->getFileName());
        $this->assertStringContainsString("categories('pra')", $pra,
            'PRA signup must validate pos_type against the PRA service list.');

        $fbr = file_get_contents((new \ReflectionClass(\App\Http\Controllers\FbrPosAuthController::class))->getFileName());
        $this->assertStringContainsString("categories('fbr')", $fbr,
            'FBR signup must validate pos_type against the FBR goods list.');

        foreach (self::GOODS as $goods) {
            $this->assertNotContains($goods, PosFeatureService::categories('pra'),
                "PRA signup must not accept the goods type '$goods'.");
        }
    }

    // ── the Second Schedule service families (Sep 2026) ─────────────────────

    /**
     * The service families PRA e-IMS lists but our panel had no card for.
     *
     * Without its own type a courier, photo studio, event planner, travel
     * agent, rent-a-car, property dealer, ad agency, software house or
     * security company had nothing matching its work at sign-up and fell
     * through to the restaurant default — opening with a kitchen.
     */
    private const NEW_SERVICES = [
        'courier', 'photography', 'event_management', 'travel_agent',
        'rent_a_car', 'property_dealer', 'advertising', 'it_services',
        'security_services',
    ];

    public function test_new_service_families_are_offered_on_the_pra_panel(): void
    {
        $offered = PosFeatureService::categories('pra');

        foreach (self::NEW_SERVICES as $service) {
            $this->assertContains($service, $offered,
                "'$service' must be pickable on the PRA panel.");
            $this->assertNotContains($service, PosFeatureService::categories('fbr'),
                "'$service' is a service, so it must not appear on the goods panel.");

            $meta = PosFeatureService::presetMeta($service);
            $this->assertNotSame('', trim((string) ($meta['label'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['icon'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['description'] ?? '')));

            foreach (['en', 'rur', 'ur'] as $locale) {
                $key = 'pos.auth_bt_' . $service;
                $this->assertNotSame($key, __($key, [], $locale),
                    "'$service' needs a sign-up label in '$locale'.");
            }
        }
    }

    /** None of them is a kitchen business, so none may open in restaurant mode. */
    public function test_new_service_families_never_switch_on_a_kitchen(): void
    {
        foreach (self::NEW_SERVICES as $service) {
            $defaults = PosFeatureService::defaultsForCategory($service);

            foreach (PosFeatureService::RESTAURANT_FLAGS as $kitchenFlag) {
                $this->assertFalse((bool) ($defaults[$kitchenFlag] ?? false),
                    "'$service' is not a kitchen business — '$kitchenFlag' must stay off.");
            }
            $this->assertFalse(PosFeatureService::restaurantModeFrom($defaults),
                "'$service' must never boot into restaurant mode.");

            $this->assertTrue((bool) ($defaults['service_jobs'] ?? false),
                "'$service' sells work, so service jobs must be on.");
            $this->assertTrue((bool) ($defaults['customer_profile'] ?? false),
                "'$service' bills named customers, so customer profiles must be on.");
        }
    }

    /** A service item gets fields about the work, not leftover retail fields. */
    public function test_new_service_families_get_service_shaped_product_fields(): void
    {
        $goodsOnlyFields = [
            'batch_number', 'expiry_date', 'drug_type', 'prescription_required',
            'size', 'color', 'season', 'imei', 'serial_number', 'part_number',
        ];
        $map = \App\Models\PosProduct::categoryFields();

        foreach (self::NEW_SERVICES as $service) {
            $this->assertArrayHasKey($service, $map,
                "'$service' must have a product-field map, even an empty one.");

            foreach ($goodsOnlyFields as $goodsField) {
                $this->assertNotContains($goodsField, $map[$service],
                    "'$service' sells a service — '$goodsField' does not belong on its form.");
            }
        }

        $this->assertContains('vehicle_make', $map['rent_a_car'],
            'A rent-a-car item is a vehicle.');
        $this->assertContains('service_duration', $map['photography'],
            'A shoot is billed by how long it runs.');
    }

    // ── full PRA service coverage (Sep 2026) ────────────────────────────────

    /**
     * The only PRA types that may own a kitchen.
     *
     * PRA taxes SERVICES, and only a handful of those services cook. Every
     * other offered type — clinic, courier, consultant, cargo agent, tailor —
     * must be unable to derive restaurant mode from its own defaults, which is
     * exactly what used to happen when an unlisted trade fell through to the
     * restaurant default at sign-up.
     */
    private const FOOD_TYPES = [
        'restaurant', 'cafe', 'quick_service', 'hotel', 'marquee', 'catering',
    ];

    /** Every offered PRA type is a real, complete, nameable business type. */
    public function test_every_offered_pra_type_resolves_to_a_real_preset_and_label(): void
    {
        foreach (PosFeatureService::categories('pra') as $type) {
            $defaults = PosFeatureService::defaultsForCategory($type);
            $this->assertNotSame([], $defaults,
                "'$type' is offered at sign-up but has no preset defaults.");

            $meta = PosFeatureService::presetMeta($type);
            foreach (['label', 'description', 'icon'] as $piece) {
                $this->assertNotSame('', trim((string) ($meta[$piece] ?? '')),
                    "'$type' is offered but has no $piece — the shop would see a raw slug.");
            }
            // A raw slug leaking through as the label is the exact failure the
            // shop reports as "my business type shows as some code".
            $this->assertNotSame($type, $meta['label'],
                "'$type' shows its own slug as its label.");
            $this->assertStringNotContainsString('_', $meta['label'],
                "'$type' label looks like a slug, not a business name.");

            $this->assertNotContains($type, self::GOODS,
                "'$type' is a goods business and cannot be a PRA taxpayer.");
        }
    }

    /** No non-food PRA type may boot with a kitchen, KOT tickets or tables. */
    public function test_no_non_food_pra_type_can_derive_restaurant_mode(): void
    {
        foreach (PosFeatureService::categories('pra') as $type) {
            if (in_array($type, self::FOOD_TYPES, true)) {
                continue;
            }

            $defaults = PosFeatureService::defaultsForCategory($type);
            foreach (PosFeatureService::RESTAURANT_FLAGS as $kitchenFlag) {
                $this->assertFalse((bool) ($defaults[$kitchenFlag] ?? false),
                    "'$type' does not cook — '$kitchenFlag' must stay off.");
            }
            $this->assertFalse(PosFeatureService::restaurantModeFrom($defaults),
                "'$type' must never open in restaurant mode.");
            $this->assertFalse(
                (bool) (PosFeatureService::masterSwitches($defaults)['restaurant_mode'] ?? false),
                "'$type' must not have its restaurant_mode master column set."
            );
        }
    }

    /**
     * Every offered type on EITHER panel is nameable in all three languages and
     * has its own auto-image hint.
     */
    public function test_every_offered_type_is_named_three_ways_and_has_an_image_hint(): void
    {
        $keywords = new \ReflectionMethod(\App\Services\ProductImageService::class, 'categoryKeywords');
        $keywords->setAccessible(true);
        $generic = $keywords->invoke(null, 'retail');

        foreach (['pra', 'fbr'] as $panel) {
            foreach (PosFeatureService::categories($panel) as $type) {
                foreach (['en', 'rur', 'ur'] as $locale) {
                    $key = 'pos.auth_bt_' . $type;
                    $label = __($key, [], $locale);
                    $this->assertNotSame($key, $label,
                        "'$type' needs a sign-up name in '$locale'.");
                    $this->assertNotSame('', trim((string) $label));
                }

                if ($type === 'retail') {
                    continue;
                }
                $this->assertNotSame($generic, $keywords->invoke(null, $type),
                    "'$type' falls back to generic retail image keywords — "
                    . 'auto-fetched pictures would not match the trade.');
                $this->assertNotSame('', trim(PosFeatureService::categorySearchTerms($type)),
                    "'$type' cannot be found by typing in the sign-up filter.");
            }
        }
    }

    /** Urdu names for the new list carry no Latin words. */
    public function test_urdu_business_type_names_are_free_of_latin_words(): void
    {
        foreach (PosFeatureService::categories('pra') as $type) {
            $urdu = (string) __('pos.auth_bt_' . $type, [], 'ur');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $urdu,
                "'$type' has no real Urdu name.");
        }
    }

    /** The honest "my business is not listed" card exists and works. */
    public function test_the_catch_all_service_type_is_offered_and_resolves(): void
    {
        $this->assertContains('other_service', PosFeatureService::categories('pra'),
            'A service business with no matching family must be able to say so.');
        $this->assertNotContains('other_service', PosFeatureService::categories('fbr'));

        $defaults = PosFeatureService::defaultsForCategory('other_service');
        $this->assertNotSame([], $defaults);
        $this->assertFalse(PosFeatureService::restaurantModeFrom($defaults));
        $this->assertTrue((bool) $defaults['service_jobs']);

        $this->assertNotSame('other_service', PosFeatureService::presetMeta('other_service')['label']);
    }

    /** Grouping the picker may never hide an offered type. */
    public function test_the_picker_groups_cover_every_offered_type_exactly_once(): void
    {
        foreach (['pra', 'fbr'] as $panel) {
            $groups  = PosFeatureService::categoryGroups($panel);
            $grouped = array_merge(...array_values($groups));

            $offered = PosFeatureService::categories($panel);
            sort($offered);
            $seen = $grouped;
            sort($seen);

            $this->assertSame($offered, $seen,
                "The $panel picker groups must contain exactly the offered list.");
            $this->assertSame(count($grouped), count(array_unique($grouped)),
                "A type appears in two $panel families.");

            foreach (array_keys($groups) as $heading) {
                foreach (['en', 'rur', 'ur'] as $locale) {
                    $key = 'pos.auth_btg_' . $heading;
                    $this->assertNotSame($key, __($key, [], $locale),
                        "Family heading '$heading' needs wording in '$locale'.");
                }
            }
        }
    }

    /** The longer PRA picker keeps its filter box and its mobile grid rule. */
    public function test_the_pra_picker_survives_the_longer_list(): void
    {
        $blade = file_get_contents(resource_path('views/pos/auth/register.blade.php'));

        $this->assertStringContainsString("categoryGroups('pra')", $blade,
            'The PRA picker must group the tiles from the shared family map.');
        $this->assertStringContainsString('auth_bt_filter_ph', $blade,
            'The PRA picker needs a type-to-filter box at this size.');
        // mobile.css collapses a raw grid-cols-3 to ONE column below 640px on
        // guest layouts — without the keep class the whole catalogue stacks.
        $this->assertStringContainsString('grid-cols-3-keep', $blade,
            'The tile grid must keep three columns on a phone.');

        // The FBR picker stays short and ungrouped.
        $fbr = file_get_contents(resource_path('views/fbr-pos/auth/register.blade.php'));
        $this->assertStringNotContainsString("categoryGroups(", $fbr,
            'The goods panel keeps its current short list.');
    }

    /** The SaaS admin's Industry Preset picker accepts every newly offered type. */
    public function test_the_admin_preset_picker_offers_and_accepts_the_new_types(): void
    {
        $company = \App\Models\Company::find($this->companyId);
        $allowed = PosFeatureService::categoriesForCompany($company);

        foreach (PosFeatureService::categories('pra') as $type) {
            $this->assertContains($type, $allowed,
                "A super admin must be able to file this shop as '$type'.");
        }

        // The admin handler must derive its `in:` rule from that same list —
        // a hard-coded list is how the panels drifted apart before.
        $admin = file_get_contents(
            (new \ReflectionClass(\App\Http\Controllers\AdminController::class))->getFileName()
        );
        $this->assertStringContainsString('categoriesForCompany($company)', $admin,
            'The admin category validator must read the shared offered list.');
    }

    /** Existing shops on retired or legacy types resolve exactly as before. */
    public function test_legacy_and_retired_types_are_untouched_by_the_new_list(): void
    {
        foreach (array_keys(PosFeatureService::LEGACY_CATEGORIES) as $legacy) {
            $this->assertNotContains($legacy, PosFeatureService::categories('pra'),
                "'$legacy' is retired — it must not reappear on the sign-up list.");
            $this->assertNotSame([], PosFeatureService::defaultsForCategory($legacy),
                "'$legacy' must still resolve for the shops sitting on it.");
        }

        // Widening the wording of a live card must not change what it switches on.
        $this->assertTrue((bool) PosFeatureService::defaultsForCategory('salon')['service_jobs']);
        $this->assertTrue((bool) PosFeatureService::defaultsForCategory('workshop')['inventory']);
        $this->assertTrue((bool) PosFeatureService::defaultsForCategory('courier')['delivery']);
    }

    // ── however the shop came into existence, its type configures it ────────

    /** Create a company the way SaaS admin staff do, from the admin panel. */
    private function adminCreatesCompany(array $fields): Company
    {
        $adminId = DB::table('admin_users')->insertGetId([
            'name'       => 'Staff',
            'email'      => 'staff' . uniqid() . '@pracategories.test',
            'password'   => bcrypt('secret'),
            'role'       => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs(\App\Models\AdminUser::find($adminId), 'admin');

        $unique = uniqid();
        app(\App\Http\Controllers\SaasAdmin\AdminCompanyController::class)->store(
            Request::create('/admin/companies', 'POST', array_merge([
                'name'           => 'Admin Made Shop',
                'owner_name'     => 'Shop Owner',
                'email'          => "shop$unique@pracategories.test",
                'status'         => 'approved',
                'admin_name'     => 'Shop Admin',
                'admin_email'    => "shopadmin$unique@pracategories.test",
                'admin_password' => 'secret123',
            ], $fields))
        );

        return Company::orderByDesc('id')->first();
    }

    /**
     * A POS company created from the SaaS admin panel opens on its trade.
     *
     * Staff create companies by hand all the time (sales, migration, a shop
     * that phoned in). That path asked for no business type and stored none, so
     * the owner signed in to a POS with every module off — the very thing the
     * signup preset was built to end. It now applies the SAME shared preset,
     * so the two ways into the product cannot drift apart.
     */
    public function test_an_admin_created_pos_company_opens_on_its_business_type(): void
    {
        $company = $this->adminCreatesCompany(['product_type' => 'pos', 'pos_type' => 'cafe']);

        $this->assertSame('cafe', $company->business_category,
            'The type staff picked must be stored, not just asked for.');
        $this->assertSame('cafe', $company->pos_type);

        $signup = PosFeatureService::registrationAttributes('cafe');
        $this->assertSame($signup['feature_flags'], $company->feature_flags,
            'An admin-created shop must get exactly what the same type gives a shop that signs itself up.');
        $this->assertTrue((bool) ($company->feature_flags['kitchen'] ?? false),
            'A cafe runs a kitchen, so it opens with one.');
        $this->assertTrue((bool) $company->restaurant_mode,
            'The restaurant master switch must agree with the modules.');
        $this->assertTrue((bool) $company->inventory_enabled,
            'The inventory master switch must agree with the modules.');
    }

    /** The FBR panel's own list applies the same way. */
    public function test_an_admin_created_fbr_pos_company_opens_on_its_business_type(): void
    {
        $company = $this->adminCreatesCompany(['product_type' => 'fbrpos', 'pos_type' => 'pharmacy']);

        $this->assertSame('pharmacy', $company->business_category);
        $this->assertTrue((bool) ($company->feature_flags['prescription'] ?? false),
            'An FBR pharmacy must open with prescriptions on.');
        $this->assertTrue((bool) $company->inventory_enabled);
        $this->assertFalse((bool) $company->restaurant_mode,
            'A pharmacy has no kitchen.');
    }

    /** Staff who skip the picker get the general/unclassified type, not nothing. */
    public function test_an_admin_created_pos_company_defaults_to_the_general_type(): void
    {
        $company = $this->adminCreatesCompany(['product_type' => 'pos']);

        $this->assertSame('general', $company->business_category,
            'A shop nobody classified must still be STORED as general, or it resolves as a restaurant.');
        $this->assertSame('general', PosFeatureService::resolveCategory($company));
        $this->assertFalse((bool) $company->restaurant_mode,
            'The catch-all must not hand an unclassified shop a kitchen.');
    }

    /** A type from the other panel is refused, not silently stored. */
    public function test_the_admin_create_form_offers_only_the_panel_it_is_creating_on(): void
    {
        $this->assertContains('general', PosFeatureService::choosableCategories('pra'),
            'The catch-all must be pickable on both panels.');
        $this->assertContains('general', PosFeatureService::choosableCategories('fbr'));
        $this->assertNotContains('pharmacy', PosFeatureService::choosableCategories('pra'),
            'PRA cannot tax goods, so a PRA company must not be creatable as a pharmacy.');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->adminCreatesCompany(['product_type' => 'pos', 'pos_type' => 'pharmacy']);
    }

    /** Every other product is created exactly as it was before. */
    public function test_an_admin_created_digital_invoice_company_is_untouched(): void
    {
        $company = $this->adminCreatesCompany(['product_type' => 'di', 'pos_type' => 'cafe']);

        $this->assertNull($company->business_category,
            'Digital Invoice has no business type — creating one must store none.');
        $this->assertNull($company->pos_type);
        $this->assertNull($company->feature_flags,
            'A DI company must not be handed a POS module map.');
        $this->assertFalse((bool) $company->inventory_enabled);
        $this->assertFalse((bool) $company->restaurant_mode);
    }

    // ── filling in the shops that never got a module map ────────────────────

    /** Run the one-time fill-in exactly as a deployment would. */
    private function runTheFillIn(): void
    {
        (require database_path('migrations/2026_10_09_000000_backfill_business_type_presets.php'))->up();
    }

    /** The columns the fill-in is allowed to touch, for a before/after capture. */
    private function configurationOf(int $companyId): array
    {
        $row = DB::table('companies')
            ->select('feature_flags', 'business_category', 'pos_type', 'inventory_enabled', 'restaurant_mode')
            ->where('id', $companyId)->first();

        return (array) $row;
    }

    private function seedCompany(array $attributes): int
    {
        return DB::table('companies')->insertGetId(array_merge([
            'name'           => 'Seeded Shop',
            'product_type'   => 'pos',
            'status'         => 'approved',
            'company_status' => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $attributes));
    }

    /**
     * A shop that registered before the preset existed is filled in.
     *
     * It told us its business at signup, but only the type was written — the
     * module map is simply ABSENT, so the runtime resolves every module off and
     * the shop is still on the empty POS the preset was meant to end.
     */
    public function test_the_fill_in_configures_a_shop_that_never_had_a_module_map(): void
    {
        $byCategory = $this->seedCompany([
            'business_category' => 'restaurant', 'pos_type' => 'restaurant', 'feature_flags' => null,
        ]);
        // Pre-split shop: the choice only ever reached pos_type.
        $byPosType = $this->seedCompany([
            'business_category' => null, 'pos_type' => 'salon', 'feature_flags' => null,
        ]);

        $this->runTheFillIn();

        $restaurant = Company::find($byCategory);
        $preset = PosFeatureService::registrationAttributes('restaurant')['feature_flags'];
        foreach ($preset as $flag => $on) {
            if ($flag === 'inventory' || in_array($flag, PosFeatureService::RESTAURANT_FLAGS, true)) {
                continue; // master-bearing: pinned to the shop's own switches
            }
            $this->assertSame($on, $restaurant->feature_flags[$flag] ?? null,
                "The fill-in must hand the shop its type's '$flag'.");
        }

        $salon = Company::find($byPosType);
        $this->assertTrue((bool) ($salon->feature_flags['service_jobs'] ?? false),
            'A shop with only its registration pos_type must be filled in from that.');
        $this->assertFalse((bool) $salon->restaurant_mode,
            'A salon is not a restaurant.');
    }

    /**
     * The fill-in never switches a MASTER module on behind a working shop.
     *
     * Inventory changes how every bill is made (stock deduction, out-of-stock
     * refusals) and the kitchen adds KOT printing and table screens. A shop that
     * has traded for months without them did not ask for them this morning — a
     * live deploy did exactly that once, and the settings-regression guard is
     * what caught it.
     */
    public function test_the_fill_in_never_switches_a_master_module_on(): void
    {
        $id = $this->seedCompany([
            'business_category' => 'restaurant', 'pos_type' => 'restaurant',
            'feature_flags'     => null,
            'inventory_enabled' => false,
            'restaurant_mode'   => false,
        ]);

        $this->runTheFillIn();

        $company = Company::find($id);
        $this->assertFalse((bool) $company->inventory_enabled,
            'A trading shop must not find stock tracking switched on by a deploy.');
        $this->assertFalse((bool) $company->restaurant_mode,
            'Nor a kitchen it never asked for.');
        $this->assertFalse((bool) ($company->feature_flags['inventory'] ?? false),
            'The map must agree with the column it was pinned to.');
        foreach (PosFeatureService::RESTAURANT_FLAGS as $flag) {
            $this->assertFalse((bool) ($company->feature_flags[$flag] ?? false),
                "'$flag' rides restaurant mode and must stay off with it.");
        }
        $this->assertTrue((bool) ($company->feature_flags['customer_profile'] ?? false),
            'Everything the type implies that is NOT a master switch is still filled in.');
    }

    /** A module the shop already uses is never taken away by the fill-in. */
    public function test_the_fill_in_never_switches_a_live_module_off(): void
    {
        $id = $this->seedCompany([
            'business_category' => 'salon', 'pos_type' => 'salon',
            'feature_flags'     => null,
            'inventory_enabled' => true,
        ]);

        $this->runTheFillIn();

        $company = Company::find($id);
        $this->assertTrue((bool) $company->inventory_enabled,
            'A shop tracking stock today must keep it, even though the salon preset alone would not switch it on.');
        $this->assertTrue((bool) ($company->feature_flags['inventory'] ?? false),
            'The map and the master column must agree — the dual-switch trap.');
    }

    /** Anything already configured — or unresolvable — is left completely alone. */
    public function test_the_fill_in_leaves_every_configured_shop_untouched(): void
    {
        $untouchable = [
            'a shop with modules on' => $this->seedCompany([
                'business_category' => 'restaurant', 'pos_type' => 'restaurant',
                'feature_flags'     => json_encode(['inventory' => true]),
                'inventory_enabled' => true,
            ]),
            // Everything deliberately switched off is a decision, not an absence.
            'a shop with everything off' => $this->seedCompany([
                'business_category' => 'restaurant', 'pos_type' => 'restaurant',
                'feature_flags'     => json_encode(array_fill_keys(PosFeatureService::ALL_FLAGS, false)),
            ]),
            'a shop with an empty map' => $this->seedCompany([
                'business_category' => 'cafe', 'pos_type' => 'cafe',
                'feature_flags'     => json_encode([]),
            ]),
            // Configured, on a retired goods category, and plan-locked out of
            // the restaurant module: its stored configuration still stands.
            'a plan-locked shop on a retired category' => $this->seedCompany([
                'business_category'   => 'pharmacy', 'pos_type' => 'pharmacy',
                'feature_flags'       => json_encode(['kitchen' => true]),
                'restaurant_mode'     => true,
                'is_internal_account' => false,
            ]),
            'a shop on an unknown type' => $this->seedCompany([
                'business_category' => 'not_a_business', 'pos_type' => 'not_a_business',
                'feature_flags'     => null,
            ]),
            'a Digital Invoice company' => $this->seedCompany([
                'product_type' => 'di', 'business_category' => null, 'pos_type' => null,
                'feature_flags' => null,
            ]),
            // DI signups carry a registration pos_type of their own; it must
            // not turn into a POS configuration they never asked for.
            'a Digital Invoice company with a registration type' => $this->seedCompany([
                'product_type' => 'di', 'business_category' => null, 'pos_type' => 'general',
                'feature_flags' => null,
            ]),
        ];

        $before = array_map(fn ($id) => $this->configurationOf($id), $untouchable);

        $this->runTheFillIn();

        foreach ($untouchable as $what => $id) {
            $this->assertSame($before[$what], $this->configurationOf($id),
                "The fill-in must not move $what.");
        }
    }

    /** Running it twice changes nothing the second time. */
    public function test_the_fill_in_is_safe_to_run_again(): void
    {
        $id = $this->seedCompany([
            'business_category' => 'grocery', 'pos_type' => 'grocery', 'feature_flags' => null,
        ]);

        $this->runTheFillIn();
        $afterFirst = $this->configurationOf($id);

        $this->runTheFillIn();

        $this->assertSame($afterFirst, $this->configurationOf($id),
            'A second run must be a no-op — the shop now HAS a map of its own.');
    }

    // ── schema / seed ───────────────────────────────────────────────────────

    private function seedShop(): void
    {
        $this->companyId = DB::table('companies')->insertGetId([
            'name'                => 'Service Shop',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'active',
            'is_internal_account' => true,
            'business_category'   => 'salon',
            'pos_type'            => 'salon',
            'feature_flags'       => json_encode(['inventory' => true]),
            'pos_setup_completed' => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->adminUserId = DB::table('users')->insertGetId([
            'name'       => 'Admin',
            'email'      => 'admin@pracategories.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->companyId,
            'role'       => 'company_admin',
            'pos_role'   => 'admin',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('approved');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->string('pos_type')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('use_universal_pos')->default(false);
            $t->string('pos_ui_density')->nullable();
            $t->boolean('pos_setup_completed')->default(false);
            $t->decimal('pos_tax_rate_cash', 5, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 5, 2)->nullable();
            $t->boolean('auto_print_kot')->default(false);
            $t->boolean('kot_reprint_enabled')->default(false);
            $t->boolean('kot_last_addon_enabled')->default(false);
            $t->boolean('pos_guided_flow_enabled')->default(false);
            $t->boolean('kds_enabled')->default(false);
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('print_on_hold')->default(false);
            $t->boolean('print_on_pay')->default(false);
            $t->boolean('dine_in_auto_kot')->default(false);
            $t->boolean('pos_kot_full_mode')->default(false);
            $t->boolean('delivery_kot_after_payment')->default(false);
            $t->boolean('kot_on_final_if_unsent')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            // Written by the SaaS admin's company-create path (Task 1562).
            $t->string('owner_name')->nullable();
            $t->string('email')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('province')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('website')->nullable();
            $t->unsignedBigInteger('franchise_id')->nullable();
            $t->decimal('standard_tax_rate', 5, 2)->nullable();
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->string('fbr_pos_environment')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->string('pra_environment')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        // The SaaS admin panel's own tables — company creation writes an audit
        // trail under the acting admin and records the credentials it used.
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role', 30)->default('admin');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('action');
            $t->string('target_type')->nullable();
            $t->unsignedBigInteger('target_id')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('registered_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('credential_type', 20);
            $t->string('credential_value', 191);
            $t->string('product_type', 20)->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['credential_type', 'credential_value']);
        });

        // Empty: every new company falls back to a plan-less trial row.
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->boolean('is_trial')->default(false);
            $t->timestamps();
        });

        // Read by the Customize page's Sales Tax Rates card.
        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 5, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // The SaaS-admin surfaces write an audit + security trail on every save.
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('sha256_hash')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        // Empty on purpose: a shop with no subscription and no internal flag is
        // exactly the plan-locked case the restaurant gate has to handle.
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->string('billing_cycle')->nullable();
            $t->decimal('discount_percent', 5, 2)->default(0);
            $t->decimal('final_price', 12, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->nullable();
            $t->timestamp('override_until')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });
    }
}
