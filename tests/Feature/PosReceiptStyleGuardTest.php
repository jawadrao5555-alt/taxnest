<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 574 — Receipt style prefs (invoice_display_prefs['pos_style']) NEVER
 * silently vanish through ANY settings-save that rebuilds the JSON.
 *
 * Tasks 569/573 locked the same rebuild-trap for pos_printer_settings; this
 * test does the same for invoice_display_prefs. Four write paths touch that
 * JSON today:
 *   • PRA receipt-settings POST  (/pos/receipt-settings)   — rebuilds
 *     pos + pos_local + pos_style from the form (the form always renders
 *     every style control, so absence = deliberate change), but must
 *     preserve the FBR-owned 'fbrpos' key.
 *   • FBR receipt-settings POST  (/fbr-pos/receipt-settings) — only knows
 *     bold + logo; MUST preserve show_logo / logo_finals_only / show_menu_qr
 *     / pdf_paper and the pos / pos_local / fbrpos siblings (the "preserve
 *     keys we don't touch" comment in FbrPosController, now guarded).
 *   • PRA business-profile POST  (/pos/business-profile, with
 *     receipt_prefs_submitted) — rebuilds only 'pos'; pos_style / pos_local
 *     / fbrpos must survive untouched.
 *   • FBR business-profile POST  (/fbr-pos/business-profile) — rebuilds only
 *     'fbrpos'; pos_style / pos / pos_local must survive untouched.
 *
 * Also locked: posReceiptStyle() defaults, an explicit saved-false bold is
 * respected (not re-defaulted to true), and a blocked cashier can't write.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create
 * (same as PosPrintConfirmGuardTest / PosCounterKotGuardTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosReceiptStyleGuardTest.php --testdox
 */
class PosReceiptStyleGuardTest extends TestCase
{
    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $posAdminId;
    private int $posCashierId;
    private int $fbrAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('owner_name')->nullable();
            $t->string('product_type')->nullable();
            $t->string('status')->default('approved');
            $t->string('company_status')->default('approved');
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->string('ntn')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('website')->nullable();
            $t->string('logo_path')->nullable();
            // The JSON under test + sibling columns the write paths touch.
            $t->text('invoice_display_prefs')->nullable();
            $t->text('pos_printer_settings')->nullable();
            $t->boolean('pos_receipt_show_tax')->default(true);
            $t->string('receipt_printer_size')->nullable();
            $t->string('print_paper_size')->nullable();
            $t->string('receipt_footer_note')->nullable();
            $t->string('order_match_style')->default('off');
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
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
            $t->text('pos_custom_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        $now = now();
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Style PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Style FBR Shop', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posAdminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'rs-admin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posCashierId = DB::table('users')->insertGetId([
            'name' => 'Cashier', 'email' => 'rs-cashier@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrAdminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'rs-fbradmin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->fbrCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function company(int $id): Company
    {
        return Company::findOrFail($id);
    }

    /**
     * Seed a "real shop" invoice_display_prefs: NON-default pos_style values
     * for EVERY key (so any dropped key flips back to its default and the
     * assertion catches it), plus populated pos / pos_local / fbrpos siblings.
     */
    private function seedRichDisplayPrefs(int $companyId): array
    {
        $prefs = [
            'pos' => [
                'show_address' => true, 'show_ntn' => false, 'show_email' => true,
                'show_mobile' => false, 'show_cashier' => true, 'show_footer' => true,
                'show_business_name' => true, 'show_developed_by' => false,
                'footer_text' => 'PRA shukriya!',
            ],
            'pos_local' => [
                'show_address' => false, 'show_ntn' => false, 'show_email' => false,
                'show_mobile' => true, 'show_cashier' => false, 'show_footer' => true,
                'show_business_name' => true, 'show_developed_by' => true,
                'show_tax' => false, 'footer_text' => 'Local shukriya!',
            ],
            'fbrpos' => [
                'show_address' => true, 'show_ntn' => true, 'show_mobile' => false,
                'show_cashier' => true, 'show_footer' => false,
            ],
            // Every value here is the OPPOSITE of the posReceiptStyle() default.
            'pos_style' => [
                'bold' => false,              // default true
                'logo' => 'side',             // default center
                'pdf_paper' => 'a4',          // default thermal
                'show_logo' => false,         // default true
                'logo_finals_only' => true,   // default false
                'show_menu_qr' => false,      // default true
            ],
        ];
        Company::where('id', $companyId)->update([
            'invoice_display_prefs' => json_encode($prefs),
        ]);

        return $prefs;
    }

    private function rawPrefs(int $companyId): array
    {
        return json_decode($this->company($companyId)->getRawOriginal('invoice_display_prefs'), true);
    }

    // ── 1. Model defaults + explicit-false persistence ────────────────────

    public function test_pos_receipt_style_defaults(): void
    {
        $style = $this->company($this->posCompanyId)->posReceiptStyle();
        $this->assertTrue($style['bold'], 'bold defaults ON (owner: universal)');
        $this->assertSame('center', $style['logo']);
        $this->assertTrue($style['show_logo']);
        $this->assertFalse($style['logo_finals_only']);
        $this->assertTrue($style['show_menu_qr']);
    }

    public function test_explicit_saved_false_bold_is_respected_not_redefaulted(): void
    {
        $this->seedRichDisplayPrefs($this->posCompanyId);
        $style = $this->company($this->posCompanyId)->posReceiptStyle();
        $this->assertFalse($style['bold'], 'a saved false must NOT be re-defaulted to true');
        $this->assertSame('side', $style['logo']);
        $this->assertFalse($style['show_logo']);
        $this->assertTrue($style['logo_finals_only']);
        $this->assertFalse($style['show_menu_qr']);
    }

    // ── 2. PRA receipt-settings POST ──────────────────────────────────────

    public function test_pra_receipt_settings_post_writes_every_style_key_and_preserves_fbrpos_sibling(): void
    {
        $seeded = $this->seedRichDisplayPrefs($this->posCompanyId);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/receipt-settings', [
                'rp_pos_style_present' => '1',   // fresh-form marker required for checkbox keys
                'rp_style_bold'        => '1',
                'rp_logo_style'        => 'center',
                'rp_pdf_paper'         => 'thermal',
                'rp_show_logo'         => '1',
                'rp_logo_finals_only'  => '1',
                'rp_show_menu_qr'      => '1',
                'rp_show_tax'          => '1',
            ]);
        $resp->assertRedirect();

        $prefs = $this->rawPrefs($this->posCompanyId);
        // Full pos_style shape written — the rebuild contract of this page.
        $this->assertSame([
            'bold' => true, 'logo' => 'center', 'pdf_paper' => 'thermal',
            'show_logo' => true, 'logo_finals_only' => true, 'show_menu_qr' => true,
        ], [
            'bold' => $prefs['pos_style']['bold'],
            'logo' => $prefs['pos_style']['logo'],
            'pdf_paper' => $prefs['pos_style']['pdf_paper'],
            'show_logo' => $prefs['pos_style']['show_logo'],
            'logo_finals_only' => $prefs['pos_style']['logo_finals_only'],
            'show_menu_qr' => $prefs['pos_style']['show_menu_qr'],
        ]);
        // The FBR-owned sibling key must survive a PRA save untouched.
        $this->assertSame($seeded['fbrpos'], $prefs['fbrpos'], 'fbrpos display set must survive a PRA receipt-settings save');
    }

    public function test_pra_receipt_settings_blade_renders_every_style_control(): void
    {
        // Checkbox-based pos_style keys (show_logo, show_menu_qr, logo_finals_only,
        // pdf_paper) are only written by the controller when rp_pos_style_present is
        // in the request — meaning the form was freshly rendered, not stale/cached.
        // The form MUST always render every style control AND the hidden marker so
        // that a fresh save can always express the user's current intent.
        // Task 712: bold/logo are submitted as a named theme (rp_receipt_theme);
        // the legacy rp_style_bold/rp_logo_style fields left the blade but the
        // controller still accepts them (old cached forms). The theme input is
        // rendered by the shared cards partial included inside the form.
        $blade = file_get_contents(resource_path('views/pos/receipt-settings.blade.php'))
            . file_get_contents(resource_path('views/pos/partials/receipt-theme-cards.blade.php'));
        $this->assertStringContainsString(
            'pos.partials.receipt-theme-cards',
            $blade,
            'receipt-settings form must include the theme cards partial'
        );
        foreach (['rp_pos_style_present', 'rp_receipt_theme', 'rp_pdf_paper',
                  'rp_show_logo', 'rp_logo_finals_only', 'rp_show_menu_qr'] as $field) {
            $this->assertStringContainsString($field, $blade, "receipt-settings form must render {$field}");
        }
    }

    // ── 3. FBR receipt-settings POST — the "preserve keys we don't touch" guard ──

    public function test_fbr_receipt_settings_post_preserves_untouched_style_keys_and_all_siblings(): void
    {
        $seeded = $this->seedRichDisplayPrefs($this->fbrCompanyId);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', [
                'rp_style_bold' => '1',
                'rp_logo_style' => 'center',
                'rp_order_match' => 'off',
            ]);
        $resp->assertRedirect();

        $prefs = $this->rawPrefs($this->fbrCompanyId);
        // The two keys this page owns changed as requested…
        $this->assertTrue($prefs['pos_style']['bold']);
        $this->assertSame('center', $prefs['pos_style']['logo']);
        // …and EVERY key it does not know about survived (the rebuild trap).
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper'], 'pdf_paper must survive an FBR receipt-settings save');
        $this->assertFalse($prefs['pos_style']['show_logo'], 'show_logo must survive an FBR receipt-settings save');
        $this->assertTrue($prefs['pos_style']['logo_finals_only'], 'logo_finals_only must survive an FBR receipt-settings save');
        $this->assertFalse($prefs['pos_style']['show_menu_qr'], 'show_menu_qr must survive an FBR receipt-settings save');
        // Sibling display sets untouched.
        $this->assertSame($seeded['pos'], $prefs['pos']);
        $this->assertSame($seeded['pos_local'], $prefs['pos_local']);
        $this->assertSame($seeded['fbrpos'], $prefs['fbrpos']);
    }

    // ── 4. PRA business-profile POST rebuilds only 'pos' ──────────────────

    public function test_pra_business_profile_receipt_prefs_save_preserves_pos_style_and_siblings(): void
    {
        $seeded = $this->seedRichDisplayPrefs($this->posCompanyId);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/business-profile', [
                'name' => 'Style PRA Shop',
                'receipt_prefs_submitted' => '1',
                'rp_show_address' => '1',
                'rp_footer_text' => 'Naya footer',
            ]);
        $resp->assertRedirect();

        $prefs = $this->rawPrefs($this->posCompanyId);
        $this->assertTrue($prefs['pos']['show_address'], 'the pos set it owns was rewritten');
        $this->assertSame('Naya footer', $prefs['pos']['footer_text']);
        // Style + other siblings must survive byte-for-byte.
        $this->assertSame($seeded['pos_style'], $prefs['pos_style'], 'pos_style must survive a business-profile save');
        $this->assertSame($seeded['pos_local'], $prefs['pos_local']);
        $this->assertSame($seeded['fbrpos'], $prefs['fbrpos']);
    }

    public function test_pra_business_profile_without_receipt_prefs_flag_leaves_json_untouched(): void
    {
        $seeded = $this->seedRichDisplayPrefs($this->posCompanyId);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/business-profile', [
                'name' => 'Style PRA Shop (renamed)',
            ]);
        $resp->assertRedirect();

        $this->assertSame($seeded, $this->rawPrefs($this->posCompanyId), 'a profile-only save must not rewrite the JSON at all');
    }

    // ── 5. FBR business-profile POST rebuilds only 'fbrpos' ───────────────

    public function test_fbr_business_profile_save_preserves_pos_style_and_pra_siblings(): void
    {
        $seeded = $this->seedRichDisplayPrefs($this->fbrCompanyId);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name' => 'Style FBR Shop',
                'rd_show_address' => '1',
                'rd_show_ntn' => '1',
            ]);
        $resp->assertRedirect();

        $prefs = $this->rawPrefs($this->fbrCompanyId);
        $this->assertTrue($prefs['fbrpos']['show_address'], 'the fbrpos set it owns was rewritten');
        $this->assertSame($seeded['pos_style'], $prefs['pos_style'], 'pos_style must survive an FBR business-profile save');
        $this->assertSame($seeded['pos'], $prefs['pos']);
        $this->assertSame($seeded['pos_local'], $prefs['pos_local']);
    }

    // ── Task 662: manual order-match save locks the choice ────────────────

    public function test_manual_order_match_save_sets_locked_flag_on_both_panels(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('order_match_style_locked')->default(false);
        });

        // PRA panel manual save.
        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/receipt-settings', ['rp_order_match' => 'token'])
            ->assertRedirect();
        $posRow = DB::table('companies')->find($this->posCompanyId);
        $this->assertSame('token', $posRow->order_match_style);
        $this->assertEquals(1, $posRow->order_match_style_locked, 'PRA manual save must lock the choice');

        // FBR panel manual save (rp_logo_style is required by its validator).
        $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->post('/fbr-pos/receipt-settings', ['rp_order_match' => 'token', 'rp_logo_style' => 'center'])
            ->assertRedirect();
        $fbrRow = DB::table('companies')->find($this->fbrCompanyId);
        $this->assertSame('token', $fbrRow->order_match_style);
        $this->assertEquals(1, $fbrRow->order_match_style_locked, 'FBR manual save must lock the choice');
    }

    // ── 6. Cashier stays blocked ──────────────────────────────────────────

    public function test_cashier_receipt_settings_post_is_blocked_and_prefs_untouched(): void
    {
        $seeded = $this->seedRichDisplayPrefs($this->posCompanyId);

        // HTTP layer: middleware bounces the cashier before the controller.
        $web = $this->actingAs(User::find($this->posCashierId), 'pos')
            ->post('/pos/receipt-settings', ['rp_style_bold' => '1']);
        $web->assertRedirect(route('pos.dashboard'));

        // Controller layer: defense in depth — receiptSettings() itself must
        // 403 a posCashierBlocked user even without the middleware.
        $request = \Illuminate\Http\Request::create('/pos/receipt-settings', 'POST', [
            'rp_style_bold' => '1',
        ]);
        app()->instance('request', $request);
        auth('pos')->setUser(User::find($this->posCashierId));
        app()->instance('currentCompanyId', $this->posCompanyId);
        try {
            app(\App\Http\Controllers\PosController::class)->receiptSettings($request);
            $this->fail('cashier receiptSettings POST must abort 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            auth('pos')->forgetUser();
        }

        $this->assertSame($seeded, $this->rawPrefs($this->posCompanyId), 'blocked cashier POST must not alter stored prefs');
    }
}
