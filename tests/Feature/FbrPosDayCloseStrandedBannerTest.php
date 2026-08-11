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
 * FBR DAY-CLOSE PAGE DETAILED STRANDED-DAY BANNER GUARD (Task 479 — FBR mirror
 * of the PRA guard PosDayCloseStrandedBannerTest / Task 475, feature Task 455).
 *
 * FbrPosController::dayCloseReport calls the shared FBR helper
 * unclosedPriorBusinessDays WITH an excludeDate (the day being viewed) and
 * the Blade renders the detailed red banner. Locked guarantees, over real
 * HTTP (GET /fbr-pos/day-close):
 *
 *   1. Prior stranded day → the detailed banner renders (pos.dc_prior_open_title
 *      + msg) with a per-day "Close <date>" link to that day's page.
 *   2. excludeDate: viewing the stranded day itself must NOT list that day
 *      in its own banner (no self-referential "close this day" nag).
 *   3. Prior day properly closed → no banner.
 *   4. AUTHORIZATION (owner rule 5 Aug 2026, mirrored from PRA): a cashier
 *      without day-close rights can neither GET the day-close page (redirect
 *      to dashboard) nor POST a closure; an admin CAN close the warned date.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * FbrPosDashboardUnclosedDaysWarningTest, which drives the same helper).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDayCloseStrandedBannerTest.php
 */
class FbrPosDayCloseStrandedBannerTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now()->setTime(12, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** Stranded prior day → detailed banner renders with a per-day close link. */
    public function test_detailed_banner_renders_on_day_close_page(): void
    {
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/day-close');
        $response->assertOk();

        // Controller passed the stranded day to the view…
        $response->assertViewHas('unclosedPriorDays', function ($days) use ($day) {
            return $days->count() === 1 && $days->first() === $day;
        });

        // …and the Blade actually rendered the DETAILED banner.
        $response->assertSee(__('pos.dc_prior_open_title'));
        $response->assertSee(__('pos.dc_prior_open_msg'));
        $response->assertSee(__('pos.dc_close_this_day', ['date' => Carbon::parse($day)->format('d M Y')]));
        $response->assertSee(route('fbrpos.day-close', ['date' => $day]), false);
    }

    /** excludeDate: the day being VIEWED must not appear in its own banner. */
    public function test_currently_viewed_date_is_excluded_from_banner(): void
    {
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/day-close?date=' . $day);
        $response->assertOk();
        $response->assertViewHas('date', $day);

        // The viewed day is excluded → list empty → no banner at all.
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(__('pos.dc_prior_open_title'));
    }

    /** Prior day properly closed → no banner. */
    public function test_no_banner_when_prior_day_is_closed(): void
    {
        $day = $this->strandPriorDay();
        DB::table('fbr_day_close_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => $day,
            'report_number' => 'FZ-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/day-close');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(__('pos.dc_prior_open_title'));
    }

    /** Cashier without day-close rights → GET redirected to dashboard. */
    public function test_cashier_without_rights_cannot_view_day_close_page(): void
    {
        $this->strandPriorDay();
        $cashier = $this->makeCashier();

        $this->assertFalse(\App\Services\PosAccessService::dayCloseAllowed($cashier, $this->company->fresh()));

        $response = $this->actingAs($cashier, 'fbrpos')->get('/fbr-pos/day-close');
        $response->assertRedirect(route('fbrpos.dashboard'));
        $response->assertSessionHas('error', __('pos.custom_access_denied'));
    }

    /** Cashier without day-close rights → POST blocked, no report row created. */
    public function test_cashier_without_rights_cannot_post_day_close(): void
    {
        $day = $this->strandPriorDay();
        $cashier = $this->makeCashier();

        $response = $this->actingAs($cashier, 'fbrpos')
            ->post('/fbr-pos/day-close', ['date' => $day]);
        $response->assertRedirect(route('fbrpos.dashboard'));
        $response->assertSessionHas('error', __('pos.custom_access_denied'));

        $this->assertSame(0, DB::table('fbr_day_close_reports')->count());
    }

    /** Admin CAN close the warned (stranded) date via POST. */
    public function test_admin_can_close_the_warned_day(): void
    {
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/day-close', ['date' => $day]);
        $response->assertSessionHas('success');

        $this->assertSame(1, DB::table('fbr_day_close_reports')
            ->where('company_id', $this->company->id)
            ->whereDate('report_date', $day)
            ->count());

        // Closed → the banner no longer flags that day.
        $page = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/day-close');
        $page->assertOk();
        $page->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures (mirror FbrPosDashboardUnclosedDaysWarningTest)
    // ─────────────────────────────────────────────────────────────────────────

    /** Insert a bill on YESTERDAY with no day-close report; returns Y-m-d. */
    private function strandPriorDay(): string
    {
        $at = now()->subDay()->setTime(14, 0);
        DB::table('fbr_pos_transactions')->insert([
            'company_id' => $this->company->id,
            'invoice_number' => 'FPOS-STR-1',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $at->toDateString();
    }

    private function makeCashier(): User
    {
        return User::create([
            'name' => 'Cashier', 'email' => 'cashier@fbrbanner.pk',
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);
    }

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Stranded FBR Banner Shop', 'product_type' => 'fbrpos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@fbrbanner.pk',
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
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('fbr_pos_enabled')->default(true);
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->string('pos_dayclose_provisional_action')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->rememberToken();
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

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('transaction_type')->default('sale');
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->decimal('item_discount', 12, 2)->nullable();
            $t->decimal('promotion_discount', 12, 2)->nullable();
            $t->timestamps();
        });

        // Full snapshot shape — the admin-close test drives performDayClose,
        // which writes every column (mirrors FbrPosUdhaarSeparationTest).
        Schema::create('fbr_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->integer('fbr_invoices')->default(0);
            $t->integer('local_invoices')->default(0);
            $t->integer('failed_invoices')->default(0);
            $t->decimal('gross_sales', 14, 2)->default(0);
            $t->decimal('total_discount', 14, 2)->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);
            $t->decimal('total_tax', 14, 2)->default(0);
            $t->decimal('total_fbr_fee', 14, 2)->nullable();
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->decimal('cash_amount', 14, 2)->default(0);
            $t->decimal('card_amount', 14, 2)->default(0);
            $t->decimal('udhaar_amount', 14, 2)->default(0);
            $t->decimal('other_amount', 14, 2)->default(0);
            $t->string('first_invoice_number')->nullable();
            $t->string('last_invoice_number')->nullable();
            $t->timestamp('first_invoice_time')->nullable();
            $t->timestamp('last_invoice_time')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('hash')->nullable();
            $t->decimal('opening_float', 14, 2)->nullable();
            $t->decimal('counted_cash', 14, 2)->nullable();
            $t->decimal('expected_cash', 14, 2)->nullable();
            $t->decimal('cash_variance', 14, 2)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
        });

        Schema::create('fbr_pos_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->text('request_payload')->nullable();
            $t->text('response_payload')->nullable();
            $t->string('response_code')->nullable();
            $t->string('status')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
        });

        // MySQL SUBSTRING_INDEX polyfill (atomic report_number counter).
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            $parts = explode((string) $delim, (string) $str);
            return $count < 0
                ? implode($delim, array_slice($parts, (int) $count))
                : implode($delim, array_slice($parts, 0, (int) $count));
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->nullable();
            $t->boolean('is_trial')->default(false);
            $t->decimal('price', 12, 2)->default(0);
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
    }
}
