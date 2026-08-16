<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS business-profile save must not erase receipt-settings choices (Task 827).
 *
 * FbrPosVerifyLinePersistenceTest (Task 788) already locks show_verify_line
 * against accidental erasure.  This file is the broader "all receipt-settings-
 * owned keys" companion, mirroring PraPosBusinessProfileDisplayPersistenceTest
 * for the FBR product.
 *
 * FbrPosController::businessProfile writes five keys into the 'fbrpos' set via
 * array_merge($fbrExisting, [...]):
 *   show_address, show_ntn, show_mobile, show_cashier, show_footer
 *
 * FbrPosController::fbrReceiptSettings owns:
 *   show_verify_line  (gated on rp_verify_present)
 *   [show_business_name, show_developed_by — potential future expansion]
 *
 * The array_merge guard in businessProfile is the protection.  These tests
 * document that invariant so any future expansion to the write block that
 * removes the merge guard fails immediately.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/FbrPosBusinessProfileDisplayPersistenceTest.php --testdox
 */
class FbrPosBusinessProfileDisplayPersistenceTest extends TestCase
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
     * After show_verify_line=false is saved via receipt-settings, a subsequent
     * Business Profile POST must leave show_verify_line untouched.
     */
    public function test_business_profile_save_preserves_show_verify_line_false(): void
    {
        // 1. Set show_verify_line = false via receipt-settings
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'   => '1',
                // rp_show_verify_line absent = unchecked = false
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            'show_verify_line should be false after receipt-settings save');

        // 2. Save Business Profile — businessProfile always processes prefs,
        //    no receipt_prefs_submitted gate (unlike PRA). The array_merge guard
        //    is the only protection.
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
                'rd_show_address'  => '1',
                'rd_show_ntn'      => '1',
                // rd_show_cashier, rd_show_phone, rd_show_footer absent = false
            ])
            ->assertRedirect();

        // 3. show_verify_line must still be false
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            'Business Profile save must not erase show_verify_line=false from the fbrpos set');
    }

    /**
     * Converse: show_verify_line=true must also survive a Business Profile save.
     */
    public function test_business_profile_save_preserves_show_verify_line_true(): void
    {
        // 1. Set show_verify_line = true via receipt-settings
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/receipt-settings', [
                'rp_verify_present'   => '1',
                'rp_show_verify_line' => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            'show_verify_line should be true after receipt-settings save');

        // 2. Business Profile save (only touches its own five keys)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
            ])
            ->assertRedirect();

        // 3. Still true
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line'] ?? false),
            'Business Profile save must preserve show_verify_line=true in the fbrpos set');
    }

    // ── B. Future receipt-settings keys survive without needing a code change ─

    /**
     * Keys that are NOT part of businessProfile's own write set
     * (show_business_name, show_developed_by — potential future receipt-
     * settings expansion) must survive a businessProfile POST because the
     * array_merge preserves every pre-existing key in $fbrExisting.
     *
     * This test pre-seeds those keys directly and verifies they are not
     * dropped — proving the guard holds for keys businessProfile does not
     * explicitly write.
     */
    public function test_business_profile_save_preserves_keys_not_in_its_own_write_set(): void
    {
        // 1. Pre-seed receipt-settings-only keys directly into invoice_display_prefs
        $this->company->invoice_display_prefs = [
            'fbrpos' => [
                'show_verify_line'   => true,
                'show_business_name' => true,
                'show_developed_by'  => true,
            ],
        ];
        $this->company->save();

        // 2. Business Profile save — writes its own subset
        //    (show_address, show_ntn, show_mobile, show_cashier, show_footer)
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
                'rd_show_address'  => '1',
                'rd_show_cashier'  => '1',
                // show_business_name and show_developed_by are NOT in this form
            ])
            ->assertRedirect();

        // 3. All pre-seeded keys must survive untouched
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['fbrpos']['show_verify_line']   ?? false),
            'show_verify_line must survive business-profile save');
        $this->assertTrue((bool) ($prefs['fbrpos']['show_business_name'] ?? false),
            'show_business_name must survive business-profile save');
        $this->assertTrue((bool) ($prefs['fbrpos']['show_developed_by']  ?? false),
            'show_developed_by must survive business-profile save');

        // 4. businessProfile's own keys ARE written (array_merge + explicit values)
        $this->assertTrue((bool) ($prefs['fbrpos']['show_address'] ?? false),
            'show_address should be written true (rd_show_address present)');
        $this->assertTrue((bool) ($prefs['fbrpos']['show_cashier'] ?? false),
            'show_cashier should be written true (rd_show_cashier present)');
    }

    /**
     * When show_verify_line is pre-seeded as false and the form omits it,
     * businessProfile must write its own keys AND leave show_verify_line=false.
     * Verifies the merge does not silently reset a false to its absent-key default.
     */
    public function test_business_profile_writes_own_keys_and_leaves_verify_line_false(): void
    {
        // 1. Pre-seed show_verify_line = false
        $this->company->invoice_display_prefs = [
            'fbrpos' => ['show_verify_line' => false],
        ];
        $this->company->save();

        // 2. Business Profile save
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/business-profile', [
                'name'             => 'Test FBR Shop',
                'print_paper_size' => 'thermal',
                'rd_show_ntn'      => '1',
                // rd_show_cashier, rd_show_address, etc. absent = false
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;

        // show_verify_line stays false (not silently reset to absent-key default true)
        $this->assertFalse((bool) ($prefs['fbrpos']['show_verify_line'] ?? true),
            'show_verify_line=false must not be silently reset by business-profile save');

        // businessProfile's own keys are written correctly
        $this->assertTrue((bool) ($prefs['fbrpos']['show_ntn']     ?? false),
            'show_ntn should be written true (rd_show_ntn present)');
        $this->assertFalse((bool) ($prefs['fbrpos']['show_address'] ?? true),
            'show_address should be written false (rd_show_address absent)');
        $this->assertFalse((bool) ($prefs['fbrpos']['show_cashier'] ?? true),
            'show_cashier should be written false (rd_show_cashier absent)');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $company = Company::create([
            'name'                => 'FBR Display Persistence Test Shop',
            'product_type'        => 'fbrpos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
            'fbr_pos_enabled'     => true,
        ]);

        $user = User::create([
            'name'       => 'FBR Admin',
            'email'      => 'admin@fbrdisplay.test',
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
            $t->string('logo_path')->nullable();
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
