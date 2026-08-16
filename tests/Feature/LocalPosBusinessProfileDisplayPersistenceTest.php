<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Local-receipt display choices must survive a Business Profile save (Task 812).
 *
 * Task 800 proved the PRA (pos) receipt set is protected by the array_merge
 * guard in PosController::businessProfile.  The Local (pos_local) receipt set
 * — written by receiptSettings with its own lp_* fields — has no equivalent
 * lock: a future change that adds pos_local handling to businessProfile
 * WITHOUT the guard would silently erase Local prefs with no test failure.
 *
 * These tests mirror PraPosBusinessProfileDisplayPersistenceTest exactly,
 * but exercise the pos_local sub-array.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/LocalPosBusinessProfileDisplayPersistenceTest.php --testdox
 */
class LocalPosBusinessProfileDisplayPersistenceTest extends TestCase
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

    // ── A. Business Profile save must not erase pos_local keys ───────────────

    /**
     * After lp_show_tax=false is saved via receipt-settings, a subsequent
     * Business Profile save (with receipt_prefs_submitted present) must leave
     * pos_local['show_tax'] untouched.
     */
    public function test_business_profile_save_preserves_local_show_tax_false(): void
    {
        // 1. Set show_tax = false for Local receipts via receipt-settings
        //    (lp_show_tax absent = unchecked = false)
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'lp_show_address' => '1',
                'lp_show_ntn'     => '1',
                // lp_show_tax deliberately absent
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_local']['show_tax'] ?? true),
            'pos_local show_tax should be false after receipt-settings save');

        // 2. Save Business Profile — with receipt_prefs_submitted so the prefs
        //    block is triggered (regression path: a future wholesale rewrite
        //    that touches pos_local without the merge guard would erase keys).
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test Local Shop',
                'receipt_prefs_submitted' => '1',
                'rp_show_address'         => '1',
                'rp_show_ntn'             => '1',
                // no lp_* keys — business-profile form does not own the Local set
            ])
            ->assertRedirect();

        // 3. pos_local show_tax must still be false
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_local']['show_tax'] ?? true),
            'Business Profile save must not erase pos_local show_tax=false');
    }

    /**
     * Converse: lp_show_tax=true must also survive a Business Profile save.
     */
    public function test_business_profile_save_preserves_local_show_tax_true(): void
    {
        // 1. Set show_tax = true for Local receipts
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'lp_show_tax' => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax'] ?? false),
            'pos_local show_tax should be true after receipt-settings save');

        // 2. Business Profile save with receipt_prefs_submitted
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test Local Shop',
                'receipt_prefs_submitted' => '1',
                // no lp_* keys
            ])
            ->assertRedirect();

        // 3. Still true
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax'] ?? false),
            'Business Profile save must preserve pos_local show_tax=true');
    }

    /**
     * All keys owned exclusively by Local receipt-settings (show_cashier,
     * show_business_name, show_developed_by, show_tax) must survive a
     * business-profile POST that only touches the PRA (pos) subset.
     */
    public function test_business_profile_save_preserves_all_local_receipt_settings_keys(): void
    {
        // 1. Save a full Local receipt-settings payload with all Local-only keys ON
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'lp_show_address'       => '1',
                'lp_show_ntn'           => '1',
                'lp_show_cashier'       => '1',
                'lp_show_business_name' => '1',
                'lp_show_developed_by'  => '1',
                'lp_show_tax'           => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos_local']['show_address']       ?? false), 'lp show_address saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_ntn']           ?? false), 'lp show_ntn saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_cashier']       ?? false), 'lp show_cashier saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_business_name'] ?? false), 'lp show_business_name saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_developed_by']  ?? false), 'lp show_developed_by saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax']           ?? false), 'lp show_tax saved');

        // 2. Business Profile save — only touches PRA (pos) subset
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test Local Shop',
                'receipt_prefs_submitted' => '1',
                'rp_show_address'         => '1',
                // Local keys are NOT present in the business-profile form
            ])
            ->assertRedirect();

        // 3. All Local keys must survive untouched
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos_local']['show_address']       ?? false),
            'pos_local show_address must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos_local']['show_ntn']           ?? false),
            'pos_local show_ntn must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos_local']['show_cashier']       ?? false),
            'pos_local show_cashier must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos_local']['show_business_name'] ?? false),
            'pos_local show_business_name must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos_local']['show_developed_by']  ?? false),
            'pos_local show_developed_by must survive business-profile save');
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax']           ?? false),
            'pos_local show_tax must survive business-profile save');
    }

    /**
     * Without receipt_prefs_submitted the business-profile save must not touch
     * invoice_display_prefs at all — the existing pos_local set is fully preserved.
     */
    public function test_business_profile_without_receipt_prefs_submitted_leaves_local_prefs_untouched(): void
    {
        // Seed pos_local directly
        $this->company->invoice_display_prefs = [
            'pos_local' => ['show_tax' => false, 'show_cashier' => true],
        ];
        $this->company->save();

        // POST business profile WITHOUT receipt_prefs_submitted
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name' => 'Test Local Shop',
                // receipt_prefs_submitted absent — normal blade submit path
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos_local']['show_tax'] ?? true),
            'pos_local show_tax must be untouched when receipt_prefs_submitted is absent');
        $this->assertTrue((bool) ($prefs['pos_local']['show_cashier'] ?? false),
            'pos_local show_cashier must be untouched when receipt_prefs_submitted is absent');
    }

    /**
     * A business-profile save must not cross-contaminate: PRA prefs are
     * updated by receipt_prefs_submitted but pos_local remains exactly as set.
     */
    public function test_business_profile_updates_pra_set_without_touching_local_set(): void
    {
        // 1. Set both sets via receipt-settings
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/receipt-settings', [
                'rp_show_address' => '1',
                'rp_show_ntn'     => '1',
                'lp_show_tax'     => '1',
                'lp_show_cashier' => '1',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertTrue((bool) ($prefs['pos']['show_address']     ?? false), 'PRA show_address saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax']   ?? false), 'Local show_tax saved');
        $this->assertTrue((bool) ($prefs['pos_local']['show_cashier'] ?? false), 'Local show_cashier saved');

        // 2. Business Profile flips PRA show_address OFF (absent = unchecked)
        $this->actingAs($this->posAdmin, 'pos')
            ->post('/pos/business-profile', [
                'name'                    => 'Test Local Shop',
                'receipt_prefs_submitted' => '1',
                // rp_show_address absent → writes false into pos['show_address']
                // no lp_* keys at all
            ])
            ->assertRedirect();

        // 3. PRA show_address changed, but Local set is completely untouched
        $this->company->refresh();
        $prefs = $this->company->invoice_display_prefs;
        $this->assertFalse((bool) ($prefs['pos']['show_address']      ?? true),
            'PRA show_address should now be false (businessProfile owns it)');
        $this->assertTrue((bool) ($prefs['pos_local']['show_tax']     ?? false),
            'pos_local show_tax must be unaffected by PRA set change');
        $this->assertTrue((bool) ($prefs['pos_local']['show_cashier'] ?? false),
            'pos_local show_cashier must be unaffected by PRA set change');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $company = Company::create([
            'name'                => 'Local Display Persistence Test Shop',
            'product_type'        => 'pos',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => false,
        ]);

        $user = User::create([
            'name'       => 'Local POS Admin',
            'email'      => 'admin@localdisplay.test',
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
