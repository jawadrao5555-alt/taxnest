<?php

namespace Tests\Feature;

use App\Models\AiPageLedger;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\AiPageCreditService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AI READER PAGES ARE MONEY (Sep 2026 DI restructure).
 *
 * Two pockets, and the order between them is the whole deal the shop was sold:
 *   1. the package's MONTHLY allowance — resets on the 1st, never carries over
 *   2. the PURCHASED balance — paid for in cash, so it must never expire and
 *      must never be spent while free allowance is still sitting there
 *
 * A refund has to go back to the pocket it came out of — purchased pages
 * FIRST, otherwise a failed batch quietly converts paid pages into allowance
 * pages that die at the month boundary.
 *
 * The other half is cost: a strong-model read is ~14x the API cost of a mini
 * read, so it burns 10 pages. Charging it as 1 would hand the expensive read
 * away, and admitting a read the shop cannot pay for does the same thing by a
 * different route — the affordability check must therefore be made against the
 * REAL cost, not against "at least one page left".
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/AiPageCreditTest.php --testdox
 */
class AiPageCreditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('ai_page_balance')->default(0);
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
            $t->integer('ai_page_limit')->default(0);
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

        Schema::create('ai_page_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('kind');
            $t->integer('from_allowance')->default(0);
            $t->integer('from_balance')->default(0);
            $t->string('source')->nullable();
            $t->string('ref_type')->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });

        // The service caches its schema probe per process, and these tests
        // rebuild the schema between cases.
        AiPageCreditService::forgetLedgerProbe();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function shop(int $monthlyPages, int $purchased = 0): Company
    {
        $plan = PricingPlan::forceCreate([
            'name' => 'Asaan',
            'is_trial' => false,
            'invoice_limit' => 700,
            'ai_page_limit' => $monthlyPages,
            'product_type' => 'di',
            'price' => 1799,
        ]);

        $company = Company::forceCreate([
            'name' => 'Test Traders',
            'product_type' => 'di',
            'is_internal_account' => false,
            'ai_page_balance' => $purchased,
        ]);

        Subscription::forceCreate([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(11),
        ]);

        return $company->fresh();
    }

    // ------------------------------------------------------- pocket ordering

    public function test_free_allowance_is_spent_before_purchased_pages(): void
    {
        $company = $this->shop(5, 100);

        $entry = AiPageCreditService::consume($company, 3, 'single_parse');

        $this->assertSame(3, $entry->from_allowance);
        $this->assertSame(0, $entry->from_balance);
        $this->assertSame(100, (int) $company->fresh()->ai_page_balance, 'Paid pages must not be touched first.');
    }

    public function test_a_read_that_straddles_the_two_pockets_splits_correctly(): void
    {
        $company = $this->shop(5, 100);
        AiPageCreditService::consume($company, 4, 'single_parse');

        // 1 allowance page left, this read wants 6.
        $entry = AiPageCreditService::consume($company->fresh(), 6, 'single_parse');

        $this->assertSame(1, $entry->from_allowance);
        $this->assertSame(5, $entry->from_balance);
        $this->assertSame(95, (int) $company->fresh()->ai_page_balance);
    }

    public function test_purchased_pages_survive_the_month_but_allowance_does_not(): void
    {
        $company = $this->shop(5, 20);
        AiPageCreditService::consume($company, 5, 'single_parse');
        AiPageCreditService::consume($company->fresh(), 4, 'single_parse');

        $this->assertSame(16, (int) $company->fresh()->ai_page_balance);
        $this->assertSame(0, AiPageCreditService::allowanceRemaining($company->fresh()));

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

        $this->assertSame(5, AiPageCreditService::allowanceRemaining($company->fresh()), 'Allowance resets on the 1st.');
        $this->assertSame(16, (int) $company->fresh()->ai_page_balance, 'Paid pages never expire.');
    }

    public function test_unlimited_accounts_are_never_charged(): void
    {
        $company = $this->shop(-1, 10);

        $entry = AiPageCreditService::consume($company, 10, 'single_parse');

        $this->assertSame(0, $entry->from_allowance);
        $this->assertSame(0, $entry->from_balance);
        $this->assertSame(10, (int) $company->fresh()->ai_page_balance);
        $this->assertSame(-1, AiPageCreditService::totalRemaining($company->fresh()));
    }

    // ------------------------------------------------------------ affordability

    public function test_a_shop_out_of_pages_is_refused(): void
    {
        $company = $this->shop(2, 0);
        AiPageCreditService::consume($company, 2, 'single_parse');

        $verdict = AiPageCreditService::canConsume($company->fresh(), 1);

        $this->assertFalse($verdict['allowed']);
        $this->assertNotEmpty($verdict['reason']);
    }

    public function test_consume_refuses_rather_than_going_negative(): void
    {
        $company = $this->shop(1, 1);

        $this->expectException(\RuntimeException::class);
        AiPageCreditService::consume($company, 5, 'single_parse');
    }

    public function test_a_failed_consume_leaves_the_balance_untouched(): void
    {
        $company = $this->shop(1, 1);

        try {
            AiPageCreditService::consume($company, 5, 'single_parse');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(1, (int) $company->fresh()->ai_page_balance);
        $this->assertSame(0, AiPageLedger::where('kind', AiPageLedger::KIND_CONSUME)->count());
    }

    // ------------------------------------------------------------- page cost

    public function test_a_strong_model_read_costs_ten_pages_and_mini_costs_one(): void
    {
        $this->assertSame(1, AiPageCreditService::pageCostFor(null));
        $this->assertSame(1, AiPageCreditService::pageCostFor('gpt-4o-mini'));
        $this->assertSame(
            AiPageCreditService::STRONG_MODEL_PAGE_COST,
            AiPageCreditService::pageCostFor('gpt-4o')
        );
    }

    public function test_the_expensive_read_is_only_affordable_at_its_real_cost(): void
    {
        // Three pages left: a mini read is fine, the strong read is not — which
        // is exactly the gate the reader consults before escalating.
        $company = $this->shop(3, 0);

        $this->assertTrue(AiPageCreditService::canConsume($company, 1)['allowed']);
        $this->assertFalse(
            AiPageCreditService::canConsume($company, AiPageCreditService::STRONG_MODEL_PAGE_COST)['allowed'],
            'A 10-page read must not be admitted on a 3-page balance.'
        );
    }

    public function test_a_strong_read_burns_ten_pages_off_the_right_pockets(): void
    {
        $company = $this->shop(4, 50);

        $entry = AiPageCreditService::consume(
            $company,
            AiPageCreditService::pageCostFor('gpt-4o'),
            'single_parse'
        );

        $this->assertSame(4, $entry->from_allowance);
        $this->assertSame(6, $entry->from_balance);
        $this->assertSame(44, (int) $company->fresh()->ai_page_balance);
    }

    // ---------------------------------------------------------------- refunds

    public function test_a_refund_returns_purchased_pages_first(): void
    {
        $company = $this->shop(4, 50);
        $entry = AiPageCreditService::consume($company, 10, 'bulk_import');

        AiPageCreditService::refund($entry);

        $this->assertSame(50, (int) $company->fresh()->ai_page_balance, 'Paid pages must come back.');
        $this->assertSame(4, AiPageCreditService::allowanceRemaining($company->fresh()));
    }

    public function test_a_partial_refund_takes_the_paid_pages_back_before_allowance(): void
    {
        $company = $this->shop(4, 50);
        $entry = AiPageCreditService::consume($company, 10, 'bulk_import');

        AiPageCreditService::refund($entry, 6);

        // All 6 paid pages restored; the allowance share stays spent.
        $this->assertSame(50, (int) $company->fresh()->ai_page_balance);
        $this->assertSame(0, AiPageCreditService::allowanceRemaining($company->fresh()));
    }

    public function test_the_same_charge_cannot_be_refunded_twice(): void
    {
        $company = $this->shop(0, 50);
        $entry = AiPageCreditService::consume($company, 10, 'bulk_import');

        AiPageCreditService::refund($entry);
        AiPageCreditService::refund($entry);
        AiPageCreditService::refund($entry, 10);

        $this->assertSame(50, (int) $company->fresh()->ai_page_balance, 'Double refund would mint free pages.');
        $this->assertSame(1, AiPageLedger::where('kind', AiPageLedger::KIND_REFUND)->count());
    }

    public function test_a_topup_adds_pages_that_never_expire(): void
    {
        $company = $this->shop(2, 0);

        AiPageCreditService::credit($company, 500, AiPageLedger::KIND_TOPUP, ['note' => '500-pack']);

        $this->assertSame(500, (int) $company->fresh()->ai_page_balance);

        Carbon::setTestNow(Carbon::parse('2027-01-05 09:00:00'));
        $this->assertSame(500, AiPageCreditService::purchasedBalance($company->fresh()));
        $this->assertSame(502, AiPageCreditService::totalRemaining($company->fresh()));
    }

    public function test_an_admin_grant_is_recorded_separately_from_a_purchase(): void
    {
        $company = $this->shop(2, 0);

        AiPageCreditService::credit($company, 25, AiPageLedger::KIND_ADMIN_GRANT, ['note' => 'goodwill']);

        $row = AiPageLedger::first();
        $this->assertSame(AiPageLedger::KIND_ADMIN_GRANT, $row->kind);
        $this->assertSame(25, (int) $company->fresh()->ai_page_balance);
    }

    // ------------------------------------------------------- schema tolerance

    public function test_nothing_explodes_before_the_migration_has_run(): void
    {
        $company = $this->shop(5, 10);

        Schema::drop('ai_page_ledgers');
        AiPageCreditService::forgetLedgerProbe();

        // A production box that has not migrated yet must keep reading
        // invoices, just without bookkeeping.
        $this->assertNull(AiPageCreditService::consume($company, 1, 'single_parse'));
        $this->assertNull(AiPageCreditService::credit($company, 10));
        $this->assertSame(0, AiPageCreditService::usedThisMonth($company));
    }
}
