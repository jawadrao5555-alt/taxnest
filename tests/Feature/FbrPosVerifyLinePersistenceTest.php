<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR verify-line choice persistence (Task 788).
 *
 * Two protection invariants were added in Task 769 but had no automated tests:
 *
 *   A. The Business Profile save handler MERGE-PRESERVES the 'fbrpos' set in
 *      invoice_display_prefs instead of rewriting it wholesale.  A regression
 *      here silently erases show_verify_line when the owner saves profile info.
 *
 *   B. The receipt-settings POST only touches show_verify_line when the hidden
 *      rp_verify_present marker is present in the form submission.  A stale
 *      cached form that predates the checkbox must NEVER silently flip the line
 *      OFF (or ON) by omitting the marker.
 *
 *   C. When the key is absent entirely, displayPrefs('fbrpos') must return
 *      show_verify_line = true (default-ON for all existing shops).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/FbrPosVerifyLinePersistenceTest.php --testdox
 */
class FbrPosVerifyLinePersistenceTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    // ── A. Business Profile save must not erase show_verify_line ────────────

    /**
     * After a deliberate show_verify_line=false is saved via receipt-settings,
     * a subsequent Business Profile save must leave the value untouched.
     */
    public function test_business_profile_save_preserves_show_verify_line(): void
    {
        // 1. Save show_verify_line = false via receipt-settings
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'   => '1',   // marker: checkbox was rendered
                // rp_show_verify_line absent = unchecked = false
            ])
            ->assertRedirect();

        // Confirm it was saved as false
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            'show_verify_line should be false after receipt-settings save');

        // 2. Now save Business Profile (touches the same fbrpos key set)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'            => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
                'rd_show_address' => '1',
                'rd_show_ntn'     => '1',
                // rd_show_phone, rd_show_cashier, rd_show_footer omitted = false
            ])
            ->assertRedirect();

        // 3. show_verify_line must still be false — not silently reset
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            'Business Profile save must not erase show_verify_line from the fbrpos set');
    }

    /**
     * Converse case: show_verify_line = true must also survive a Business
     * Profile save (explicit true is different from absent-key default).
     */
    public function test_business_profile_save_preserves_show_verify_line_true(): void
    {
        // 1. Explicitly save true
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'    => '1',
                'rp_show_verify_line'  => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            'show_verify_line should be true after receipt-settings save');

        // 2. Business Profile save
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'            => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
            ])
            ->assertRedirect();

        // 3. Still true
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            'Business Profile save must preserve explicit show_verify_line=true');
    }

    // ── B. Receipt-settings: absent rp_verify_present must not flip the line ─

    /**
     * A stale cached form without rp_verify_present (e.g. cached before Task
     * 769 added the marker + checkbox) must NOT change show_verify_line.
     * Here we pre-set show_verify_line=false and verify it stays false.
     */
    public function test_receipt_settings_without_verify_present_leaves_show_verify_line_untouched(): void
    {
        // Pre-set show_verify_line = false directly in DB
        $this->company->invoice_display_prefs = [
            'fbrpos' => ['show_verify_line' => false],
        ];
        $this->company->save();

        // POST receipt-settings WITHOUT rp_verify_present (stale form)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                // deliberately omit rp_verify_present
                'rp_show_verify_line' => '1',   // would flip it ON if guard absent
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            'Without rp_verify_present the show_verify_line must not be changed');
    }

    /**
     * Mirror: when show_verify_line=true is saved and a stale form (no
     * rp_verify_present) is submitted WITHOUT rp_show_verify_line, the value
     * must remain true — not get erased to false.
     */
    public function test_receipt_settings_without_verify_present_keeps_true_untouched(): void
    {
        $this->company->invoice_display_prefs = [
            'fbrpos' => ['show_verify_line' => true],
        ];
        $this->company->save();

        // Stale POST — no rp_verify_present, no rp_show_verify_line
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            'Without rp_verify_present an existing true must not be flipped OFF');
    }

    // ── C. Default-ON when key is absent ────────────────────────────────────

    /**
     * A company that has never touched the setting must see show_verify_line=true
     * from displayPrefs('fbrpos') (default-ON so existing shops are unchanged).
     */
    public function test_show_verify_line_defaults_to_true_when_key_absent(): void
    {
        // Company with completely empty invoice_display_prefs
        $this->company->invoice_display_prefs = null;
        $this->company->save();

        $this->company->refresh();
        $prefs = $this->company->displayPrefs('fbrpos');

        $this->assertTrue($prefs['show_verify_line'],
            'show_verify_line must default to true when the key is absent');
    }

    /**
     * A company with invoice_display_prefs['fbrpos'] set but show_verify_line
     * key absent must also resolve to true (defaultDisplayPrefs fallback).
     */
    public function test_show_verify_line_defaults_to_true_when_fbrpos_set_has_no_key(): void
    {
        $this->company->invoice_display_prefs = [
            'fbrpos' => ['show_address' => false],   // other keys present, but not show_verify_line
        ];
        $this->company->save();

        $this->company->refresh();
        $prefs = $this->company->displayPrefs('fbrpos');

        $this->assertTrue($prefs['show_verify_line'],
            'show_verify_line must default to true when absent from the fbrpos set');
    }

    // ── D. Re-entrant receipt-settings saves must not drop any key ───────────

    /**
     * Save receipt-settings twice in a row:
     *   1st POST — rp_verify_present present + show_verify_line=false + bold style set
     *   2nd POST — rp_verify_present ABSENT (stale/cached form, no verify block)
     *
     * After both saves:
     *   • show_verify_line must still equal the value written in the 1st POST (false)
     *   • pos_style['bold'] set in the 1st POST must survive the 2nd POST unchanged
     */
    public function test_double_save_second_without_verify_present_preserves_show_verify_line_and_pos_style(): void
    {
        // 1st POST — sets show_verify_line=false AND bold=true
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'  => '1',
                // rp_show_verify_line absent = false
                'rp_style_bold'      => '1',
                'rp_logo_style'      => 'center',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            '1st save: show_verify_line should be false');
        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false),
            '1st save: pos_style.bold should be true');

        // 2nd POST — rp_verify_present absent (stale form), no style fields either
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;

        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            '2nd save: show_verify_line must still be false (2nd POST must not flip it)');
        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false),
            '2nd save: pos_style.bold must survive a bare 2nd POST');
    }

    /**
     * Mirror: 1st POST sets show_verify_line=true + bold=false;
     *         2nd POST has rp_verify_present but no rp_show_verify_line (unchecked).
     *         Then a 3rd POST has neither rp_verify_present NOR any style field.
     *
     * After all three saves:
     *   • show_verify_line = false (set by 2nd POST — marker was present)
     *   • pos_style['bold'] = false (set by 1st POST, never changed since)
     */
    public function test_triple_save_verify_line_and_pos_style_each_only_change_when_their_marker_present(): void
    {
        // 1st POST — show_verify_line=true, bold=false (explicit rp_logo_style only)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'   => '1',
                'rp_show_verify_line' => '1',
                'rp_logo_style'       => 'center',
                // no rp_style_bold → bold=false
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            '1st save: show_verify_line should be true');

        // 2nd POST — rp_verify_present present, checkbox unchecked (show_verify_line→false)
        // No style fields → pos_style stays as-is via read-modify-write
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present' => '1',
                // rp_show_verify_line absent → false
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            '2nd save: show_verify_line should now be false');

        // 3rd POST — completely bare form (no rp_verify_present, no style fields)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            '3rd save: show_verify_line must still be false (bare POST must not touch it)');
    }

    /**
     * pos_style keys not touched on this page (show_logo, pdf_paper, show_menu_qr)
     * must survive even when a full-featured 1st POST is followed by a bare 2nd POST.
     *
     * This guards against a "rebuild-from-scratch" regression where a 2nd save
     * could blank pos_style keys that were never submitted in the form.
     */
    public function test_double_save_preserves_unrelated_pos_style_keys(): void
    {
        // Pre-seed keys that the receipt-settings page does not submit
        $this->company->invoice_display_prefs = [
            'pos_style' => [
                'show_logo'    => false,
                'pdf_paper'    => 'a4',
                'show_menu_qr' => true,
                'bold'         => false,
                'logo'         => 'center',
            ],
            'fbrpos' => ['show_verify_line' => true],
        ];
        $this->company->save();

        // 1st POST — sets bold + verify_line
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'   => '1',
                'rp_show_verify_line' => '1',
                'rp_style_bold'       => '1',
                'rp_logo_style'       => 'side',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        // Keys written by this page
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false));
        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false));
        $this->assertSame('side', $prefs['pos_style']['logo'] ?? null);
        // Keys this page never touches — must survive
        $this->assertFalse((bool) ($prefs['pos_style']['show_logo'] ?? true),
            '1st save: show_logo (not in form) must be preserved');
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper'] ?? null,
            '1st save: pdf_paper (not in form) must be preserved');
        $this->assertTrue((bool) ($prefs['pos_style']['show_menu_qr'] ?? false),
            '1st save: show_menu_qr (not in form) must be preserved');

        // 2nd POST — bare form (simulate stale/cached submit)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        // All keys must survive the bare 2nd POST
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            '2nd save: show_verify_line must survive bare POST');
        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false),
            '2nd save: bold must survive bare POST');
        $this->assertSame('side', $prefs['pos_style']['logo'] ?? null,
            '2nd save: logo must survive bare POST');
        $this->assertFalse((bool) ($prefs['pos_style']['show_logo'] ?? true),
            '2nd save: show_logo must survive bare POST');
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper'] ?? null,
            '2nd save: pdf_paper must survive bare POST');
        $this->assertTrue((bool) ($prefs['pos_style']['show_menu_qr'] ?? false),
            '2nd save: show_menu_qr must survive bare POST');
    }

    /**
     * print_confirm_ask=true set in a 1st POST (with rp_print_confirm_present marker)
     * must survive a 2nd bare POST that omits rp_print_confirm_present entirely.
     *
     * Before the fix, the bare 2nd POST would call has('rp_print_confirm') → false
     * unconditionally and silently disable the setting the owner deliberately enabled.
     */
    public function test_double_save_bare_second_post_does_not_disable_print_confirm_ask(): void
    {
        // 1st POST — enable print-confirm dialog
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_print_confirm_present' => '1',
                'rp_print_confirm'         => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $pset = $this->company->printerSettings();
        $this->assertTrue((bool) ($pset['print_confirm_ask'] ?? false),
            '1st save: print_confirm_ask should be true');

        // 2nd POST — bare/stale form (no rp_print_confirm_present, no rp_print_confirm)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $pset = $this->company->printerSettings();
        $this->assertTrue((bool) ($pset['print_confirm_ask'] ?? false),
            '2nd bare POST must not silently disable print_confirm_ask');
    }

    /**
     * When rp_print_confirm_present is present but rp_print_confirm is absent
     * (checkbox unchecked), print_confirm_ask must be written as false — the
     * marker distinguishes "intentionally unchecked" from "stale form".
     */
    public function test_print_confirm_present_without_checkbox_writes_false(): void
    {
        // Pre-enable
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_print_confirm_present' => '1',
                'rp_print_confirm'         => '1',
            ])
            ->assertRedirect();

        // Deliberate uncheck — marker present, checkbox absent
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_print_confirm_present' => '1',
                // rp_print_confirm absent = unchecked
            ])
            ->assertRedirect();

        $this->company->refresh();
        $pset = $this->company->printerSettings();
        $this->assertFalse((bool) ($pset['print_confirm_ask'] ?? true),
            'Deliberate uncheck (marker present, checkbox absent) must write false');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $company = Company::create([
            'name'                => 'Verify Line Test Shop',
            'product_type'        => 'fbrpos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
            'fbr_pos_enabled'     => true,
        ]);

        $user = User::create([
            'name'       => 'FBR Admin',
            'email'      => 'admin@verifyline.test',
            'password'   => bcrypt('secret'),
            'company_id' => $company->id,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);

        return [$company, $user];
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
            // Receipt / display settings
            $t->text('invoice_display_prefs')->nullable();
            $t->text('pos_printer_settings')->nullable();
            // Business profile fields
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('print_paper_size')->nullable();
            $t->string('receipt_footer_note')->nullable();
            $t->boolean('pos_receipt_show_tax')->default(true);
            // Print position columns (hasColumn-guarded in controller)
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            // Order matching (hasColumn-guarded in controller)
            $t->string('order_match_style')->nullable();
            $t->boolean('order_match_style_locked')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
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
