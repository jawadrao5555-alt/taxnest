<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * AUTO DAY-CLOSE SKIP ALERT (Task 454, Aug 2026).
 *
 * When pos:auto-dayclose skips a restaurant company because orders are still
 * open (owner policy 10 Aug 2026), the owner must get an EMAIL — the log line
 * reaches nobody. Locked guarantees:
 *
 *   1. Skip due to open orders → one email to the company OWNER
 *      (users.role = 'company_admin'; pos_role may be NULL on owner rows).
 *   2. Throttle: the hourly command sends at most ONE alert per company per
 *      calendar day (cache key includes the date).
 *   3. Next morning still stranded → a FRESH alert goes out.
 *   4. Orders settled → no skip alert at all.
 *   5. Fallback recipient: a pos_role pos_admin account when no role-owner.
 *
 * Pattern: sqlite :memory: + minimal Schema::create (see
 * PosDayCloseOpenOrdersWarningTest). The close path itself (performDayClose)
 * is out of scope here — its failures are caught per-company by the command.
 */
class PosAutoDayCloseSkipAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Cache::flush();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_auto_dayclose_24h')->default(false);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->date('business_date')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('table_number');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('status')->default('held');
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(): Company
    {
        return Company::create([
            'name' => 'Skip Alert Test',
            'product_type' => 'pos',
            'restaurant_mode' => true,
            'pos_auto_dayclose_24h' => true,
        ]);
    }

    /** An un-closed prior business day so the command has something to sweep. */
    private function strandedDay(Company $c): void
    {
        \DB::table('pos_transactions')->insert([
            'company_id' => $c->id,
            'business_date' => today()->subDays(3)->toDateString(),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
    }

    private function openOrder(Company $c, ?int $tableId = null): void
    {
        $o = RestaurantOrder::create(['company_id' => $c->id, 'table_id' => $tableId, 'status' => 'held']);
        RestaurantOrderItem::create(['order_id' => $o->id, 'item_name' => 'Karahi']);
    }

    public function test_skip_emails_the_owner_even_when_pos_role_is_null(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);
        // Owner row: role=company_admin, pos_role NULL (the common live shape).
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin', 'pos_role' => null]);

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        Mail::assertSent(\App\Mail\TrialReminderMail::class, function ($mail) {
            return $mail->hasTo('owner@shop.pk');
        });
    }

    public function test_falls_back_to_pos_admin_when_no_owner_row(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);
        User::forceCreate(['company_id' => $c->id, 'email' => 'cashierboss@shop.pk', 'role' => 'user', 'pos_role' => 'pos_admin']);

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        Mail::assertSent(\App\Mail\TrialReminderMail::class, fn ($m) => $m->hasTo('cashierboss@shop.pk'));
    }

    public function test_owner_preferred_over_pos_admin(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);
        User::forceCreate(['company_id' => $c->id, 'email' => 'cashierboss@shop.pk', 'role' => 'user', 'pos_role' => 'pos_admin']);
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin', 'pos_role' => null]);

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        Mail::assertSent(\App\Mail\TrialReminderMail::class, fn ($m) => $m->hasTo('owner@shop.pk'));
        Mail::assertNotSent(\App\Mail\TrialReminderMail::class, fn ($m) => $m->hasTo('cashierboss@shop.pk'));
    }

    public function test_hourly_reruns_send_only_one_alert_per_day(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin']);

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        Mail::assertSentTimes(\App\Mail\TrialReminderMail::class, 1);
    }

    public function test_next_day_still_stranded_sends_a_fresh_alert(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin']);

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        \Carbon\Carbon::setTestNow(now()->addDay());
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        Mail::assertSentTimes(\App\Mail\TrialReminderMail::class, 2);
    }

    public function test_mail_failure_does_not_consume_the_daily_quota(): void
    {
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin']);

        // First run: SMTP blows up.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        // Same-day retry (next hourly run) must send. (Mail::fake() cannot be
        // layered over the Mockery facade mock, so assert via the mock chain.)
        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->once()->with(\Mockery::type(\App\Mail\TrialReminderMail::class));
        Mail::shouldReceive('to')->once()->with('owner@shop.pk')->andReturn($pending);
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);
    }

    public function test_missing_recipient_does_not_consume_the_daily_quota(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        $this->openOrder($c);

        // No admin user yet — nothing sent, quota must stay free.
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);
        Mail::assertNotSent(\App\Mail\TrialReminderMail::class);

        // Admin appears later the same day — next hourly run alerts them.
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin']);
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);
        Mail::assertSent(\App\Mail\TrialReminderMail::class, fn ($m) => $m->hasTo('owner@shop.pk'));
    }

    public function test_no_alert_when_orders_are_settled(): void
    {
        Mail::fake();
        $c = $this->makeCompany();
        $this->strandedDay($c);
        // completed order + item-less shell — neither counts as "open".
        $done = RestaurantOrder::create(['company_id' => $c->id, 'status' => 'completed']);
        RestaurantOrderItem::create(['order_id' => $done->id, 'item_name' => 'Karahi']);
        RestaurantOrder::create(['company_id' => $c->id, 'status' => 'held']); // no items
        User::forceCreate(['company_id' => $c->id, 'email' => 'owner@shop.pk', 'role' => 'company_admin']);

        // The command proceeds to the close path (out of scope here; per-company
        // try/catch swallows any close failure) — the assertion is simply that
        // NO skip alert goes out.
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        Mail::assertNotSent(\App\Mail\TrialReminderMail::class);
    }
}
