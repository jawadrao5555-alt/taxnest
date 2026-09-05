<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
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
            $t->softDeletes();
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
