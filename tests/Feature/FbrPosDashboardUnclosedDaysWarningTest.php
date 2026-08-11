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
 * FBR DASHBOARD STRANDED-DAY WARNING GUARD (Task 479 — FBR mirror of the PRA
 * guard PosDashboardUnclosedDaysWarningTest / Task 473, feature from Task 466).
 *
 * The FBR POS dashboard shows a compact red warning when a prior day has
 * bills but no FbrDayCloseReport (FbrPosController::unclosedPriorBusinessDays,
 * also feeding the day-close page's detailed banner). Locked guarantees,
 * exercised over real HTTP (GET /fbr-pos/dashboard):
 *
 *   1. Prior-day bill + no FbrDayCloseReport → the warning renders
 *      (pos.dash_unclosed_days_title) with the stranded date, and an admin
 *      (dayCloseAllowed=true) gets the actionable "Close now" link.
 *   2. A cashier without day-close rights gets the INFO-ONLY variant:
 *      title + info text, NO action link.
 *   3. All prior days closed → no warning at all.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (FBR schema
 * mirrors FbrPosUdhaarSeparationTest; scenario mirrors the PRA guard).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDashboardUnclosedDaysWarningTest.php
 */
class FbrPosDashboardUnclosedDaysWarningTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday freeze: safely inside the current day, so "yesterday" is
        // unambiguously a PRIOR day (created_at < startOfToday).
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

    /** Stranded prior day → warning renders with actionable link for admins. */
    public function test_warning_renders_for_admin_with_close_now_link(): void
    {
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();

        $response->assertViewHas('unclosedPriorDays', function ($days) use ($day) {
            return $days->count() === 1 && $days->first() === $day;
        });
        $response->assertViewHas('canDayClose', true);

        $response->assertSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
        $response->assertSee(__('pos.dash_unclosed_days_action'));
        $response->assertSee(route('fbrpos.day-close', ['date' => $day]), false);
        $response->assertDontSee(__('pos.dash_unclosed_days_info_only'));
    }

    /** Cashier without day-close rights → info-only variant (no dead-end link). */
    public function test_cashier_without_day_close_rights_gets_info_only_variant(): void
    {
        $day = $this->strandPriorDay();
        $cashier = $this->makeUser('pos_cashier', 'cashier@fbrtest.pk');

        $this->assertFalse(\App\Services\PosAccessService::dayCloseAllowed($cashier, $this->company->fresh()));

        $response = $this->actingAs($cashier, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('canDayClose', false);

        $response->assertSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
        $response->assertSee(__('pos.dash_unclosed_days_info_only'));
        $response->assertDontSee(__('pos.dash_unclosed_days_action'));
        $response->assertDontSee(route('fbrpos.day-close', ['date' => $day]), false);
    }

    /** Prior day properly closed → no warning at all. */
    public function test_no_warning_when_prior_day_is_closed(): void
    {
        $day = $this->strandPriorDay();
        $this->closeDay($day);

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
    }

    /**
     * Pre-cutoff grace (Task 489): at 01:00 a shop trading past midnight is
     * still inside YESTERDAY's business day (default cutoff 06:00), so an
     * unclosed yesterday must NOT be flagged as stranded.
     */
    public function test_yesterday_not_flagged_before_cutoff(): void
    {
        Carbon::setTestNow(now()->setTime(1, 0));
        \App\Services\PosBusinessDay::forgetCutoff($this->company->id);
        $this->strandPriorDay(); // bill yesterday 14:00, never closed

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
        $response->assertDontSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
    }

    /** Grace covers ONLY yesterday: a genuinely older stranded day still warns at 01:00. */
    public function test_older_stranded_day_still_flagged_before_cutoff(): void
    {
        Carbon::setTestNow(now()->setTime(1, 0));
        \App\Services\PosBusinessDay::forgetCutoff($this->company->id);
        $old = now()->subDays(2)->setTime(14, 0);
        DB::table('fbr_pos_transactions')->insert([
            'company_id' => $this->company->id,
            'invoice_number' => 'FPOS-STR-OLD',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $this->strandPriorDay(); // yesterday: covered by grace

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', function ($days) use ($old) {
            return $days->count() === 1 && $days->first() === $old->toDateString();
        });
        $response->assertSee(trans_choice('pos.dash_unclosed_days_title', 1, ['count' => 1]));
    }

    /** From the cutoff on (06:00), an unclosed yesterday is flagged as usual. */
    public function test_yesterday_flagged_at_cutoff(): void
    {
        Carbon::setTestNow(now()->setTime(6, 0));
        \App\Services\PosBusinessDay::forgetCutoff($this->company->id);
        $day = $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/dashboard');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', function ($days) use ($day) {
            return $days->count() === 1 && $days->first() === $day;
        });
    }

    /** Grace also applies on the day-close page's detailed banner. */
    public function test_day_close_page_respects_grace_before_cutoff(): void
    {
        Carbon::setTestNow(now()->setTime(1, 0));
        \App\Services\PosBusinessDay::forgetCutoff($this->company->id);
        $this->strandPriorDay();

        $response = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/day-close');
        $response->assertOk();
        $response->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
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

    private function closeDay(string $day): void
    {
        DB::table('fbr_day_close_reports')->insert([
            'company_id' => $this->company->id,
            'report_date' => $day,
            'report_number' => 'FZ-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $posRole, string $email): User
    {
        return User::create([
            'name' => ucfirst($posRole), 'email' => $email,
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => $posRole === 'pos_cashier' ? 'user' : 'company_admin',
            'pos_role' => $posRole,
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
            'name' => 'Stranded FBR Shop', 'product_type' => 'fbrpos',
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
            'name' => 'Admin', 'email' => 'admin@fbrtest.pk',
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
            // Day-close cashier switch intentionally ABSENT (PROD-drift shape)
            // → dayCloseAllowed falls back to FALSE for cashiers, TRUE for admins.
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

        Schema::create('fbr_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
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
    }
}
