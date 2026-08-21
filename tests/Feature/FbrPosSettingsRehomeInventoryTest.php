<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS settings re-home — nothing lost (Phase A).
 *
 * The FBR POS settings were reorganised to mirror the PRA POS Customize page:
 * day-close-shaped settings (the pending-bills policy + the cashier day-close
 * toggle) were pulled out of the "Sale & Billing" mash and the FBR Settings
 * page into their own "Local Bills & Day-Close" section on the Customize hub.
 *
 * This is a RE-HOME, not a rewrite: every POST route, input name, presence
 * marker and gate must survive untouched. This test is the proof of that — it
 * renders both settings surfaces as the shop owner and asserts that EVERY
 * settings form action / input name that existed before the move is still
 * rendered somewhere, and that the two moved settings now render in their new
 * home (Customize) and no longer on the page they left (FBR Settings).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/FbrPosSettingsRehomeInventoryTest.php --testdox
 */
class FbrPosSettingsRehomeInventoryTest extends TestCase
{
    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        // planAllows() memoizes per company id in a process-lived static array.
        PosFeatureService::flushGateCaches();
        $this->buildSchema();
        $this->seedShop();
    }

    /** Render the Customize hub as the owner. */
    private function customizeHtml(): string
    {
        return $this->actingAs($this->admin, 'fbrpos')
            ->get('/fbr-pos/customize')
            ->assertOk()
            ->getContent();
    }

    /** Render the FBR Settings page as the owner. */
    private function settingsHtml(): string
    {
        return $this->actingAs($this->admin, 'fbrpos')
            ->get('/fbr-pos/settings')
            ->assertOk()
            ->getContent();
    }

    // ── Every Customize-owned setting endpoint still renders on Customize ────

    /**
     * The full set of JSON toggle endpoints + form actions the Customize hub
     * owned before the move. Each must still appear in the rendered hub — an
     * endpoint that stops rendering is a setting that became unreachable.
     */
    public function test_every_customize_setting_endpoint_still_renders_on_the_hub(): void
    {
        $html = $this->customizeHtml();

        $endpoints = [
            // Appearance & Experience
            '/fbr-pos/settings/theme',
            '/fbr-pos/settings/guided-flow',
            '/fbr-pos/settings/whatsapp-bill-toggle',
            '/fbr-pos/settings/dashboard-style',
            '/fbr-pos/settings/default-language',
            // FBR Features
            '/fbr-pos/settings/feature-toggle',
            // Sale & Billing
            '/fbr-pos/settings/quick-type',
            '/fbr-pos/settings/cash-received-toggle',
            '/fbr-pos/settings/receipt-autoclose',
            '/fbr-pos/api/toggle-auto-kot',
            '/fbr-pos/settings/kot-reprint-toggle',
            '/fbr-pos/settings/inventory-toggle',
            '/fbr-pos/settings/restock-toggle',
            // Local Bills & Day-Close (cashier day-close moved here, still same URL)
            '/fbr-pos/settings/cashier-dayclose-toggle',
        ];

        foreach ($endpoints as $url) {
            $this->assertStringContainsString($url, $html,
                "Customize hub must still expose the [$url] settings endpoint");
        }
    }

    /**
     * The default-language form still POSTs the three language buttons with the
     * default_language input, and the feature-toggle card still names all three
     * FBR-owned features.
     */
    public function test_language_and_feature_toggle_inputs_survive(): void
    {
        $html = $this->customizeHtml();

        $this->assertStringContainsString('name="default_language"', $html,
            'The shop default-language buttons must still carry default_language');
        foreach (['rur', 'ur', 'en'] as $lang) {
            $this->assertStringContainsString('value="' . $lang . '"', $html,
                "The default-language button for [$lang] must still render");
        }

        foreach (['store_slip', 'delivery', 'store_notes'] as $feature) {
            $this->assertStringContainsString("featSave('$feature'", $html,
                "The FBR feature toggle for [$feature] must still render on the hub");
        }
    }

    // ── The two MOVED day-close settings landed in their new home ───────────

    /**
     * The pending-bills day-close policy MOVED from the FBR Settings page onto
     * the Customize hub, but with its POST target, presence marker and input
     * name untouched.
     */
    public function test_pending_bills_policy_moved_to_customize_with_same_form_contract(): void
    {
        $html = $this->customizeHtml();

        $this->assertStringContainsString('name="dayclose_pending_update"', $html,
            'The pending-bills policy presence marker must render on Customize now');
        $this->assertStringContainsString('name="pending_policy"', $html,
            'The pending-bills policy radio input must render on Customize now');
        $this->assertStringContainsString('value="carry"', $html);
        $this->assertStringContainsString('value="finalize"', $html);
        // Its form must still target the fbrpos.settings handler (route path).
        $this->assertStringContainsString('action="' . route('fbrpos.settings') . '"', $html,
            'The moved policy form must still POST to the fbrpos.settings handler');
    }

    /** ...and it no longer renders on the page it left (no duplicate writer). */
    public function test_pending_bills_policy_no_longer_on_the_fbr_settings_page(): void
    {
        $html = $this->settingsHtml();
        $this->assertStringNotContainsString('name="dayclose_pending_update"', $html,
            'The pending-bills policy must NOT also render on FBR Settings (single home)');
    }

    /**
     * ...and the moved form must actually SAVE.
     *
     * pos_dayclose_provisional_action is shared with the PRA day-close
     * vocabulary and is deliberately NOT in Company::$fillable, so the
     * original $company->update([...]) write was silently dropped: the page
     * flashed "saved" while the stored value never moved. Caught in a real
     * browser, not by any test — hence this one (Aug 2026).
     */
    public function test_pending_bills_policy_actually_persists(): void
    {
        $col = 'pos_dayclose_provisional_action';
        $this->assertNotSame('finalize', \Illuminate\Support\Facades\DB::table('companies')
            ->where('id', $this->company->id)->value($col));

        $this->actingAs($this->admin, 'fbrpos')
            ->post(route('fbrpos.settings'), [
                'dayclose_pending_update' => '1',
                'pending_policy' => 'finalize',
            ])->assertRedirect();

        $this->assertSame('finalize', \Illuminate\Support\Facades\DB::table('companies')
            ->where('id', $this->company->id)->value($col),
            'Saving the pending-bills policy must actually reach the companies row');

        // Two-way: switching back must stick as well.
        $this->actingAs($this->admin, 'fbrpos')
            ->post(route('fbrpos.settings'), [
                'dayclose_pending_update' => '1',
                'pending_policy' => 'carry',
            ])->assertRedirect();

        $this->assertSame('carry', \Illuminate\Support\Facades\DB::table('companies')
            ->where('id', $this->company->id)->value($col));
    }

    /** The cashier day-close toggle moved into the Local Bills & Day-Close section. */
    public function test_cashier_dayclose_toggle_renders_in_the_new_dayclose_section(): void
    {
        $html = $this->customizeHtml();
        $this->assertStringContainsString('/fbr-pos/settings/cashier-dayclose-toggle', $html,
            'The cashier day-close toggle endpoint must still render on the hub');

        // It sits in the new Local Bills & Day-Close section, after the pending
        // policy block and before the card hub — the same slot PRA uses.
        $secPos      = strpos($html, __('pos.sec_local_bills_dayclose'));
        $togglePos   = strpos($html, '/fbr-pos/settings/cashier-dayclose-toggle');
        $this->assertNotFalse($secPos, 'The Local Bills & Day-Close heading must render');
        $this->assertNotFalse($togglePos);
        $this->assertLessThan($togglePos, $secPos,
            'The cashier day-close toggle must sit inside the Local Bills & Day-Close section');
    }

    // ── The FBR Settings page keeps everything that genuinely belongs there ──

    /**
     * The FBR-integration settings (submission mode, config, agent-key
     * regenerate, PIN, peti rate) all stay on the FBR Settings page with their
     * exact presence markers and input names.
     */
    public function test_fbr_settings_page_keeps_its_own_settings(): void
    {
        $html = $this->settingsHtml();

        // Submission mode + connection config
        $this->assertStringContainsString('name="fbr_pos_environment"', $html);
        $this->assertStringContainsString('name="fbr_connection_mode"', $html);
        $this->assertStringContainsString('name="fbr_pos_id"', $html);
        $this->assertStringContainsString('name="fbr_pos_token"', $html);
        $this->assertStringContainsString('name="fbr_access_code"', $html);
        // Confidential PIN
        $this->assertStringContainsString('name="pin_update"', $html);
        $this->assertStringContainsString('name="confidential_pin"', $html);
        // Peti (wholesale) rate
        $this->assertStringContainsString('name="peti_rate_update"', $html);
        $this->assertStringContainsString('name="peti_rate_enabled"', $html);
        $this->assertStringContainsString('name="peti_margin_pct"', $html);

        // Every one of those forms targets the fbrpos.settings handler.
        $this->assertStringContainsString('action="' . route('fbrpos.settings') . '"', $html);
    }

    /**
     * The agent-key regenerate form is a compatibility handler shown only in
     * fiscal-device mode with a key already minted (its own long-standing
     * gate). In that state it still renders on the FBR Settings page with its
     * presence marker untouched — the move never touched it.
     */
    public function test_agent_key_regenerate_form_survives_in_fiscal_device_mode(): void
    {
        $this->company->update([
            'fbr_connection_mode' => 'fiscal_device',
            'agent_api_key'       => 'tnk_existing_key_value',
            'agent_enabled'       => true,
        ]);

        $html = $this->settingsHtml();
        $this->assertStringContainsString('name="regenerate_agent_key"', $html,
            'The agent-key regenerate compatibility form must still render in fiscal-device mode');
        $this->assertStringContainsString('action="' . route('fbrpos.settings') . '"', $html);
    }

    // ── Phase B: search / scroll-spy nav must never hide a setting ──────────

    /**
     * Phase B added an instant live search + a sticky scroll-spy section-nav to
     * the Customize hub. Both filter cards CLIENT-SIDE via Alpine x-show only —
     * the server always renders every card, and x-show merely toggles CSS
     * display. This test is the guarantee that the filtering machinery can never
     * cost the owner a setting: with the search/nav markup present in the page,
     * EVERY settings input name + form action that a shopkeeper writes through
     * must still be found in the rendered HTML (rendered-but-filtered is fine;
     * missing is a failure).
     */
    public function test_search_and_nav_cannot_hide_any_setting_from_the_owner(): void
    {
        $html = $this->customizeHtml();

        // The Phase B search + scroll-spy nav are actually on the page…
        $this->assertStringContainsString('initSpy()', $html,
            'The scroll-spy nav initialiser must be wired on the hub');
        $this->assertStringContainsString('hit(kw.all)', $html,
            'The no-results empty state must be driven by the all-page keyword blob');
        $this->assertStringContainsString(__('pos.cust_search_placeholder'), $html,
            'The live search box must render on the hub');

        // …yet every writable setting the owner reaches is STILL in the DOM.
        // JSON toggle endpoints + inline form POST targets.
        $endpoints = [
            '/fbr-pos/settings/theme',
            '/fbr-pos/settings/guided-flow',
            '/fbr-pos/settings/whatsapp-bill-toggle',
            '/fbr-pos/settings/dashboard-style',
            '/fbr-pos/settings/default-language',
            '/fbr-pos/settings/feature-toggle',
            '/fbr-pos/settings/quick-type',
            '/fbr-pos/settings/cash-received-toggle',
            '/fbr-pos/settings/receipt-autoclose',
            '/fbr-pos/api/toggle-auto-kot',
            '/fbr-pos/settings/kot-reprint-toggle',
            '/fbr-pos/settings/inventory-toggle',
            '/fbr-pos/settings/restock-toggle',
            '/fbr-pos/settings/cashier-dayclose-toggle',
        ];
        foreach ($endpoints as $url) {
            $this->assertStringContainsString($url, $html,
                "Search/nav filtering must not remove the [$url] endpoint from the DOM");
        }

        // Named inputs the owner writes through (radios, buttons, hidden markers).
        $inputs = [
            'name="default_language"',
            'name="pending_policy"',
            'name="dayclose_pending_update"',
        ];
        foreach ($inputs as $needle) {
            $this->assertStringContainsString($needle, $html,
                "Search/nav filtering must not remove [$needle] from the DOM");
        }

        // The FBR feature toggles (store_slip / delivery / store_notes) too.
        foreach (['store_slip', 'delivery', 'store_notes'] as $feature) {
            $this->assertStringContainsString("featSave('$feature'", $html,
                "Search/nav filtering must not remove the [$feature] toggle from the DOM");
        }
    }

    /**
     * The filtering is display-only: every setting card's visibility is gated by
     * an Alpine hit() call, never re-parented or removed. The five scroll-spy
     * section anchors the nav jumps to must all exist so no section is orphaned.
     */
    public function test_all_scrollspy_section_anchors_render(): void
    {
        $html = $this->customizeHtml();

        foreach (['id="appearance"', 'id="features"', 'id="sale-billing"', 'id="dayclose"', 'id="shortcuts"'] as $anchor) {
            $this->assertStringContainsString($anchor, $html,
                "The scroll-spy anchor [$anchor] must render so the nav can reach it");
        }
    }

    // ── The gate the move must not weaken: cashier is 403 on Customize ──────

    public function test_a_cashier_still_cannot_reach_the_customize_hub(): void
    {
        $cashier = User::create([
            'name'       => 'FBR Cashier',
            'email'      => 'cashier@fbrrehome.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'pos_user',
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
        ]);

        $this->actingAs($cashier, 'fbrpos')
            ->get('/fbr-pos/customize')
            ->assertForbidden();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function seedShop(): void
    {
        $this->company = Company::create([
            'name'                => 'FBR Re-home Shop',
            'product_type'        => 'fbrpos',
            'status'              => 'active',
            'company_status'      => 'active',
            'fbr_pos_enabled'     => true,
            'fbr_connection_mode' => 'cloud',
            'fbr_pos_environment' => 'sandbox',
            'feature_flags'       => [],
        ]);

        \DB::table('pricing_plans')->insert([
            'id'                 => 1,
            'name'               => 'FBR Pro',
            'product_type'       => 'fbrpos',
            'is_trial'           => 0,
            'restaurant_enabled' => 0,
            'kot_enabled'        => 1,
            'riders_enabled'     => 1,
            'offline_enabled'    => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        \DB::table('subscriptions')->insert([
            'id'              => 1,
            'company_id'      => $this->company->id,
            'pricing_plan_id' => 1,
            'active'          => 1,
            'ends_at'         => now()->addYear(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->admin = User::create([
            'name'       => 'FBR Admin',
            'email'      => 'admin@fbrrehome.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_pos_environment')->nullable();
            $t->string('fbr_pos_id')->nullable();
            $t->text('fbr_pos_token')->nullable();
            $t->text('fbr_access_code')->nullable();
            $t->string('fbr_registration_no')->nullable();
            $t->string('fbr_business_name')->nullable();
            $t->string('ntn')->nullable();
            $t->string('province')->nullable();
            $t->string('address')->nullable();
            $t->string('confidential_pin')->nullable();
            // Desktop Agent pairing
            $t->string('agent_api_key')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->string('agent_version')->nullable();
            // Feature switches the Customize hub owns
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('auto_print_kot')->default(false);
            $t->boolean('kot_reprint_enabled')->default(true);
            $t->text('feature_flags')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            $t->text('pos_printer_settings')->nullable();
            // Appearance / experience
            $t->string('pos_theme')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->boolean('pos_guided_flow_enabled')->default(true);
            $t->boolean('pos_quick_type_enabled')->default(false);
            $t->boolean('pos_cash_received_enabled')->default(false);
            $t->integer('pos_receipt_autoclose_seconds')->default(10);
            $t->boolean('pos_restock_on_void')->default(true);
            $t->boolean('pos_cashier_dayclose')->default(false);
            $t->boolean('pos_whatsapp_bill_enabled')->default(true);
            $t->boolean('pos_whatsapp_bill_auto_open')->default(false);
            $t->string('default_language')->nullable();
            // Day-close pending policy (moved onto Customize)
            $t->string('pos_dayclose_provisional_action')->nullable();
            // Peti (wholesale) rate
            $t->boolean('fbr_peti_rate_enabled')->default(false);
            $t->decimal('fbr_peti_margin_pct', 6, 2)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('fbr_pos_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('status')->nullable();
            $t->string('response_code')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('restaurant_enabled')->default(false);
            $t->boolean('kot_enabled')->default(false);
            $t->boolean('riders_enabled')->default(false);
            $t->boolean('offline_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
    }
}
