<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Receipt Content Checklist (Task #292): tick = prints, untick = NEVER prints.
 *
 * Invariants locked:
 *   1. posReceiptStyle() defaults: show_logo=true, show_menu_qr=true (zero change
 *      for companies with no saved setting).
 *   2. show_logo=false overrides logo_finals_only — logo suppressed everywhere.
 *   3. show_logo=true + logo_finals_only=true → logo only on non-provisional bills.
 *   4. show_logo=true + logo_finals_only=false → logo on all bills.
 *   5. show_menu_qr=false returns false (suppresses both Menu QR + JSON QR).
 *   6. show_menu_qr is independent of show_logo.
 *   7. POST to /pos/receipt-settings saves show_logo + show_menu_qr + logo_finals_only.
 *   8. POST without show_logo (checkbox unchecked) stores false.
 *   9. POST without show_menu_qr (checkbox unchecked) stores false.
 *  10. Cashier (posCashierBlocked) cannot POST.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run (strip Postgres env to force SQLite):
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PosReceiptContentChecklistTest.php
 */
class PosReceiptContentChecklistTest extends TestCase
{
    private int $companyId;
    private int $adminUserId;
    private int $cashierUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('pos_theme')->nullable();
            $table->string('pos_dashboard_style')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('fbr_universal_enabled')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->string('default_language')->nullable();
            $table->string('receipt_printer_size')->nullable();
            $table->boolean('pos_receipt_show_tax')->default(true);
            $table->json('invoice_display_prefs')->nullable();
            $table->text('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('ntn')->nullable();
            $table->string('website')->nullable();
            $table->string('business_activity')->nullable();
            $table->string('fbr_registration_no')->nullable();
            $table->string('public_profile_slug')->nullable();
            $table->json('public_profile_settings')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
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
            $table->string('language')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('pos_access_overrides')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('flag');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->default('active');
            $table->boolean('active')->default(true);
            $table->string('plan_key')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // branches: needed by PosAuth → BranchContextService::autoSelectBranch
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        // branch_user pivot: manager branch assignments
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        // pos_terminals: PosAuth resolves terminal_id from session
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('terminal_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // subscriptions: PosAccessService checks subscription status
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        // pricing_plans: subscription plan gates
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->boolean('restaurant_enabled')->default(true);
            $table->boolean('deals_enabled')->default(false);
            $table->timestamps();
        });

        // app_updates: PosAccessService / layout may check for updates
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('product_type')->default('pos');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // user_update_reads: tracks which updates a user has seen
        Schema::create('user_update_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('app_update_id');
            $table->timestamps();
        });

        $company = DB::table('companies')->insertGetId([
            'name'           => 'Test Shop',
            'product_type'   => 'pos',
            'status'         => 'approved',
            'company_status' => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $this->companyId = $company;

        $this->adminUserId = DB::table('users')->insertGetId([
            'name'       => 'Admin',
            'email'      => 'admin@test.com',
            'password'   => Hash::make('password'),
            'company_id' => $this->companyId,
            'pos_role'   => 'admin',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cashierUserId = DB::table('users')->insertGetId([
            'name'       => 'Cashier',
            'email'      => 'cashier@test.com',
            'password'   => Hash::make('password'),
            'company_id' => $this->companyId,
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1–6: Model-level unit tests (no HTTP, pure Company logic)
    // ─────────────────────────────────────────────────────────────────────────

    /** 1. Fresh company (null invoice_display_prefs) gets defaults show_logo=true, show_menu_qr=true. */
    public function test_defaults_are_true_when_no_prefs_saved(): void
    {
        $company = Company::find($this->companyId);
        $style   = $company->posReceiptStyle();

        $this->assertTrue($style['show_logo'],     'show_logo must default to true');
        $this->assertTrue($style['show_menu_qr'],  'show_menu_qr must default to true');
        $this->assertFalse($style['logo_finals_only'], 'logo_finals_only must default to false');
    }

    /** 2. show_logo=false overrides logo_finals_only — logo must be suppressed on ALL bills. */
    public function test_show_logo_false_overrides_logo_finals_only(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode([
                'pos_style' => [
                    'show_logo'       => false,
                    'logo_finals_only'=> true, // sub-option doesn't matter when master=off
                    'show_menu_qr'    => true,
                ],
            ]),
        ]);

        $company = Company::find($this->companyId);
        $style   = $company->posReceiptStyle();

        $this->assertFalse($style['show_logo'],
            'show_logo=false must suppress logo regardless of logo_finals_only');
        // Gate logic (mirrors the blade): show_logo=false → $showLogo=false always
        $logoDataUri        = 'data:image/png;base64,ABC'; // pretend logo exists
        $rcptTopProvisional = true; // even on a provisional bill
        $showLogo = $logoDataUri
            && $style['show_logo']
            && (!$style['logo_finals_only'] || !$rcptTopProvisional);
        $this->assertFalse($showLogo,
            'Logo gate must be false when show_logo=false');

        // Non-provisional bill — still false
        $rcptTopProvisional = false;
        $showLogo = $logoDataUri
            && $style['show_logo']
            && (!$style['logo_finals_only'] || !$rcptTopProvisional);
        $this->assertFalse($showLogo,
            'Logo gate must be false on final bill too when show_logo=false');
    }

    /** 3. show_logo=true + logo_finals_only=true → logo only on non-provisional bills. */
    public function test_show_logo_true_with_logo_finals_only(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode([
                'pos_style' => [
                    'show_logo'        => true,
                    'logo_finals_only' => true,
                    'show_menu_qr'     => true,
                ],
            ]),
        ]);

        $company = Company::find($this->companyId);
        $style   = $company->posReceiptStyle();
        $logo    = 'data:image/png;base64,ABC';

        // Provisional bill → suppressed
        $showLogo = $logo && $style['show_logo'] && (!$style['logo_finals_only'] || !true);
        $this->assertFalse($showLogo, 'Logo must be suppressed on provisional when logo_finals_only=true');

        // Final bill → shown
        $showLogo = $logo && $style['show_logo'] && (!$style['logo_finals_only'] || !false);
        $this->assertTrue($showLogo, 'Logo must show on final bill when logo_finals_only=true');
    }

    /** 4. show_logo=true + logo_finals_only=false → logo on ALL bills. */
    public function test_show_logo_true_logo_finals_only_false(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode([
                'pos_style' => ['show_logo' => true, 'logo_finals_only' => false, 'show_menu_qr' => true],
            ]),
        ]);

        $company  = Company::find($this->companyId);
        $style    = $company->posReceiptStyle();
        $logo     = 'data:image/png;base64,ABC';

        foreach ([true, false] as $isProvisional) {
            $showLogo = $logo && $style['show_logo'] && (!$style['logo_finals_only'] || !$isProvisional);
            $this->assertTrue($showLogo,
                "Logo must show on " . ($isProvisional ? 'provisional' : 'final') . " bill when logo_finals_only=false");
        }
    }

    /** 5. show_menu_qr=false → $showReceiptQr is false. */
    public function test_show_menu_qr_false_suppresses_qr(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode([
                'pos_style' => ['show_logo' => true, 'logo_finals_only' => false, 'show_menu_qr' => false],
            ]),
        ]);

        $company = Company::find($this->companyId);
        $style   = $company->posReceiptStyle();

        $this->assertFalse($style['show_menu_qr'], 'show_menu_qr must return false when saved false');

        $showReceiptQr = (bool) ($style['show_menu_qr'] ?? true);
        $this->assertFalse($showReceiptQr, '$showReceiptQr gate must be false');
    }

    /** 6. show_menu_qr is independent of show_logo. */
    public function test_show_menu_qr_independent_of_show_logo(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode([
                'pos_style' => ['show_logo' => false, 'show_menu_qr' => true],
            ]),
        ]);

        $company = Company::find($this->companyId);
        $style   = $company->posReceiptStyle();

        $this->assertFalse($style['show_logo'],    'show_logo should be false');
        $this->assertTrue($style['show_menu_qr'],  'show_menu_qr should be true even when show_logo=false');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7–10: HTTP POST tests
    // ─────────────────────────────────────────────────────────────────────────

    /** 7. POST with both checkboxes ON saves correct values. */
    public function test_post_saves_show_logo_and_show_menu_qr_on(): void
    {
        $this->actingAs(
            \App\Models\User::find($this->adminUserId), 'pos'
        );

        // Bind currentCompanyId so the controller resolves the company.
        app()->instance('currentCompanyId', $this->companyId);

        $response = $this->post('/pos/receipt-settings', [
            '_token'           => csrf_token(),
            'rp_show_logo'     => '1',
            'rp_show_menu_qr'  => '1',
            // logo_finals_only NOT checked
            'rp_style_bold'    => '1',
            'rp_logo_style'    => 'center',
        ]);

        $response->assertRedirect();

        $prefs = json_decode(
            DB::table('companies')->where('id', $this->companyId)->value('invoice_display_prefs'),
            true
        );

        $this->assertTrue((bool) ($prefs['pos_style']['show_logo'] ?? false),    'show_logo saved true');
        $this->assertTrue((bool) ($prefs['pos_style']['show_menu_qr'] ?? false), 'show_menu_qr saved true');
        $this->assertFalse((bool) ($prefs['pos_style']['logo_finals_only'] ?? false), 'logo_finals_only saved false');
    }

    /** 8. POST without show_logo (checkbox absent) stores false. */
    public function test_post_unchecked_show_logo_stores_false(): void
    {
        $this->actingAs(
            \App\Models\User::find($this->adminUserId), 'pos'
        );
        app()->instance('currentCompanyId', $this->companyId);

        // Only rp_show_menu_qr present — rp_show_logo absent = unchecked.
        // rp_pos_style_present must be present so the handler knows this is a
        // fresh form submission (not a stale/cached form) and writes the checkbox
        // keys from the form instead of preserving the stored values.
        $this->post('/pos/receipt-settings', [
            '_token'               => csrf_token(),
            'rp_pos_style_present' => '1',
            'rp_show_menu_qr'      => '1',
            'rp_style_bold'        => '1',
            'rp_logo_style'        => 'center',
        ]);

        $prefs = json_decode(
            DB::table('companies')->where('id', $this->companyId)->value('invoice_display_prefs'),
            true
        );
        $this->assertFalse((bool) ($prefs['pos_style']['show_logo'] ?? true),
            'show_logo must be false when checkbox absent');
    }

    /** 9. POST without show_menu_qr (checkbox absent) stores false. */
    public function test_post_unchecked_show_menu_qr_stores_false(): void
    {
        $this->actingAs(
            \App\Models\User::find($this->adminUserId), 'pos'
        );
        app()->instance('currentCompanyId', $this->companyId);

        $this->post('/pos/receipt-settings', [
            '_token'               => csrf_token(),
            'rp_pos_style_present' => '1',
            'rp_show_logo'         => '1',
            'rp_style_bold'        => '1',
            'rp_logo_style'        => 'center',
        ]);

        $prefs = json_decode(
            DB::table('companies')->where('id', $this->companyId)->value('invoice_display_prefs'),
            true
        );
        $this->assertFalse((bool) ($prefs['pos_style']['show_menu_qr'] ?? true),
            'show_menu_qr must be false when checkbox absent');
    }

    /**
     * 10. Cashier cannot change receipt settings.
     *
     * PosAuth middleware redirects cashiers to the dashboard (302) before the
     * controller's posCashierBlocked() abort(403) fires. Either outcome (302 or
     * 403) is acceptable — what matters is that the database was NOT modified.
     */
    public function test_cashier_cannot_post_receipt_settings(): void
    {
        // Pre-condition: no prefs saved
        $prefsBefore = DB::table('companies')->where('id', $this->companyId)->value('invoice_display_prefs');

        $this->actingAs(
            \App\Models\User::find($this->cashierUserId), 'pos'
        );
        app()->instance('currentCompanyId', $this->companyId);

        $response = $this->post('/pos/receipt-settings', [
            '_token'          => csrf_token(),
            'rp_show_logo'    => '1',
            'rp_show_menu_qr' => '1',
            'rp_style_bold'   => '1',
            'rp_logo_style'   => 'center',
        ]);

        // Must NOT succeed (2xx); redirect (middleware) or 403 (controller) both fine.
        $this->assertNotContains(
            $response->status(),
            [200, 201, 204],
            'Cashier must not receive a 2xx success response from receipt settings POST'
        );

        // Settings must be unchanged — no prefs written by the cashier.
        $prefsAfter = DB::table('companies')->where('id', $this->companyId)->value('invoice_display_prefs');
        $this->assertEquals($prefsBefore, $prefsAfter,
            'Cashier must not be able to modify invoice_display_prefs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11–13: Stale-form guard, one marker per display set (Task 1377)
    //
    // The Receipt Settings page used to be runtime-cached by the service worker,
    // so a shop could save an OUTDATED copy of the form. Every display block is
    // a wholesale rewrite driven by checkbox presence, so such a POST silently
    // wiped the toggles it did not carry — that is how a company ended up with
    // its whole Local set false and local bills stopped printing the tax line
    // (owner voice note, 21 Aug 2026).
    // ─────────────────────────────────────────────────────────────────────────

    private function actAsAdmin(): void
    {
        $this->actingAs(\App\Models\User::find($this->adminUserId), 'pos');
        app()->instance('currentCompanyId', $this->companyId);
    }

    private function storedPrefs(): array
    {
        return json_decode(
            (string) DB::table('companies')->where('id', $this->companyId)->value('invoice_display_prefs'),
            true
        ) ?: [];
    }

    /** 11. A POST that carries no lp_* fields at all must NOT wipe the stored Local set. */
    public function test_stale_form_without_local_fields_does_not_wipe_the_local_set(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode([
                'pos_local' => ['show_tax' => true, 'show_cashier' => true, 'footer_text' => 'Shukriya'],
            ]),
        ]);

        $this->actAsAdmin();
        // Pre-Local-tab form: rp_* only, not a single lp_* field.
        $this->post('/pos/receipt-settings', [
            '_token'               => csrf_token(),
            'rp_pos_style_present' => '1',
            'rp_show_address'      => '1',
            'rp_show_menu_qr'      => '1',
            'rp_footer_text'       => '',
        ]);

        $local = $this->storedPrefs()['pos_local'] ?? [];
        $this->assertTrue((bool) ($local['show_tax'] ?? false),
            'A form that never carried the Local tab must leave its show_tax alone');
        $this->assertTrue((bool) ($local['show_cashier'] ?? false));
        $this->assertSame('Shukriya', $local['footer_text'] ?? null);
    }

    /** 12. Likewise for the PRA set + its tax column — a stale POST must not blank them. */
    public function test_stale_form_without_pra_fields_does_not_wipe_the_pra_set(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'pos_receipt_show_tax'  => true,
            'invoice_display_prefs' => json_encode([
                'pos' => ['show_ntn' => true, 'show_email' => true],
            ]),
        ]);

        $this->actAsAdmin();
        // Only the global style block — no rp_* content fields at all.
        $this->post('/pos/receipt-settings', [
            '_token'               => csrf_token(),
            'rp_pos_style_present' => '1',
            'rp_show_logo'         => '1',
        ]);

        $pra = $this->storedPrefs()['pos'] ?? [];
        $this->assertTrue((bool) ($pra['show_ntn'] ?? false), 'PRA set must survive a POST that never carried it');
        $this->assertTrue((bool) ($pra['show_email'] ?? false));
        $this->assertTrue(
            (bool) DB::table('companies')->where('id', $this->companyId)->value('pos_receipt_show_tax'),
            'The PRA tax column belongs to the PRA set and must follow the same guard'
        );
    }

    /** 13. The real form saves both complaints' settings in one go: QR off + Local tax on. */
    public function test_fresh_form_persists_menu_qr_off_together_with_local_tax_on(): void
    {
        $this->actAsAdmin();
        $this->post('/pos/receipt-settings', [
            '_token'               => csrf_token(),
            'rp_pos_style_present' => '1',
            'rp_present'           => '1',
            'lp_present'           => '1',
            // rp_show_menu_qr absent = unticked
            'rp_show_tax'          => '1',
            'lp_show_tax'          => '1',
            'rp_footer_text'       => '',
            'lp_footer_text'       => '',
            'rp_style_bold'        => '1',
            'rp_logo_style'        => 'center',
        ]);

        $prefs = $this->storedPrefs();
        $this->assertFalse((bool) ($prefs['pos_style']['show_menu_qr'] ?? true),
            'Unticking Print Menu QR Code must persist');
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax'] ?? false),
            'Local tab show_tax must persist in the same save');

        $company = Company::find($this->companyId);
        $this->assertFalse($company->posReceiptStyle()['show_menu_qr']);
        $this->assertTrue($company->posReceiptPrefs('local')['show_tax']);
        $this->assertTrue($company->posReceiptPrefs('pra')['show_tax']);
    }

    /** 13b. And unticking the Local tax on a fresh form still turns it off. */
    public function test_fresh_form_can_turn_the_local_tax_off_again(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'invoice_display_prefs' => json_encode(['pos_local' => ['show_tax' => true]]),
        ]);

        $this->actAsAdmin();
        $this->post('/pos/receipt-settings', [
            '_token'               => csrf_token(),
            'rp_pos_style_present' => '1',
            'rp_present'           => '1',
            'lp_present'           => '1',
            'rp_footer_text'       => '',
            'lp_footer_text'       => '',
        ]);

        $this->assertFalse((bool) ($this->storedPrefs()['pos_local']['show_tax'] ?? true),
            'Unticking the Local tax on a freshly rendered form must store false');
    }
}
