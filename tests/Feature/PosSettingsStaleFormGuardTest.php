<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PRA POS settings pages must not silently switch a shop's options off (Task 1393).
 *
 * Same failure the PRA Receipt Settings page hit (Task 1377): the handler rebuilt
 * an options block wholesale from checkbox presence, with no proof the request had
 * actually carried that block. Unchecked checkboxes send nothing, so an OUTDATED
 * copy of the form (served from the service-worker runtime cache) and a form with
 * everything unticked look identical on the wire — and the outdated one wiped
 * every toggle it did not know about.
 *
 * Each page now carries a hidden per-panel marker, with a fallback that treats any
 * of that block's own fields as proof it was submitted so scripted and legacy
 * POSTs keep working. These tests lock both halves of that rule per page:
 *   - a POST missing a block leaves the stored block untouched, and
 *   - a POST that DOES carry the block can still turn everything off.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/PosSettingsStaleFormGuardTest.php --testdox
 */
class PosSettingsStaleFormGuardTest extends TestCase
{
    private int $companyId;
    private int $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();
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

    // ── /pos/features — the Customize wizard ────────────────────────────────

    /** A POST carrying no feature_flags at all must NOT switch every feature off. */
    public function test_stale_features_post_does_not_wipe_the_feature_flag_map(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'feature_flags'     => json_encode(['kitchen' => true, 'inventory' => true, 'customers' => true]),
            'inventory_enabled' => true,
        ]);

        $this->actAsAdmin();
        // Outdated copy of the wizard: category only, not one feature_flags key.
        $this->post('/pos/features', [
            '_token'            => csrf_token(),
            'business_category' => 'retail',
        ]);

        $company = $this->company();
        $flags   = $company->feature_flags ?? [];
        $this->assertTrue((bool) ($flags['kitchen'] ?? false),
            'A form that never carried the feature map must leave kitchen alone');
        $this->assertTrue((bool) ($flags['inventory'] ?? false));
        $this->assertTrue((bool) $company->inventory_enabled,
            'The inventory column follows the flag — it must not be switched off either');
    }

    /** Likewise for the kitchen tick-boxes, which live in their own block. */
    public function test_stale_features_post_does_not_wipe_the_kitchen_tickboxes(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'auto_print_kot'          => true,
            'kot_reprint_enabled'     => true,
            'pos_guided_flow_enabled' => true,
            'use_universal_pos'       => true,
        ]);

        $this->actAsAdmin();
        $this->post('/pos/features', [
            '_token'            => csrf_token(),
            'business_category' => 'retail',
        ]);

        $company = $this->company();
        $this->assertTrue((bool) $company->auto_print_kot,
            'auto_print_kot must survive a POST that never carried its block');
        $this->assertTrue((bool) $company->kot_reprint_enabled);
        $this->assertTrue((bool) $company->pos_guided_flow_enabled);
        $this->assertTrue((bool) $company->use_universal_pos,
            'use_universal_pos rides a hidden input — a form without it must not reset it');
    }

    /** The freshly rendered wizard (fs_present) can still turn everything off. */
    public function test_fresh_features_form_can_still_turn_features_off(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'feature_flags'      => json_encode(['kitchen' => true, 'inventory' => true]),
            'inventory_enabled'  => true,
            'auto_print_kot'     => true,
        ]);

        $this->actAsAdmin();
        $this->post('/pos/features', [
            '_token'            => csrf_token(),
            'fs_present'        => '1',
            'business_category' => 'retail',
            // feature_flags + kitchen tick-boxes absent = everything unticked
        ]);

        $company = $this->company();
        $flags   = $company->feature_flags ?? [];
        $this->assertFalse((bool) ($flags['kitchen'] ?? true),
            'Unticking a feature on a freshly rendered wizard must persist');
        $this->assertFalse((bool) $company->inventory_enabled);
        $this->assertFalse((bool) $company->auto_print_kot,
            'Unticking a kitchen preference on a freshly rendered wizard must persist');
    }

    // ── /pos/restaurant/kitchen-settings — KOT settings ─────────────────────

    /** A POST carrying no workflow field must leave the workflow panel alone. */
    public function test_stale_kitchen_settings_post_does_not_wipe_the_workflow_panel(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'kds_enabled'             => true,
            'kitchen_printer_enabled' => true,
            'print_on_hold'           => true,
            'kot_on_final_if_unsent'  => true,
        ]);

        $this->actAsAdmin();
        // Only the print-style panel came back — the workflow panel is missing.
        $this->post('/pos/restaurant/kitchen-settings', [
            '_token'      => csrf_token(),
            'kot_compact' => '1',
        ]);

        $company = $this->company();
        $this->assertTrue((bool) $company->kds_enabled,
            'kds_enabled must survive a POST that never carried the workflow panel');
        $this->assertTrue((bool) $company->kitchen_printer_enabled);
        $this->assertTrue((bool) $company->print_on_hold);
        $this->assertTrue((bool) $company->kot_on_final_if_unsent);
        $this->assertTrue((bool) $company->kot_compact,
            'The panel that WAS submitted must still be written');
    }

    /** And a POST carrying no print-style field must leave that panel alone. */
    public function test_stale_kitchen_settings_post_does_not_wipe_the_print_style_panel(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'kot_compact'            => true,
            'kot_show_customer'      => true,
            'kot_show_kitchen_notes' => true,
            'kot_align_center'       => true,
            'kot_left_margin_mm'     => 5,
        ]);

        $this->actAsAdmin();
        // Pre-print-style form: workflow toggles only.
        $this->post('/pos/restaurant/kitchen-settings', [
            '_token'      => csrf_token(),
            'kds_enabled' => '1',
        ]);

        $company = $this->company();
        $this->assertTrue((bool) $company->kot_compact,
            'kot_compact must survive a POST that never carried the print-style panel');
        $this->assertTrue((bool) $company->kot_show_customer);
        $this->assertTrue((bool) $company->kot_show_kitchen_notes);
        $this->assertTrue((bool) $company->kot_align_center);
        $this->assertSame(5, (int) $company->kot_left_margin_mm,
            'The KOT left margin belongs to that panel and must follow the same guard');
    }

    /** The freshly rendered page (ks_present) can still turn both panels off. */
    public function test_fresh_kitchen_settings_form_can_still_turn_both_panels_off(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'kds_enabled' => true,
            'kot_compact' => true,
        ]);

        $this->actAsAdmin();
        $this->post('/pos/restaurant/kitchen-settings', [
            '_token'     => csrf_token(),
            'ks_present' => '1',
            // every toggle absent = unticked
        ]);

        $company = $this->company();
        $this->assertFalse((bool) $company->kds_enabled,
            'Unticking a workflow toggle on a freshly rendered form must persist');
        $this->assertFalse((bool) $company->kot_compact,
            'Unticking a print-style toggle on a freshly rendered form must persist');
    }

    // ── /pos/printer-settings ───────────────────────────────────────────────

    /** A POST carrying none of the tick-boxes must leave all three alone. */
    public function test_stale_printer_settings_post_does_not_switch_the_tickboxes_off(): void
    {
        $company = $this->company();
        $company->pos_printer_settings = [
            'available_printers'   => [['name' => 'EPSON-80']],
            'receipt_printer'      => 'EPSON-80',
            'kot_printer'          => 'EPSON-80',
            'counter_kot_printer'  => 'EPSON-80',
            'counter_kot_enabled'  => true,
            'silent_print_enabled' => true,
            'print_confirm_ask'    => true,
        ];
        $company->save();

        $this->actAsAdmin();
        $this->post('/pos/printer-settings', ['_token' => csrf_token()]);

        $s = $this->company()->printerSettings();
        $this->assertTrue((bool) $s['silent_print_enabled'],
            'Silent printing must survive a POST that never carried its tick-box');
        $this->assertTrue((bool) $s['print_confirm_ask'],
            'The print-confirm prompt must survive it too');
        $this->assertTrue((bool) $s['counter_kot_enabled'],
            'Counter KOT must survive a POST that never carried its tick-box');
        $this->assertSame('EPSON-80', $s['receipt_printer'],
            'A form that never carried the printer picks must not unset them');
    }

    /** The freshly rendered page (ps_present) can still turn them off. */
    public function test_fresh_printer_settings_form_can_still_turn_silent_print_off(): void
    {
        $company = $this->company();
        $company->pos_printer_settings = [
            'available_printers'   => [['name' => 'EPSON-80']],
            'receipt_printer'      => 'EPSON-80',
            'silent_print_enabled' => true,
            'print_confirm_ask'    => true,
        ];
        $company->save();

        $this->actAsAdmin();
        $this->post('/pos/printer-settings', [
            '_token'          => csrf_token(),
            'ps_present'      => '1',
            'receipt_printer' => 'EPSON-80',
            // silent_print_enabled / print_confirm_ask absent = unticked
        ]);

        $s = $this->company()->printerSettings();
        $this->assertFalse((bool) $s['silent_print_enabled'],
            'Unticking silent printing on a freshly rendered form must persist');
        $this->assertFalse((bool) $s['print_confirm_ask'],
            'Unticking the print-confirm prompt on a freshly rendered form must persist');
    }

    /**
     * The manual PRA tax-rate overrides are number inputs, so the rendered form
     * ALWAYS submits them. A request that carries neither is a stale form, not a
     * shop clearing its rates back to the global default.
     */
    public function test_stale_features_post_does_not_wipe_the_manual_tax_rates(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'pos_tax_rate_cash' => 5.00,
            'pos_tax_rate_card' => 15.00,
        ]);

        $this->actAsAdmin();
        $this->post('/pos/features', [
            '_token'            => csrf_token(),
            'business_category' => 'retail',
        ]);

        $company = $this->company();
        // (sqlite hands decimals back unpadded — compare the value, not its format)
        $this->assertSame(5.0, (float) $company->pos_tax_rate_cash,
            'A form that never carried the rate inputs must leave the cash rate alone');
        $this->assertSame(15.0, (float) $company->pos_tax_rate_card);
    }

    /** Submitting the rate inputs BLANK is the real "clear back to default" path. */
    public function test_fresh_features_form_can_still_clear_the_manual_tax_rates(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'pos_tax_rate_cash' => 5.00,
            'pos_tax_rate_card' => 15.00,
        ]);

        $this->actAsAdmin();
        $this->post('/pos/features', [
            '_token'            => csrf_token(),
            'fs_present'        => '1',
            'business_category' => 'retail',
            'pos_tax_rate_cash' => '',
            'pos_tax_rate_card' => '',
        ]);

        $company = $this->company();
        $this->assertNull($company->pos_tax_rate_cash,
            'Blanking the field on a freshly rendered form must clear the override');
        $this->assertNull($company->pos_tax_rate_card);
    }

    // ── /pos/public-profile (rendered inside the Business Profile page) ─────

    /** A POST carrying no pp_* field must not switch the public page off. */
    public function test_stale_public_profile_post_does_not_switch_the_public_page_off(): void
    {
        $company = $this->company();
        $company->public_profile_settings = [
            'enabled' => true, 'show_phone' => true, 'show_address' => true,
            'show_menu' => true, 'hours_text' => '9am - 11pm', 'about_text' => 'Karyana store',
        ];
        $company->public_profile_slug = 'abc123';
        $company->save();

        $this->actAsAdmin();
        $this->post('/pos/public-profile', ['_token' => csrf_token()]);

        $pp = $this->company()->public_profile_settings ?? [];
        $this->assertTrue((bool) ($pp['enabled'] ?? false),
            'The public page must not be switched off by a POST that never carried the form');
        $this->assertTrue((bool) ($pp['show_phone'] ?? false));
        $this->assertTrue((bool) ($pp['show_menu'] ?? false));
        $this->assertSame('9am - 11pm', $pp['hours_text'] ?? null,
            'Its free-text fields belong to the same block and must not be blanked');
    }

    /** The freshly rendered form (pp_present) can still switch it off. */
    public function test_fresh_public_profile_form_can_still_switch_the_public_page_off(): void
    {
        $company = $this->company();
        $company->public_profile_settings = ['enabled' => true, 'show_phone' => true];
        $company->public_profile_slug = 'abc123';
        $company->save();

        $this->actAsAdmin();
        $this->post('/pos/public-profile', [
            '_token'     => csrf_token(),
            'pp_present' => '1',
            // pp_enabled + every pp_show_* absent = unticked
        ]);

        $pp = $this->company()->public_profile_settings ?? [];
        $this->assertFalse((bool) ($pp['enabled'] ?? true),
            'Unticking the public page on a freshly rendered form must persist');
        $this->assertFalse((bool) ($pp['show_phone'] ?? true));
    }

    // ── /pos/public-profile/menu — the QR menu picker ───────────────────────

    /** A POST carrying no menu picker at all must not delete the shop's QR menu. */
    public function test_stale_menu_post_does_not_delete_the_qr_menu(): void
    {
        $this->seedMenu([1, 2, 3]);

        $this->actAsAdmin();
        // Outdated copy of the page: no marker, not one menu_product_ids[] value.
        $this->post('/pos/public-profile/menu', ['_token' => csrf_token()]);

        $this->assertSame(3, DB::table('pos_menu_items')->where('company_id', $this->companyId)->count(),
            'Every menu row must survive a POST that never carried the picker');
    }

    /** The freshly rendered picker with nothing ticked can still clear the menu. */
    public function test_fresh_menu_form_with_nothing_ticked_can_still_clear_the_qr_menu(): void
    {
        $this->seedMenu([1, 2, 3]);

        $this->actAsAdmin();
        $this->post('/pos/public-profile/menu', [
            '_token'     => csrf_token(),
            'pm_present' => '1',
            // every menu_product_ids[] absent = the shop unticked them all
        ]);

        $this->assertSame(0, DB::table('pos_menu_items')->where('company_id', $this->companyId)->count(),
            'Unticking everything on a freshly rendered picker must still clear the menu');
    }

    /** Legacy POST: the picker's own field is proof enough, no marker needed. */
    public function test_legacy_menu_post_without_marker_still_saves_the_picker(): void
    {
        $this->seedMenu([1, 2, 3]);

        $this->actAsAdmin();
        $this->post('/pos/public-profile/menu', [
            '_token'           => csrf_token(),
            'menu_product_ids' => [2],
        ]);

        $rows = DB::table('pos_menu_items')->where('company_id', $this->companyId)->pluck('pos_product_id')->all();
        $this->assertSame([2], array_map('intval', $rows),
            'A POST that carries the picker still rewrites the menu, marker or not');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Give the shop some products and pin them all to the public menu. */
    private function seedMenu(array $productIds): void
    {
        foreach ($productIds as $i => $id) {
            DB::table('pos_products')->insert([
                'id' => $id, 'company_id' => $this->companyId, 'name' => 'Item ' . $id,
                'price' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pos_menu_items')->insert([
                'company_id' => $this->companyId, 'pos_product_id' => $id,
                'sort_order' => $i, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedShop(): void
    {
        $this->companyId = DB::table('companies')->insertGetId([
            'name'           => 'Stale Form Guard Shop',
            'product_type'   => 'pos',
            'status'         => 'approved',
            'company_status' => 'active',
            // Internal account = every plan gate open, so these tests exercise the
            // stale-form guards themselves and never the Restaurant / QR-Menu plan
            // masks (which would preserve values for an unrelated reason).
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['kitchen' => true]),
            'pos_setup_completed' => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->adminUserId = DB::table('users')->insertGetId([
            'name'       => 'Admin',
            'email'      => 'admin@stalefrom.test',
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
            // Customize wizard
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('use_universal_pos')->default(false);
            $t->string('pos_ui_density')->nullable();
            $t->boolean('pos_setup_completed')->default(false);
            $t->decimal('pos_tax_rate_cash', 5, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 5, 2)->nullable();
            // Kitchen / KOT settings
            $t->boolean('auto_print_kot')->default(false);
            $t->boolean('kot_reprint_enabled')->default(false);
            $t->boolean('pos_guided_flow_enabled')->default(false);
            $t->boolean('kds_enabled')->default(false);
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('print_on_hold')->default(false);
            $t->boolean('print_on_pay')->default(false);
            $t->boolean('dine_in_auto_kot')->default(false);
            $t->boolean('pos_kot_full_mode')->default(false);
            $t->boolean('delivery_kot_after_payment')->default(false);
            $t->boolean('kot_on_final_if_unsent')->default(false);
            $t->boolean('kot_compact')->default(false);
            $t->boolean('kot_show_customer')->default(false);
            $t->boolean('kot_show_orderby')->default(false);
            $t->boolean('kot_show_barcode')->default(false);
            $t->boolean('kot_show_footer')->default(false);
            $t->boolean('kot_show_kitchen_notes')->default(false);
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->timestamp('kot_center_notice_at')->nullable();
            $t->boolean('receipt_align_center')->nullable();
            $t->integer('receipt_left_margin_mm')->default(0);
            // Printer + receipt + public profile
            $t->text('pos_printer_settings')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->text('public_profile_settings')->nullable();
            $t->string('public_profile_slug')->nullable();
            $t->boolean('pos_receipt_show_tax')->default(true);
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('ntn')->nullable();
            $t->softDeletes();
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
            $t->string('language')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('pos_access_overrides')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_head_office')->default(false);
            $t->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        Schema::create('pos_terminals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('terminal_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->boolean('restaurant_enabled')->default(true);
            $t->boolean('deals_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('pos_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('status')->default('active');
            $t->boolean('active')->default(true);
            $t->string('plan_key')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_feature_flags', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('flag');
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('app_updates', function (Blueprint $t) {
            $t->id();
            $t->string('product_type')->default('pos');
            $t->string('title')->nullable();
            $t->text('description')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('user_update_reads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('app_update_id');
            $t->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->decimal('price', 12, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_menu_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_product_id');
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }
}
