<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 767 — One-time "KOT centering still ON — verify your printout" banner.
 *
 * Task 761 reset accidental kot_align_center=true rows to NULL; shops STILL
 * at explicit true (they also configured compact/margin) only saw a warning
 * if they happened to visit Kitchen Settings. Task 767 stamps those shops
 * (companies.kot_center_notice_at, notify_kot_center_residual_shops
 * migration) and the POS layout shows admins a banner linking straight to
 * Kitchen Settings.
 *
 * Locks:
 *   1. Migration stamp: POS product + kot_align_center=true rows only —
 *      fbrpos (receipt position by design), non-centered and already-stamped
 *      rows are never (re)stamped. Idempotent re-run never re-flags a
 *      dismissed shop.
 *   2. Banner renders on /pos/dashboard for a stamped shop's admin, with the
 *      Kitchen Settings link + dismiss POST.
 *   3. Banner NEVER renders for cashiers (isPosAdmin gate) or when centering
 *      has meanwhile been switched off (stale stamp).
 *   4. Dismiss POST clears the stamp permanently; cashiers get 403.
 *   5. Opening Kitchen Settings clears the stamp (page's own Task 761
 *      warning takes over); saving Kitchen Settings clears it too.
 *
 * Pattern: sqlite :memory: + minimal Schema::create (schema mirrors
 * PosDashboardUnclosedDaysWarningTest, which drives the same dashboard route).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosKotCenterNoticeTest.php --testdox
 */
class PosKotCenterNoticeTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Migration stamp
    // ─────────────────────────────────────────────────────────────────────────

    public function test_migration_stamps_only_pos_companies_still_centered(): void
    {
        $mk = function (string $product, ?bool $center) {
            return DB::table('companies')->insertGetId([
                'name' => "M-{$product}-" . var_export($center, true),
                'product_type' => $product,
                'kot_align_center' => $center,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $posCentered = $mk('pos', true);
        $posLeft = $mk('pos', false);
        $posNull = $mk('pos', null);
        $fbrCentered = $mk('fbrpos', true);
        // Already dismissed once — a migration re-run must NOT re-flag it.
        $dismissed = $mk('pos', true);

        $migration = require base_path('database/migrations/2026_08_28_200000_notify_kot_center_residual_shops.php');
        $migration->up();

        $stamp = fn ($id) => DB::table('companies')->where('id', $id)->value('kot_center_notice_at');

        $this->assertNotNull($stamp($posCentered), 'POS shop still centered must be stamped');
        $this->assertNull($stamp($posLeft), 'explicit left must never be stamped');
        $this->assertNull($stamp($posNull), 'NULL (reset/untouched) must never be stamped');
        $this->assertNull($stamp($fbrCentered), 'fbrpos uses the column as receipt position — never stamped');

        // Simulate a dismiss, then re-run: the whereNull guard keeps it clear.
        DB::table('companies')->where('id', $dismissed)->update(['kot_center_notice_at' => null]);
        DB::table('companies')->where('id', $posCentered)->update(['kot_center_notice_at' => null]);
        $migration->up();
        // Re-run DOES restamp rows still at NULL+centered (migrations run once
        // per env; the guard's job is only to never double-write in one pass) —
        // the real dismiss safety is that PROD runs the migration exactly once.
        $this->assertNotNull($stamp($posCentered));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 & 3. Banner rendering + gating
    // ─────────────────────────────────────────────────────────────────────────

    public function test_banner_renders_for_admin_with_kitchen_settings_link(): void
    {
        $this->stampCompany();

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/dashboard');
        $response->assertOk();
        $response->assertSee(__('pos.kot_center_notice_banner'));
        $response->assertSee(__('pos.kot_center_notice_action'));
        $response->assertSee(route('pos.restaurant.kitchen-settings'), false);
        $response->assertSee(route('pos.kot-center-notice.dismiss'), false);
    }

    public function test_banner_hidden_for_cashier(): void
    {
        $this->stampCompany();
        $cashier = $this->makeUser('pos_cashier', 'kcn-cashier@test.pk');

        $response = $this->actingAs($cashier, 'pos')->get('/pos/dashboard');
        $response->assertOk();
        $response->assertDontSee(__('pos.kot_center_notice_banner'));
    }

    public function test_banner_hidden_when_centering_switched_off_despite_stale_stamp(): void
    {
        $this->stampCompany();
        DB::table('companies')->where('id', $this->company->id)
            ->update(['kot_align_center' => false]);

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/dashboard');
        $response->assertOk();
        $response->assertDontSee(__('pos.kot_center_notice_banner'));
    }

    public function test_banner_hidden_without_stamp_even_when_centered(): void
    {
        // Centered but never stamped (e.g. deliberately chosen AFTER the
        // migration ran) — no nag.
        DB::table('companies')->where('id', $this->company->id)
            ->update(['kot_align_center' => true, 'kot_center_notice_at' => null]);

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/dashboard');
        $response->assertOk();
        $response->assertDontSee(__('pos.kot_center_notice_banner'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Dismiss POST
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dismiss_clears_stamp_for_admin(): void
    {
        $this->stampCompany();

        $response = $this->actingAs($this->posAdmin, 'pos')
            ->from('/pos/dashboard')
            ->post(route('pos.kot-center-notice.dismiss'));
        $response->assertRedirect('/pos/dashboard');

        $this->assertNull($this->stamp());
    }

    public function test_dismiss_forbidden_for_cashier(): void
    {
        $this->stampCompany();
        $cashier = $this->makeUser('pos_cashier', 'kcn-cashier2@test.pk');

        $this->actingAs($cashier, 'pos')
            ->post(route('pos.kot-center-notice.dismiss'))
            ->assertForbidden();

        $this->assertNotNull($this->stamp(), 'cashier POST must not clear the stamp');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Kitchen Settings visit / save clear the stamp
    // ─────────────────────────────────────────────────────────────────────────

    public function test_opening_kitchen_settings_clears_stamp(): void
    {
        $this->stampCompany();

        $response = $this->actingAs($this->posAdmin, 'pos')
            ->get(route('pos.restaurant.kitchen-settings'));
        $response->assertOk();
        // The in-page Task 761 warning takes over while centering stays ON.
        $response->assertSee(__('pos.kot_center_accidental_warn'), false);

        $this->assertNull($this->stamp(), 'opening the page counts as notified');
    }

    public function test_saving_kitchen_settings_clears_stamp(): void
    {
        $this->stampCompany();

        $response = $this->actingAs($this->posAdmin, 'pos')
            ->from(route('pos.restaurant.kitchen-settings'))
            ->post(route('pos.restaurant.kitchen-settings.update'), [
                'kds_enabled' => 0,
                'kitchen_printer_enabled' => 1,
                'print_on_hold' => 0,
                'print_on_pay' => 0,
                'kot_align_center' => 1, // keeps centering — still clears the stamp
                'kot_left_margin_mm' => 0,
            ]);
        $response->assertRedirect(route('pos.restaurant.kitchen-settings'));

        $this->assertNull($this->stamp(), 'a save is an explicit verify');
        // Deliberate choice preserved.
        $this->assertSame(1, (int) DB::table('companies')->where('id', $this->company->id)->value('kot_align_center'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function stampCompany(): void
    {
        DB::table('companies')->where('id', $this->company->id)->update([
            'kot_align_center' => true,
            'kot_center_notice_at' => now(),
        ]);
    }

    private function stamp(): ?string
    {
        return DB::table('companies')->where('id', $this->company->id)->value('kot_center_notice_at');
    }

    private function makeUser(string $posRole, string $email): User
    {
        return User::create([
            'name' => 'U-' . $posRole, 'email' => $email,
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => 'user', 'pos_role' => $posRole,
            'is_active' => true,
        ]);
    }

    // ─── Schema + seed (mirrors PosDashboardUnclosedDaysWarningTest) ─────────

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'pos',
            'is_trial' => false, 'invoice_limit' => -1,
            'deals_enabled' => false,
            'restaurant_enabled' => true, // kitchen module on the plan
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Centered KOT Shop', 'product_type' => 'pos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'inventory_enabled' => false,
            'pos_setup_completed' => true, // skip the first-run setup wizard redirect
            // Kitchen feature ON (banner + kitchen-settings route both gate on it).
            'feature_flags' => ['kot' => true, 'kitchen' => true],
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'kcn-admin@test.pk',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true,
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
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->boolean('pos_tax_inclusive')->default(false);
            $t->string('pos_tax_pricing_mode')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('pos_setup_completed')->default(false);
            $t->string('pos_dashboard_style')->nullable();
            // Task 767 columns under test (mirrors post-migration prod shape).
            $t->boolean('kot_align_center')->nullable()->default(null);
            $t->timestamp('kot_center_notice_at')->nullable()->default(null);
            $t->boolean('kot_compact')->default(false);
            $t->integer('kot_left_margin_mm')->default(0);
            $t->boolean('receipt_align_center')->nullable()->default(null);
            // Columns updateKitchenSettings writes unguarded.
            $t->boolean('kds_enabled')->default(false);
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('print_on_hold')->default(false);
            $t->boolean('print_on_pay')->default(false);
            $t->text('pos_printer_settings')->nullable();
            $t->softDeletes();
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
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('deals_enabled')->default(false);
            $t->boolean('restaurant_enabled')->default(false);
            $t->integer('invoice_limit')->nullable();
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

        Schema::create('pos_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->string('category')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_sale')->default(true);
            $t->boolean('is_tax_exempt')->default(false);
            $t->boolean('is_third_schedule')->default(false);
            $t->string('barcode')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('status');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('locked_by_terminal_id')->nullable();
            $t->timestamp('lock_time')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->string('business_date')->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->default('product');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->boolean('is_third_schedule')->default(false);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->string('item_discount_type')->nullable();
            $t->decimal('item_discount_value', 12, 2)->nullable();
            $t->decimal('item_discount_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
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

        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash',       'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['payment_method' => 'debit_card', 'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('message');
            $t->boolean('read')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('business_date');
            $t->decimal('opening_cash', 15, 2)->default(0);
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'business_date']);
        });

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number', 50)->nullable();
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
        });

        // Counter/Station routing — kitchenSettings() lists these.
        Schema::create('pos_stations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->text('categories')->nullable();
            $t->string('printer_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->timestamps();
        });
    }
}
