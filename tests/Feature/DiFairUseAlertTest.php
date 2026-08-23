<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Notification;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\PlanLimitService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FAIR USE MUST WARN THE OFFICE, NEVER THE SHOP (Sep 2026 DI restructure).
 *
 * The "Unlimited" Digital Invoice package is sold as unlimited invoicing with
 * a fair-use figure printed alongside it. The owner's rule is explicit: that
 * figure NEVER blocks, throttles or nags the customer — it exists so the
 * office can notice one account running far past what the package assumes and
 * ring them about a custom arrangement.
 *
 * Two failures this locks:
 *   1. SILENCE — nobody hears about it, and a 80,000-invoice month surfaces
 *      months later in a report.
 *   2. OVER-REACH — a future "enforcement" tweak starts refusing invoices on
 *      an unlimited package, which is exactly what was sold against.
 *
 * Also locked: one alert per company per calendar month (a daily scheduler
 * must not mail the office every morning), a fresh alert next month, and the
 * packages that must be skipped entirely (numeric limits, internal accounts).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/DiFairUseAlertTest.php --testdox
 */
class DiFairUseAlertTest extends TestCase
{
    private const CEILING = 25000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('product_type')->default('di');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('fair_use_limit')->nullable();
            $t->string('product_type')->default('di');
            $t->decimal('price', 12, 2)->nullable();
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
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type')->nullable();
            $t->string('title')->nullable();
            $t->text('message')->nullable();
            $t->boolean('read')->default(false);
            $t->text('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('fbr_status')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('role')->default('super_admin');
            $t->timestamps();
        });

        AdminUser::forceCreate([
            'name' => 'Office',
            'email' => 'office@taxnest.test',
            'password' => bcrypt('x'),
            'role' => 'super_admin',
        ]);

        config(['mail.default' => 'array']);
        Mail::mailer('array')->getSymfonyTransport()->messages()->all();

        // This file's invoices table carries submitted_at; other suites build a
        // leaner one. The per-process column probe must not travel between them.
        PlanLimitService::forgetSchemaProbe();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PlanLimitService::forgetSchemaProbe();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function unlimitedPlan(?int $ceiling = self::CEILING): PricingPlan
    {
        return PricingPlan::forceCreate([
            'name' => 'Unlimited',
            'is_trial' => false,
            'invoice_limit' => -1,
            'fair_use_limit' => $ceiling,
            'product_type' => 'di',
            'price' => 3999,
        ]);
    }

    private function shopOn(PricingPlan $plan, array $attrs = []): Company
    {
        $company = Company::forceCreate(array_merge([
            'name' => 'Busy Traders',
            'email' => 'busy@example.test',
            'product_type' => 'di',
            'is_internal_account' => false,
        ], $attrs));

        Subscription::forceCreate([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonths(9),
        ]);

        return $company;
    }

    /** Submitted invoices inside the current month — the only ones that count. */
    private function submitInvoices(Company $company, int $count, ?Carbon $when = null): void
    {
        $when ??= now()->startOfMonth()->addDays(2);
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'company_id' => $company->id,
                'fbr_status' => 'production',
                'submitted_at' => $when,
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('invoices')->insert($chunk);
        }
    }

    /**
     * Mail::fake() cannot be used — MailFake::raw() is an explicit no-op, so a
     * raw send records nothing. Same pattern as CloudflareRocketLoaderCheckTest:
     * run the real pipeline against the 'array' transport and read it back.
     */
    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    private function assertMailCount(int $expected): void
    {
        $this->assertCount($expected, $this->sentMessages());
    }

    private function runCommand(): void
    {
        $this->artisan('di:fair-use-alerts')->assertExitCode(0);
    }

    private function alertRows(Company $company): int
    {
        return Notification::where('company_id', $company->id)
            ->where('type', 'like', 'di_fair_use_admin_alert_%')
            ->count();
    }

    // ------------------------------------------------------------------ tests

    public function test_office_is_emailed_when_a_shop_passes_the_fair_use_figure(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 10);

        $this->runCommand();

        $this->assertMailCount(1);
        $this->assertSame(1, $this->alertRows($company), 'A dedup row must record the crossing.');

        $note = Notification::where('company_id', $company->id)->first();
        $this->assertStringContainsString('Nothing was blocked', $note->message);
    }

    public function test_the_shop_is_never_blocked_after_crossing(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 40);

        $this->runCommand();

        // The whole point of the package: invoicing continues.
        $verdict = PlanLimitService::canCreateInvoice($company->id);
        $this->assertTrue($verdict['allowed'] ?? false, 'An unlimited package must keep invoicing past fair use.');
    }

    public function test_a_second_run_in_the_same_month_does_not_mail_again(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 25);

        $this->runCommand();
        $this->runCommand();
        $this->runCommand();

        $this->assertMailCount(1);
        $this->assertSame(1, $this->alertRows($company));
    }

    public function test_next_month_raises_a_fresh_alert(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 12);
        $this->runCommand();
        $this->assertMailCount(1);

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));
        $this->submitInvoices($company, 12);
        $this->runCommand();

        $this->assertMailCount(2);
        $this->assertSame(2, $this->alertRows($company));
    }

    public function test_a_shop_below_the_figure_is_left_alone(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(50));
        $this->submitInvoices($company, 9);

        $this->runCommand();

        $this->assertMailCount(0);
        $this->assertSame(0, $this->alertRows($company));
    }

    public function test_last_months_invoices_do_not_trigger_this_month(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 30, now()->subMonth()->startOfMonth()->addDay());

        $this->runCommand();

        $this->assertMailCount(0);
    }

    public function test_drafts_never_count_towards_the_figure(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));

        DB::table('invoices')->insert(array_fill(0, 30, [
            'company_id' => $company->id,
            'fbr_status' => 'draft',
            'submitted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->runCommand();

        // Fair use is about what actually went to FBR, not what was typed.
        $this->assertMailCount(0);
    }

    public function test_a_submitted_invoice_missing_its_stamp_still_counts_by_creation_date(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));

        // Older rows predate submitted_at being written; the count falls back
        // to created_at, so they must not silently drop out of the figure.
        DB::table('invoices')->insert(array_fill(0, 12, [
            'company_id' => $company->id,
            'fbr_status' => 'production',
            'submitted_at' => null,
            'created_at' => now()->startOfMonth()->addDay(),
            'updated_at' => now(),
        ]));

        $this->runCommand();

        $this->assertMailCount(1);
    }

    public function test_an_expired_subscription_is_not_chased(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 40);

        // active=true survives until the reconcile job demotes it; the shop is
        // not invoicing on this package any more, so the office has nothing to
        // discuss.
        Subscription::where('company_id', $company->id)->update([
            'end_date' => now()->subDays(3),
        ]);

        $this->runCommand();

        $this->assertMailCount(0);
    }

    public function test_a_grant_keeps_an_end_dated_shop_in_scope(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 40);

        Subscription::where('company_id', $company->id)->update([
            'end_date' => now()->subDays(3),
            'override_type' => 'temporary',
            'override_until' => now()->addDays(10),
        ]);

        // Still trading on an admin grant — still worth a phone call.
        $this->runCommand();

        $this->assertMailCount(1);
    }

    public function test_packages_with_a_numeric_limit_are_skipped(): void
    {
        $capped = PricingPlan::forceCreate([
            'name' => 'Kaarobar',
            'is_trial' => false,
            'invoice_limit' => 2500,
            'fair_use_limit' => 10,
            'product_type' => 'di',
            'price' => 2499,
        ]);
        $company = $this->shopOn($capped);
        $this->submitInvoices($company, 40);

        $this->runCommand();

        // A capped package stops itself at its own limit; fair use is not its job.
        $this->assertMailCount(0);
        $this->assertSame(0, $this->alertRows($company));
    }

    public function test_internal_accounts_are_skipped(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10), ['is_internal_account' => true]);
        $this->submitInvoices($company, 99);

        $this->runCommand();

        $this->assertMailCount(0);
    }

    public function test_a_package_without_a_ceiling_never_alerts(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(null));
        $this->submitInvoices($company, 99);

        $this->runCommand();

        $this->assertMailCount(0);
    }

    public function test_a_broken_mailer_still_records_the_crossing_once(): void
    {
        $company = $this->shopOn($this->unlimitedPlan(10));
        $this->submitInvoices($company, 11);

        // Point the mailer at a transport that cannot deliver.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $this->runCommand();
        $this->runCommand();

        // Mail failure must not become a daily retry storm.
        $this->assertSame(1, $this->alertRows($company));
    }
}
