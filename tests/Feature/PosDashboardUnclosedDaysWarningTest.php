<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

/**
 * DASHBOARD STRANDED-DAY WARNING GUARD (Task 473; feature from Task 466).
 *
 * The POS dashboard shows a compact red warning when a prior business day
 * has bills but no day-close report (shared helper unclosedPriorBusinessDays,
 * also feeding the day-close page's detailed banner from Task 455). A refactor
 * of dashboard() or the helper could silently drop it and stranded days would
 * again go unnoticed. Locked guarantees, exercised over real HTTP
 * (GET /pos/dashboard) so the production controller + Blade path is verified:
 *
 *   1. Prior-business-day bill + no PosDayCloseReport → the warning renders
 *      (pos.dash_unclosed_days_title) with the stranded date, and an admin
 *      (dayCloseAllowed=true) gets the actionable "Close now" link to the
 *      day-close page.
 *   2. A cashier without day-close rights (PosAccessService::dayCloseAllowed
 *      false — company switch pos_cashier_dayclose off/absent) gets the
 *      INFO-ONLY variant: title + info text, NO action link.
 *   3. All prior days closed → no warning (title key absent from the page).
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * PosProfitFreezeTest, which drives the same dashboard() route).
 */
class PosDashboardUnclosedDaysWarningTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday freeze: safely inside the current business day (06:00 cutoff),
        // so "yesterday" is unambiguously a PRIOR business day.
        Carbon::setTestNow(now()->setTime(12, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** Stranded prior day → warning renders with actionable link for admins. */
    public function test_warning_renders_for_admin_with_close_now_link(): void
    {
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/dashboard');
        $response->assertOk();

        // Controller passed the stranded day to the view…
        $response->assertViewHas('unclosedPriorDays', function ($days) use ($day) {
            return $days->count() === 1 && $days->first() === $day;
        });
        $response->assertViewHas('canDayClose', true);

        // …and the Blade actually rendered the warning (title key, count=1).
        $response->assertSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
        // Actionable variant: "Close now" link to the day-close page for that date.
        $response->assertSee(__('pos.dash_unclosed_days_action'));
        $response->assertSee(route('pos.day-close', ['date' => $day]), false);
        // Info-only hint must NOT show for someone who can close the day.
        $response->assertDontSee(__('pos.dash_unclosed_days_info_only'));
    }

    /** Cashier without day-close rights → info-only variant (no dead-end link). */
    public function test_cashier_without_day_close_rights_gets_info_only_variant(): void
    {
        $day = $this->strandPriorDay();
        $cashier = $this->makeUser('pos_cashier', 'cashier@test.pk');

        // Sanity: the company switch is off, so dayCloseAllowed must be false.
        $this->assertFalse(\App\Services\PosAccessService::dayCloseAllowed($cashier, $this->company->fresh()));

        $response = $this->actingAs($cashier, 'pos')->get('/pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('canDayClose', false);

        $response->assertSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
        $response->assertSee(__('pos.dash_unclosed_days_info_only'));
        // No actionable link for the cashier.
        $response->assertDontSee(__('pos.dash_unclosed_days_action'));
        $response->assertDontSee(route('pos.day-close', ['date' => $day]), false);
    }

    /** Prior day properly closed → no warning at all. */
    public function test_no_warning_when_prior_day_is_closed(): void
    {
        $day = $this->strandPriorDay();
        DB::table('pos_day_close_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => $day,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Seed one completed bill on the PRIOR business day; returns its Y-m-d. */
    private function strandPriorDay(): string
    {
        $day = now()->subDay()->toDateString();
        DB::table('pos_transactions')->insert([
            'company_id' => $this->company->id,
            'invoice_number' => 'INV-0001',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'total_amount' => 500,
            'business_date' => $day,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return $day;
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

    // ─── Schema + seed (mirrors PosProfitFreezeTest) ─────────────────────────

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'pos',
            'is_trial' => false, 'invoice_limit' => -1,
            'deals_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Stranded Day Shop', 'product_type' => 'pos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'inventory_enabled' => false,
            'pos_setup_completed' => true, // skip the first-run setup wizard redirect
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.pk',
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
            // Day-close cashier switch intentionally ABSENT in the base schema
            // (PROD-drift shape) → dayCloseAllowed falls back to FALSE for
            // cashiers, TRUE for admins/managers.
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

        // restaurant_orders required by applyReportFilters (waiter attribution query)
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        // Required by dashboard() → Notification::where(company_id)
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

        // Required by dashboard() → PosDayOpening::forDate()
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

        // Required by dashboard() → PosDayCloseReport::where(company_id, report_date)
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
    }
}
