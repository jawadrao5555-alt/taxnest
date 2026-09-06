<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Services\PosFeatureService;
use App\Services\PosUnitCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Units (UoM) follow the shop's business category — ONE catalogue
 * (PosUnitCatalog) behind every FBR + PRA POS unit surface.
 *
 * Locks:
 *   1. Catalogue integrity: measure constant == measure flags; every
 *      recommended code is a master code; every panel/legacy category has a
 *      list; every master code has a label in en/rur/ur.
 *   2. A pharmacy (FBR) product form lists PCS then STRIP first and still
 *      offers SQM under the secondary "Baqi units" group.
 *   3. A hotel (PRA) lists NGT/DAY first; a general/retail shop keeps the
 *      full goods list with U first; an unmapped PRA category falls back to
 *      NOS-first services.
 *   4. A product with a legacy / off-category / unknown stored code still
 *      renders selected on edit and re-saves unchanged; a made-up code that
 *      is NOT the stored one is rejected; new codes (STRIP, LB…) are accepted.
 *   5. A brand-new product with no unit takes the category default
 *      (pharmacy → PCS, retail → U), on the form AND on Excel import (PRA
 *      hotel → NGT), and an import cell with a new service unit is accepted.
 *   6. Re-filing the company's business category changes the recommended
 *      group with no other setting.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosUnitCatalogTest.php
 */
class PosUnitCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            // Business category drives the unit list (PosUnitCatalog).
            $table->string('business_category')->nullable();
            $table->string('pos_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();
            $table->string('pct_code')->nullable();
            $table->string('sro_reference')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('schedule_type')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('default_price', 12, 2)->nullable();
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('default_tax_rate', 8, 2)->default(0);
            $table->string('tax_type')->default('taxable');
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_price_editable')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('type');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->integer('max_products')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->timestamps();
        });
        // PRA products (Excel import default + legacy NOS/KGS rows).
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->string('description')->nullable();
            $table->string('category')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function makeCompany(string $productType, ?string $category, string $name = 'Shop'): Company
    {
        $id = (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'product_type' => $productType,
            'business_category' => $category,
            'status' => 'active',
            'company_status' => 'active',
            'fbr_pos_enabled' => true,
            'fbr_reporting_enabled' => false,
            'inventory_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $id, 'active' => true, 'override_type' => 'lifetime',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::find($id);
    }

    private function makeAdmin(int $companyId): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Admin ' . $companyId,
            'email' => 'admin' . $companyId . '@uom.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return \App\Models\User::find($id);
    }

    private function makeProduct(int $companyId, string $uom, string $name = 'Item'): int
    {
        return (int) DB::table('products')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'default_price' => 100,
            'tax_type' => 'exempt',
            'default_tax_rate' => 0,
            'is_price_editable' => true,
            'show_on_sale' => true,
            'is_active' => true,
            'uom' => $uom,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Panadol',
            'default_price' => 50,
            'is_price_editable' => 1,
            'tax_type' => 'exempt',
            'default_tax_rate' => 0,
            'is_active' => 1,
            'show_on_sale' => 1,
            'stock_action' => 'none',
        ], $overrides);
    }

    /** Codes in the order the rendered <select> lists them. */
    private function optionOrder(string $html): array
    {
        preg_match('/<select[^>]*name="uom"[^>]*>(.*?)<\/select>/su', $html, $sel);
        $this->assertNotEmpty($sel, 'unit select not rendered');
        preg_match_all('/<option value="([^"]+)"/', $sel[1], $m);
        return $m[1];
    }

    private function recommendedGroup(string $html): array
    {
        preg_match('/<select[^>]*name="uom"[^>]*>(.*?)<\/select>/su', $html, $sel);
        $this->assertNotEmpty($sel, 'unit select not rendered');
        preg_match('/<optgroup label="' . preg_quote(__('pos.uom_group_recommended'), '/') . '">(.*?)<\/optgroup>/su', $sel[1], $g);
        $this->assertNotEmpty($g, 'recommended optgroup missing');
        preg_match_all('/<option value="([^"]+)"/', $g[1], $m);
        return $m[1];
    }

    private function restGroup(string $html): array
    {
        preg_match('/<select[^>]*name="uom"[^>]*>(.*?)<\/select>/su', $html, $sel);
        preg_match('/<optgroup label="' . preg_quote(__('pos.uom_group_rest'), '/') . '">(.*?)<\/optgroup>/su', $sel[1], $g);
        $this->assertNotEmpty($g, 'rest optgroup missing');
        preg_match_all('/<option value="([^"]+)"/', $g[1], $m);
        return $m[1];
    }

    private function selectedOption(string $html): ?string
    {
        preg_match('/<select[^>]*name="uom"[^>]*>(.*?)<\/select>/su', $html, $sel);
        preg_match('/<option value="([^"]+)"[^>]*\sselected/', $sel[1] ?? '', $m);
        return $m[1] ?? null;
    }

    // ── 1. Catalogue integrity ──────────────────────────────────────────────

    public function test_measure_constant_matches_unit_flags(): void
    {
        $this->assertSame(PosUnitCatalog::measureCodesFromFlags(), PosUnitCatalog::MEASURE_CODES);
        $this->assertSame(PosUnitCatalog::MEASURE_CODES, \App\Http\Controllers\FbrPosController::VALUE_MODE_UOMS);
        foreach (['KG', 'GM', 'LTR', 'ML', 'MTR', 'SQM', 'LB', 'KGS', 'HR', 'KM'] as $c) {
            $this->assertTrue(PosUnitCatalog::isMeasure($c), "$c must be a measure unit");
        }
        foreach (['U', 'PCS', 'STRIP', 'NOS', 'NGT', 'HEAD', 'SES', 'DOZ'] as $c) {
            $this->assertFalse(PosUnitCatalog::isMeasure($c), "$c must be a count unit");
        }
    }

    public function test_every_category_has_a_list_of_master_codes_with_three_language_labels(): void
    {
        $master = PosUnitCatalog::validCodes();
        $categories = array_unique(array_merge(
            array_values(PosFeatureService::PANEL_CATEGORIES['pra']),
            array_values(PosFeatureService::PANEL_CATEGORIES['fbr']),
            array_keys(PosFeatureService::LEGACY_CATEGORIES)
        ));
        foreach ($categories as $cat) {
            $this->assertArrayHasKey($cat, PosUnitCatalog::CATEGORY_UNITS, "category '$cat' has no unit list");
        }
        foreach (PosUnitCatalog::CATEGORY_UNITS as $cat => $codes) {
            $this->assertNotEmpty($codes, "category '$cat' list is empty");
            $this->assertSame($codes, array_values(array_unique($codes)), "category '$cat' repeats a code");
            foreach ($codes as $c) {
                $this->assertContains($c, $master, "category '$cat' lists unknown code '$c'");
            }
        }
        // Every FBR goods shop could pick these before — they must all still exist.
        foreach (PosUnitCatalog::GOODS_ALL as $c) {
            $this->assertContains($c, $master);
        }
        foreach (['NOS', 'KGS', 'LTR', 'MTR', 'PCS', 'PKT', 'BOX'] as $c) {
            $this->assertContains($c, $master, "legacy PRA code '$c' dropped");
        }
        foreach (['en', 'rur', 'ur'] as $loc) {
            $lang = require base_path("lang/{$loc}/pos.php");
            foreach (PosUnitCatalog::UNITS as $code => [$suffix]) {
                $this->assertArrayHasKey('uom_' . $suffix, $lang, "lang/{$loc}: missing label for {$code}");
                $this->assertNotSame('', trim((string) $lang['uom_' . $suffix]));
            }
            $this->assertArrayHasKey('uom_group_recommended', $lang);
            $this->assertArrayHasKey('uom_group_rest', $lang);
        }
    }

    public function test_grouping_keeps_every_code_pickable_and_current_present(): void
    {
        $pharmacy = $this->makeCompany('fbrpos', 'pharmacy');
        $g = PosUnitCatalog::groupsFor($pharmacy, 'ZZZ');
        $all = array_merge(array_column($g['recommended'], 'code'), array_column($g['rest'], 'code'));
        $this->assertSame('ZZZ', $g['current']);
        $this->assertContains('ZZZ', $all, 'unknown stored code must stay pickable');
        foreach (PosUnitCatalog::validCodes() as $c) {
            $this->assertContains($c, $all, "$c became unpickable");
        }
        $this->assertCount(count(PosUnitCatalog::validCodes()) + 1, $all, 'a code is listed twice');
        $this->assertSame('PCS', $g['default']);
    }

    // ── 2/3. Category → recommended group ───────────────────────────────────

    public function test_pharmacy_form_lists_pcs_strip_first_and_sqm_in_secondary_group(): void
    {
        $pharmacy = $this->makeCompany('fbrpos', 'pharmacy', 'Shifa Pharmacy');
        $admin = $this->makeAdmin($pharmacy->id);

        $res = $this->actingAs($admin, 'fbrpos')->get('/fbr-pos/products/create');
        $res->assertOk();
        $html = $res->getContent();

        $rec = $this->recommendedGroup($html);
        $this->assertSame(['PCS', 'STRIP'], array_slice($rec, 0, 2));
        $this->assertNotContains('SQM', $rec);
        $this->assertContains('SQM', $this->restGroup($html));
        $this->assertSame('PCS', $this->selectedOption($html), 'new pharmacy product must default to PCS');
        // Nothing an FBR shop could pick before is gone.
        $this->assertSame([], array_diff(PosUnitCatalog::GOODS_ALL, $this->optionOrder($html)));
    }

    public function test_retail_shop_keeps_full_goods_list_u_first(): void
    {
        $retail = $this->makeCompany('fbrpos', 'retail', 'General Store');
        $admin = $this->makeAdmin($retail->id);
        $html = $this->actingAs($admin, 'fbrpos')->get('/fbr-pos/products/create')->assertOk()->getContent();
        $this->assertSame(PosUnitCatalog::GOODS_ALL, $this->recommendedGroup($html));
        $this->assertSame('U', $this->selectedOption($html));
    }

    public function test_hotel_pra_lists_ngt_day_first_and_unmapped_pra_falls_back_to_nos(): void
    {
        $hotel = $this->makeCompany('pos', 'hotel', 'Serena');
        $g = PosUnitCatalog::groupsFor($hotel);
        $this->assertSame(['NGT', 'DAY'], array_slice(array_column($g['recommended'], 'code'), 0, 2));
        $this->assertSame('NGT', PosUnitCatalog::defaultFor($hotel));
        $this->assertContains('NOS', array_column($g['recommended'], 'code'), 'legacy PRA default stays one click away');

        // The shared partial renders the same two groups the PRA modals use.
        $html = view('partials.pos-uom-options', ['uomGroups' => $g, 'uomSelected' => 'KGS'])->render();
        $html = '<select name="uom">' . $html . '</select>';
        $this->assertSame(['NGT', 'DAY'], array_slice($this->recommendedGroup($html), 0, 2));
        $this->assertContains('KGS', $this->restGroup($html));
        $this->assertSame('KGS', $this->selectedOption($html), 'legacy KGS row must render selected');
        $this->assertStringContainsString('NGT — ' . __('pos.uom_ngt'), $html);

        // A PRA category the catalogue does not know → generic services, NOS first.
        $odd = $this->makeCompany('pos', 'some_new_category', 'Odd Shop');
        $this->assertSame('NOS', PosUnitCatalog::defaultFor($odd));
        // An FBR shop with an unknown or missing category → the full goods list, U first.
        $oddFbr = $this->makeCompany('fbrpos', 'some_new_category', 'Odd FBR');
        $this->assertSame(PosUnitCatalog::GOODS_ALL, PosUnitCatalog::recommendedFor($oddFbr));
        $noneFbr = $this->makeCompany('fbrpos', null, 'No Category FBR');
        $this->assertSame('U', PosUnitCatalog::defaultFor($noneFbr));
        $this->assertSame('NOS', PosUnitCatalog::defaultFor(null), 'no company row → PRA services, same as panelFor(null)');
    }

    public function test_changing_business_category_changes_the_group_on_next_load(): void
    {
        $shop = $this->makeCompany('fbrpos', 'retail', 'Changing Shop');
        $admin = $this->makeAdmin($shop->id);
        $this->assertSame('U', $this->selectedOption(
            $this->actingAs($admin, 'fbrpos')->get('/fbr-pos/products/create')->assertOk()->getContent()
        ));
        DB::table('companies')->where('id', $shop->id)->update(['business_category' => 'bakery']);
        PosFeatureService::flushGateCaches();
        $html = $this->actingAs($admin, 'fbrpos')->get('/fbr-pos/products/create')->assertOk()->getContent();
        $this->assertSame(['PCS', 'KG', 'LB'], array_slice($this->recommendedGroup($html), 0, 3));
        $this->assertSame('PCS', $this->selectedOption($html));
    }

    // ── 4. Legacy / off-category / unknown stored code ──────────────────────

    public function test_legacy_and_unknown_stored_codes_render_selected_and_resave(): void
    {
        $pharmacy = $this->makeCompany('fbrpos', 'pharmacy', 'Shifa Pharmacy');
        $admin = $this->makeAdmin($pharmacy->id);
        $legacy = $this->makeProduct($pharmacy->id, 'KGS', 'Glucose Powder');   // catalogue, off-category
        $unknown = $this->makeProduct($pharmacy->id, 'XYZ', 'Odd Item');        // never in any list

        $html = $this->actingAs($admin, 'fbrpos')->get('/fbr-pos/products/' . $legacy . '/edit')->assertOk()->getContent();
        $this->assertSame('KGS', $this->selectedOption($html));
        $this->assertContains('KGS', $this->restGroup($html));

        $html = $this->actingAs($admin, 'fbrpos')->get('/fbr-pos/products/' . $unknown . '/edit')->assertOk()->getContent();
        $this->assertSame('XYZ', $this->selectedOption($html));
        $this->assertContains('XYZ', $this->restGroup($html), 'unknown stored code must stay pickable');

        // Re-saving each with its own stored code keeps it exactly as-is.
        $this->actingAs($admin, 'fbrpos')->put('/fbr-pos/products/' . $legacy, $this->productPayload(['name' => 'Glucose Powder', 'uom' => 'KGS']))
            ->assertSessionHasNoErrors();
        $this->assertSame('KGS', DB::table('products')->where('id', $legacy)->value('uom'));

        $this->actingAs($admin, 'fbrpos')->put('/fbr-pos/products/' . $unknown, $this->productPayload(['name' => 'Odd Item', 'uom' => 'XYZ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('XYZ', DB::table('products')->where('id', $unknown)->value('uom'));

        // Blank unit on edit keeps the stored unit (never silently re-defaults).
        $this->actingAs($admin, 'fbrpos')->put('/fbr-pos/products/' . $unknown, $this->productPayload(['name' => 'Odd Item', 'uom' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('XYZ', DB::table('products')->where('id', $unknown)->value('uom'));

        // A made-up code that is NOT this product's stored one is still rejected.
        $this->actingAs($admin, 'fbrpos')->put('/fbr-pos/products/' . $legacy, $this->productPayload(['name' => 'Glucose Powder', 'uom' => 'FOO']))
            ->assertSessionHasErrors('uom');
        $this->assertSame('KGS', DB::table('products')->where('id', $legacy)->value('uom'));
    }

    // ── 5. New codes accepted; defaults follow the category ─────────────────

    public function test_new_codes_are_accepted_and_blank_unit_takes_category_default(): void
    {
        $pharmacy = $this->makeCompany('fbrpos', 'pharmacy', 'Shifa Pharmacy');
        $admin = $this->makeAdmin($pharmacy->id);

        foreach (['STRIP', 'TUBE', 'PAIR', 'SUIT', 'LB', 'HR', 'DAY', 'NGT', 'HEAD', 'MON', 'KM', 'TRIP', 'SES', 'JOB', 'SQFT', 'NOS', 'KGS', 'strip'] as $code) {
            $this->actingAs($admin, 'fbrpos')
                ->post('/fbr-pos/products', $this->productPayload(['name' => 'Item ' . $code, 'uom' => $code]))
                ->assertSessionHasNoErrors("code {$code} rejected");
            $this->assertSame(strtoupper($code), DB::table('products')->where('company_id', $pharmacy->id)->where('name', 'Item ' . $code)->value('uom'));
        }
        $this->actingAs($admin, 'fbrpos')
            ->post('/fbr-pos/products', $this->productPayload(['name' => 'Bad', 'uom' => 'FOO']))
            ->assertSessionHasErrors('uom');

        // No unit posted → the category's primary unit.
        $this->actingAs($admin, 'fbrpos')
            ->post('/fbr-pos/products', $this->productPayload(['name' => 'No Unit']))
            ->assertSessionHasNoErrors();
        $this->assertSame('PCS', DB::table('products')->where('company_id', $pharmacy->id)->where('name', 'No Unit')->value('uom'));

        $retail = $this->makeCompany('fbrpos', 'retail', 'General Store');
        $retailAdmin = $this->makeAdmin($retail->id);
        $this->actingAs($retailAdmin, 'fbrpos')
            ->post('/fbr-pos/products', $this->productPayload(['name' => 'No Unit']))
            ->assertSessionHasNoErrors();
        $this->assertSame('U', DB::table('products')->where('company_id', $retail->id)->where('name', 'No Unit')->value('uom'));
    }

    public function test_pra_excel_import_accepts_service_units_and_defaults_to_category_unit(): void
    {
        $hotel = $this->makeCompany('pos', 'hotel', 'Serena');
        app()->bind('currentCompanyId', fn () => $hotel->id);
        DB::table('pos_products')->insert([
            'company_id' => $hotel->id, 'name' => 'Old Buffet', 'price' => 900, 'uom' => 'NOS',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Name', 'Price', 'Unit (UOM)'], null, 'A1');
        $sheet->fromArray(['Deluxe Room', 12000, 'NGT'], null, 'A2');
        $sheet->fromArray(['Airport Pickup', 3000, 'trip'], null, 'A3');
        $sheet->fromArray(['Breakfast', 1500, ''], null, 'A4');
        $sheet->fromArray(['Spa', 5000, 'gibberish'], null, 'A5');
        $sheet->fromArray(['Old Buffet', 950, ''], null, 'A6');
        $tmp = tempnam(sys_get_temp_dir(), 'uom') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $upload = new UploadedFile($tmp, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $req = Request::create('/pos/products/import', 'POST');
        $req->files->set('csv_file', $upload);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);
        (new PosController())->importProducts($req);
        @unlink($tmp);

        $uom = fn (string $name) => DB::table('pos_products')->where('company_id', $hotel->id)->where('name', $name)->value('uom');
        $this->assertSame('NGT', $uom('Deluxe Room'), 'new service unit must import');
        $this->assertSame('TRIP', $uom('Airport Pickup'), 'lower-case cell must normalize');
        $this->assertSame('NGT', $uom('Breakfast'), 'blank cell on a NEW row → hotel default');
        $this->assertSame('NGT', $uom('Spa'), 'unknown text is treated as blank, never stored');
        $this->assertSame('NOS', $uom('Old Buffet'), 'blank cell on an EXISTING row keeps its legacy unit');
    }
}
