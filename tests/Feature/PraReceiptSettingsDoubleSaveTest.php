<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PRA receipt-settings re-entrant save protection (Task 860).
 *
 * The FBR receipt-settings path had analogous tests added in Task 834
 * (FbrPosVerifyLinePersistenceTest, section D).  This file mirrors that
 * structure for the PRA path (PosController::receiptSettings, guard 'pos').
 *
 * Two invariants under test:
 *
 *   A. pos_style['bold'] and pos_style['logo'] use a read-modify-write via
 *      posReceiptStyle() so that a bare/stale 2nd POST (no rp_style_bold /
 *      rp_logo_style fields) does not silently reset them to defaults.
 *
 *   B. Keys in invoice_display_prefs that PRA receipt-settings does NOT own
 *      (e.g. 'fbrpos' set, arbitrary pre-seeded keys) must survive across
 *      multiple saves because the handler does a top-level read-modify-write
 *      on the prefs array and only overwrites its own top-level keys.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PraReceiptSettingsDoubleSaveTest.php --testdox
 */
class PraReceiptSettingsDoubleSaveTest extends TestCase
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

    // ── A. pos_style bold/logo survive bare 2nd POST ─────────────────────────

    /**
     * Save receipt-settings twice in a row:
     *   1st POST — rp_style_bold + rp_logo_style explicitly set
     *   2nd POST — bare/stale form (no style fields at all)
     *
     * After both saves pos_style['bold'] and pos_style['logo'] must retain
     * the values written by the 1st POST via the posReceiptStyle() read-modify-write.
     */
    public function test_double_save_bare_second_post_preserves_pos_style_bold_and_logo(): void
    {
        // 1st POST — explicit bold=true, logo=center
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_style_bold'  => '1',
                'rp_logo_style'  => 'center',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false),
            '1st save: pos_style.bold should be true');
        $this->assertSame('center', $prefs['pos_style']['logo'] ?? null,
            '1st save: pos_style.logo should be center');

        // 2nd POST — completely bare/stale form (no style fields)
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;

        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false),
            '2nd bare POST: pos_style.bold must survive unchanged');
        $this->assertSame('center', $prefs['pos_style']['logo'] ?? null,
            '2nd bare POST: pos_style.logo must survive unchanged');
    }

    /**
     * 1st POST — sets bold=false (no rp_style_bold), logo=side (explicit).
     * 2nd POST — bare.
     * 3rd POST — bare.
     *
     * After all three saves bold must still be false (not reset to the
     * posReceiptStyle() default of true) and logo must still be 'side'.
     */
    public function test_pos_style_bold_and_logo_persist_across_three_bare_saves(): void
    {
        // 1st POST — no rp_style_bold → bold=false; logo=side explicit
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_logo_style' => 'side',
                // rp_style_bold absent → bold written as false
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_style']['bold'] ?? true),
            '1st save: bold should be false (no rp_style_bold submitted)');
        $this->assertSame('side', $prefs['pos_style']['logo'] ?? null,
            '1st save: logo should be side');

        // 2nd POST — bare
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_style']['bold'] ?? true),
            '2nd bare POST: bold must still be false (not reset to default true)');
        $this->assertSame('side', $prefs['pos_style']['logo'] ?? null,
            '2nd bare POST: logo must still be side');

        // 3rd POST — bare
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_style']['bold'] ?? true),
            '3rd bare POST: bold must still be false');
        $this->assertSame('side', $prefs['pos_style']['logo'] ?? null,
            '3rd bare POST: logo must still be side');
    }

    /**
     * Explicit 2nd POST that changes bold and logo must take effect — the
     * read-modify-write must not freeze values immutably.
     */
    public function test_pos_style_bold_and_logo_do_update_when_explicitly_submitted(): void
    {
        // 1st POST — bold=false, logo=side
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_logo_style' => 'side',
            ])
            ->assertRedirect();

        // 2nd POST — bold=true, logo=center (both explicitly submitted)
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_style_bold' => '1',
                'rp_logo_style' => 'center',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos_style']['bold'] ?? false),
            '2nd explicit POST: bold should now be true');
        $this->assertSame('center', $prefs['pos_style']['logo'] ?? null,
            '2nd explicit POST: logo should now be center');
    }

    // ── B. Marker-gated pos_style keys survive bare 2nd POST ─────────────────

    /**
     * show_logo, logo_finals_only, show_menu_qr, and pdf_paper are gated by
     * the rp_pos_style_present hidden marker.  A bare/stale POST without the
     * marker must preserve whatever values were set by the most recent fresh save.
     *
     * Pre-seed all four keys to non-default values, submit a fresh 1st POST
     * (marker + checkboxes present), then submit a bare 2nd POST (no marker,
     * no style fields) and confirm all four survive unchanged.
     */
    public function test_double_save_bare_second_post_preserves_marker_gated_pos_style_keys(): void
    {
        // Pre-seed non-default values so we can tell a reset from a preserve.
        $this->company->invoice_display_prefs = [
            'pos_style' => [
                'show_logo'        => false,   // non-default (default = true)
                'logo_finals_only' => true,    // non-default (default = false)
                'show_menu_qr'     => false,   // non-default (default = true)
                'pdf_paper'        => 'a4',    // non-default (default = thermal)
                'bold'             => false,
                'logo'             => 'side',
            ],
        ];
        $this->company->save();

        // 1st POST — fresh form (marker present), keeps the same values
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_pos_style_present' => '1',
                // rp_show_logo absent  → show_logo = false (matches pre-seed)
                'rp_logo_finals_only'  => '1',   // true (matches pre-seed)
                // rp_show_menu_qr absent → show_menu_qr = false (matches pre-seed)
                'rp_pdf_paper'         => 'a4',  // a4 (matches pre-seed)
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_style']['show_logo'] ?? true),
            '1st save: show_logo should be false');
        $this->assertTrue((bool) ($prefs['pos_style']['logo_finals_only'] ?? false),
            '1st save: logo_finals_only should be true');
        $this->assertFalse((bool) ($prefs['pos_style']['show_menu_qr'] ?? true),
            '1st save: show_menu_qr should be false');
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper'] ?? null,
            '1st save: pdf_paper should be a4');

        // 2nd POST — bare/stale (no rp_pos_style_present, no style fields)
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;

        $this->assertFalse((bool) ($prefs['pos_style']['show_logo'] ?? true),
            '2nd bare POST: show_logo must survive (not reset to default true)');
        $this->assertTrue((bool) ($prefs['pos_style']['logo_finals_only'] ?? false),
            '2nd bare POST: logo_finals_only must survive (not reset to default false)');
        $this->assertFalse((bool) ($prefs['pos_style']['show_menu_qr'] ?? true),
            '2nd bare POST: show_menu_qr must survive (not reset to default true)');
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper'] ?? null,
            '2nd bare POST: pdf_paper must survive (not reset to thermal)');
    }

    /**
     * When rp_pos_style_present IS present (fresh form), the handler must write
     * new values even if they differ from what was stored — the marker is not a
     * freeze, it's a gate that enables intentional changes.
     */
    public function test_marker_present_allows_updating_show_logo_and_show_menu_qr(): void
    {
        // Pre-seed with defaults ON
        $this->company->invoice_display_prefs = [
            'pos_style' => [
                'show_logo'    => true,
                'show_menu_qr' => true,
                'pdf_paper'    => 'thermal',
            ],
        ];
        $this->company->save();

        // Fresh POST — marker present, both checkboxes absent = intentional OFF
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_pos_style_present' => '1',
                // rp_show_logo absent    → show_logo = false
                // rp_show_menu_qr absent → show_menu_qr = false
                'rp_pdf_paper' => 'a4',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_style']['show_logo'] ?? true),
            'marker present + checkbox absent = intentional false (not preserved)');
        $this->assertFalse((bool) ($prefs['pos_style']['show_menu_qr'] ?? true),
            'marker present + checkbox absent = intentional false (not preserved)');
        $this->assertSame('a4', $prefs['pos_style']['pdf_paper'] ?? null,
            'marker present: pdf_paper should update to a4');
    }

    // ── C. invoice_display_prefs keys outside pos_style survive ──────────────

    /**
     * Keys that PRA receipt-settings does not own (pre-seeded 'fbrpos' set,
     * arbitrary future sets) must survive multiple saves intact because the
     * handler does a top-level read-modify-write and only overwrites its own
     * top-level keys ('pos', 'pos_local', 'pos_style').
     *
     * This guards against a "rebuild-from-scratch" regression where the
     * handler could blank keys it never intended to touch.
     */
    public function test_double_save_preserves_unowned_invoice_display_prefs_keys(): void
    {
        // Pre-seed a 'fbrpos' set and a custom extra key — neither is written
        // by PRA receipt-settings, so both must survive every save untouched.
        $this->company->invoice_display_prefs = [
            'fbrpos' => [
                'show_verify_line' => true,
                'show_address'     => false,
            ],
            'custom_extra' => ['some_flag' => true],
        ];
        $this->company->save();

        // 1st POST — a typical full PRA receipt-settings submission
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_pos_style_present' => '1',
                'rp_show_address'      => '1',
                'rp_style_bold'        => '1',
                'rp_logo_style'        => 'center',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            '1st save: fbrpos.show_verify_line must not be touched by PRA receipt-settings');
        $this->assertFalse((bool) ($prefs['fbrpos']['show_address'] ?? true),
            '1st save: fbrpos.show_address must not be touched by PRA receipt-settings');
        $this->assertTrue((bool) ($prefs['custom_extra']['some_flag'] ?? false),
            '1st save: custom_extra set must survive');

        // 2nd POST — bare/stale
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            '2nd bare POST: fbrpos.show_verify_line must still be untouched');
        $this->assertFalse((bool) ($prefs['fbrpos']['show_address'] ?? true),
            '2nd bare POST: fbrpos.show_address must still be untouched');
        $this->assertTrue((bool) ($prefs['custom_extra']['some_flag'] ?? false),
            '2nd bare POST: custom_extra set must still survive');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $company = Company::create([
            'name'                => 'PRA Double Save Test Shop',
            'product_type'        => 'pos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
        ]);

        $user = User::create([
            'name'       => 'PRA Admin',
            'email'      => 'admin@pradoublesave.test',
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
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            // Receipt / display settings
            $t->text('invoice_display_prefs')->nullable();
            $t->text('pos_printer_settings')->nullable();
            // Always-written by receiptSettings (no hasColumn guard)
            $t->boolean('pos_receipt_show_tax')->default(true);
            $t->string('receipt_printer_size')->nullable();
            // hasColumn-guarded columns — present here so the controller can
            // write them; tests that omit related request fields leave them
            // untouched via the guard, which is fine.
            $t->boolean('kot_align_center')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
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
