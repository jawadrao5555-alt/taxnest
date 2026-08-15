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
