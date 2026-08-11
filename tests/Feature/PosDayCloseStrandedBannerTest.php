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
 * DAY-CLOSE PAGE DETAILED STRANDED-DAY BANNER GUARD (Task 475; feature from
 * Task 455).
 *
 * Task 473 locked the COMPACT stranded-day warning on the POS dashboard
 * (PosDashboardUnclosedDaysWarningTest). The DETAILED red banner on the
 * day-close page itself — same shared helper unclosedPriorBusinessDays, but
 * called WITH an excludeDate (the day being viewed) — had no guard: a
 * refactor of dayCloseReport() could silently drop it and stranded days
 * would only surface on the dashboard. Locked guarantees, over real HTTP
 * (GET /pos/day-close) so controller + Blade path is verified:
 *
 *   1. Prior stranded business day → the detailed banner renders
 *      (pos.dc_prior_open_title + msg) with a per-day "Close <date>" link
 *      to that day's day-close page.
 *   2. excludeDate: viewing the stranded day itself must NOT list that day
 *      in its own banner (no self-referential "close this day" nag).
 *   3. Prior day properly closed → no banner.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * PosDashboardUnclosedDaysWarningTest, which drives the same helper).
 */
class PosDayCloseStrandedBannerTest extends TestCase
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

    /** Stranded prior day → detailed banner renders with a per-day close link. */
    public function test_detailed_banner_renders_on_day_close_page(): void
    {
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/day-close');
        $response->assertOk();

        // Controller passed the stranded day to the view…
        $response->assertViewHas('unclosedPriorDays', function ($days) use ($day) {
            return $days->count() === 1 && $days->first() === $day;
        });

        // …and the Blade actually rendered the DETAILED banner.
        $response->assertSee(__('pos.dc_prior_open_title'));
        $response->assertSee(__('pos.dc_prior_open_msg'));
        // Per-day actionable link: "Close <d M Y>" pointing at that day's page.
        $response->assertSee(__('pos.dc_close_this_day', ['date' => Carbon::parse($day)->format('d M Y')]));
        $response->assertSee(route('pos.day-close', ['date' => $day]), false);
    }

    /** excludeDate: the day being VIEWED must not appear in its own banner. */
    public function test_currently_viewed_date_is_excluded_from_banner(): void
    {
        $day = $this->strandPriorDay();

        // Open the stranded day's OWN day-close page.
        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/day-close?date=' . $day);
        $response->assertOk();
        $response->assertViewHas('date', $day);

        // The viewed day is excluded → list empty → no banner at all.
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(__('pos.dc_prior_open_title'));
        $response->assertDontSee(__('pos.dc_close_this_day', ['date' => Carbon::parse($day)->format('d M Y')]));
    }

    /** Two stranded days: viewing one shows ONLY the other (exclude is per-view). */
    public function test_exclude_is_per_viewed_date_only(): void
    {
        $dayA = $this->strandPriorDay();                 // yesterday
        $dayB = $this->strandPriorDay(2, 'INV-0002');    // day before

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/day-close?date=' . $dayA);
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', function ($days) use ($dayB) {
            return $days->count() === 1 && $days->first() === $dayB;
        });
        $response->assertSee(__('pos.dc_prior_open_title'));
        $response->assertSee(route('pos.day-close', ['date' => $dayB]), false);
        // The viewed day itself must not get a self-close link in the banner.
        $response->assertDontSee(__('pos.dc_close_this_day', ['date' => Carbon::parse($dayA)->format('d M Y')]));
    }

    /** Prior day properly closed → no banner. */
    public function test_no_banner_when_prior_day_is_closed(): void
    {
        $day = $this->strandPriorDay();
        DB::table('pos_day_close_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => $day,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->posAdmin, 'pos')->get('/pos/day-close');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(__('pos.dc_prior_open_title'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Seed one completed bill on a PRIOR business day; returns its Y-m-d. */
    private function strandPriorDay(int $daysAgo = 1, string $invoice = 'INV-0001'): string
    {
        $day = now()->subDays($daysAgo)->toDateString();
        DB::table('pos_transactions')->insert([
            'company_id' => $this->company->id,
            'invoice_number' => $invoice,
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'total_amount' => 500,
            'business_date' => $day,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);

        return $day;
    }

    // ─── Schema + seed (mirrors PosDashboardUnclosedDaysWarningTest) ─────────

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'pos',
            'is_trial' => false, 'invoice_limit' => -1,
            'deals_enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Stranded Banner Shop', 'product_type' => 'pos',
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

        // restaurant_orders required by day-close open-held summary guards
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
    }
}
