<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PRA business-profile save must not erase receipt display choices (Task 800).
 *
 * Task 769 added the merge-preserve pattern to BOTH the FBR and PRA business-
 * profile save handlers so that keys owned by receipt-settings (show_verify_line,
 * show_cashier, show_business_name, show_developed_by …) survive when the owner
 * later edits and saves the Business Profile page.
 *
 * The FBR side is locked by FbrPosVerifyLinePersistenceTest.  This file is the
 * PRA-side equivalent: it tests PosController::businessProfile against the same
 * invariants so any future regression is caught immediately.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PraPosBusinessProfileDisplayPersistenceTest.php --testdox
 */
class PraPosBusinessProfileDisplayPersistenceTest extends TestCase
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

    // ── A. Business Profile save must not erase receipt-settings-owned keys ──

    /**
     * After show_verify_line=false is saved via receipt-settings, a subsequent
     * Business Profile save (with receipt_prefs_submitted present) must leave
     * show_verify_line untouched.
     */
    public function test_business_profile_save_preserves_show_verify_line_false(): void
    {
        // 1. Set show_verify_line = false via receipt-settings
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_show_address' => '1',
                'rp_show_ntn'     => '1',
                // rp_show_verify_line absent = unchecked = false
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos']['show_verify_line'] ?? true),
            'show_verify_line should be false after receipt-settings save');

        // 2. Save Business Profile — with receipt_prefs_submitted so the prefs
        //    block is triggered (regression path: wholesale rewrite erased keys).
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test PRA Shop',
                'receipt_prefs_submitted' => '1',
                'rp_show_address'         => '1',
                'rp_show_ntn'             => '1',
                // rp_show_verify_line deliberately absent (not a field on the
                // business-profile form — business profile must not own it)
            ])
            ->assertRedirect();

        // 3. show_verify_line must still be false
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos']['show_verify_line'] ?? true),
            'Business Profile save must not erase show_verify_line from the pos set');
    }

    /**
     * Converse: show_verify_line = true must also survive a Business Profile save.
     */
    public function test_business_profile_save_preserves_show_verify_line_true(): void
    {
        // 1. Set show_verify_line = true via receipt-settings
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_show_verify_line' => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos']['show_verify_line'] ?? false),
            'show_verify_line should be true after receipt-settings save');

        // 2. Business Profile save with receipt_prefs_submitted
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test PRA Shop',
                'receipt_prefs_submitted' => '1',
                // rp_show_verify_line absent — business profile does not own this key
            ])
            ->assertRedirect();

        // 3. Still true
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos']['show_verify_line'] ?? false),
            'Business Profile save must preserve explicit show_verify_line=true');
    }

    /**
     * Keys owned exclusively by receipt-settings (show_cashier, show_business_name,
     * show_developed_by) must all survive a business-profile POST that only touches
     * its own subset (show_address, show_ntn, show_email, show_mobile, show_footer).
     */
    public function test_business_profile_save_preserves_receipt_settings_only_keys(): void
    {
        // 1. Save a full receipt-settings payload with all receipt-only keys ON
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_show_cashier'       => '1',
                'rp_show_business_name' => '1',
                'rp_show_developed_by'  => '1',
                'rp_show_verify_line'   => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos']['show_cashier']       ?? false), 'show_cashier saved');
        $this->assertTrue((bool) ($prefs['pos']['show_business_name'] ?? false), 'show_business_name saved');
        $this->assertTrue((bool) ($prefs['pos']['show_developed_by']  ?? false), 'show_developed_by saved');
        $this->assertTrue((bool) ($prefs['pos']['show_verify_line']   ?? false), 'show_verify_line saved');

        // 2. Business Profile save — only touches its own subset of keys
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test PRA Shop',
                'receipt_prefs_submitted' => '1',
                'rp_show_address'         => '1',
                // receipt-settings-only keys are NOT in the business-profile form
            ])
            ->assertRedirect();

        // 3. All receipt-settings-owned keys must survive untouched
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos']['show_cashier']       ?? false),
            'show_cashier must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos']['show_business_name'] ?? false),
            'show_business_name must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos']['show_developed_by']  ?? false),
            'show_developed_by must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos']['show_verify_line']   ?? false),
            'show_verify_line must survive business-profile save');
    }

    /**
     * Without receipt_prefs_submitted the business-profile save must not touch
     * invoice_display_prefs at all — the existing pos set is fully preserved.
     */
    public function test_business_profile_without_receipt_prefs_submitted_leaves_prefs_untouched(): void
    {
        // Seed show_verify_line = false directly
        $this->company->invoice_display_prefs = [
            'pos' => ['show_verify_line' => false, 'show_cashier' => true],
        ];
        $this->company->save();

        // POST business profile WITHOUT receipt_prefs_submitted
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name' => 'Test PRA Shop',
                // receipt_prefs_submitted absent — normal blade submit path
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos']['show_verify_line'] ?? true),
            'show_verify_line must be untouched when receipt_prefs_submitted is absent');
        $this->assertTrue((bool) ($prefs['pos']['show_cashier'] ?? false),
            'show_cashier must be untouched when receipt_prefs_submitted is absent');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $company = Company::create([
            'name'                => 'PRA Display Persistence Test Shop',
            'product_type'        => 'pos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
        ]);

        $user = User::create([
            'name'       => 'PRA Admin',
            'email'      => 'admin@pradisplay.test',
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
            // Business profile fields
            $t->string('owner_name')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('email')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('city')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('website')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('print_paper_size')->nullable();
            $t->string('receipt_printer_size')->nullable();
            $t->string('receipt_footer_note')->nullable();
            $t->boolean('pos_receipt_show_tax')->default(true);
            // Print position columns (hasColumn-guarded in controller)
            $t->boolean('kot_align_center')->nullable();
            $t->integer('kot_left_margin_mm')->default(0);
            // Receipt-specific position (hasColumn-guarded)
            $t->boolean('receipt_align_center')->nullable();
            $t->integer('receipt_left_margin_mm')->default(0);
            // PRA / reporting columns (needed by praReportingActive())
            $t->boolean('pra_reporting_active')->default(false)->nullable();
            $t->string('pra_pos_id')->nullable();
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
