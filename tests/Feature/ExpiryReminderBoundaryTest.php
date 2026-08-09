<?php

namespace Tests\Feature;

use App\Console\Commands\SendTrialReminders;
use App\Models\Company;
use App\Models\Notification;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\SubscriptionAccessService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK: Expiry-reminder window boundary tests (today, +1, +2, +3 days, past).
 *
 * Locks the 2-day early-warning behaviour introduced 1 Aug 2026:
 *   - Banner services: paidEndingReminder / overrideReminder / trialStatus
 *   - Email command:   trial:reminders (SendTrialReminders) incl. dedup
 *   - Banner & email eligibility parity (non-trial plan required for paid path)
 *
 * All times are frozen at 2026-08-01 12:00 (Carbon::setTestNow).
 */
class ExpiryReminderBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('product_type')->default('di');
            $t->boolean('is_internal_account')->default(false);
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('role')->nullable();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->string('product_type')->default('di');
            $t->decimal('price', 12, 2)->nullable();
            $t->decimal('price_quarterly', 12, 2)->nullable();
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
            $t->timestamps();
        });

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function company(array $attrs = []): Company
    {
        return Company::forceCreate(array_merge([
            'name' => 'Boundary Test Co',
            'email' => 'owner@example.test',
            'product_type' => 'di',
            'is_internal_account' => false,
            'status' => 'active',
            'company_status' => 'active',
        ], $attrs));
    }

    private function plan(bool $trial = false, ?int $invoiceLimit = null, array $attrs = []): PricingPlan
    {
        return PricingPlan::forceCreate(array_merge([
            'name' => $trial ? 'Free Trial' : 'Paid Plan',
            'is_trial' => $trial,
            'invoice_limit' => $invoiceLimit,
        ], $attrs));
    }

    private function paidSub(Company $c, ?string $endDate, ?PricingPlan $plan = null): Subscription
    {
        return Subscription::forceCreate([
            'company_id' => $c->id,
            'pricing_plan_id' => ($plan ?? $this->plan(false))->id,
            'active' => true,
            'end_date' => $endDate,
            'override_type' => 'none',
        ]);
    }

    private function overrideSub(Company $c, ?Carbon $until, ?int $freeLimit = null): Subscription
    {
        return Subscription::forceCreate([
            'company_id' => $c->id,
            'pricing_plan_id' => $this->plan(false)->id,
            'active' => true,
            'override_type' => 'temporary',
            'override_until' => $until,
            'override_granted_at' => now()->subDays(5),
            'free_invoice_limit' => $freeLimit,
        ]);
    }

    private function trialSub(Company $c, ?Carbon $endsAt, ?int $invoiceLimit = 20): Subscription
    {
        return Subscription::forceCreate([
            'company_id' => $c->id,
            'pricing_plan_id' => $this->plan(true, $invoiceLimit)->id,
            'active' => true,
            'trial_ends_at' => $endsAt,
            'override_type' => 'none',
        ]);
    }

    private function runCommand(): void
    {
        $this->artisan('trial:reminders')->assertExitCode(0);
    }

    // ------------------------------------------- paidEndingReminder (banner)

    public function test_paid_ending_reminder_end_date_today_shows_zero_days(): void
    {
        $c = $this->company();
        $this->paidSub($c, now()->toDateString());

        $r = SubscriptionAccessService::paidEndingReminder($c);
        $this->assertNotNull($r);
        $this->assertSame(0, $r['days_left']);
        $this->assertSame('2026-08-01', $r['until']);
    }

    public function test_paid_ending_reminder_plus_one_and_two_days(): void
    {
        $c1 = $this->company();
        $this->paidSub($c1, now()->addDay()->toDateString());
        $r1 = SubscriptionAccessService::paidEndingReminder($c1);
        $this->assertNotNull($r1);
        $this->assertSame(1, $r1['days_left']);

        $c2 = $this->company();
        $this->paidSub($c2, now()->addDays(2)->toDateString());
        $r2 = SubscriptionAccessService::paidEndingReminder($c2);
        $this->assertNotNull($r2);
        $this->assertSame(2, $r2['days_left']);
    }

    public function test_paid_ending_reminder_plus_three_days_is_null(): void
    {
        $c = $this->company();
        $this->paidSub($c, now()->addDays(3)->toDateString());
        $this->assertNull(SubscriptionAccessService::paidEndingReminder($c));
    }

    public function test_paid_ending_reminder_past_end_date_is_null(): void
    {
        $c = $this->company();
        $this->paidSub($c, now()->subDay()->toDateString());
        $this->assertNull(SubscriptionAccessService::paidEndingReminder($c));
    }

    public function test_paid_ending_reminder_requires_non_trial_plan(): void
    {
        // Banner/email eligibility parity: a trial plan never triggers the PAID path.
        $c = $this->company();
        $this->paidSub($c, now()->addDay()->toDateString(), $this->plan(true, 20));
        $this->assertNull(SubscriptionAccessService::paidEndingReminder($c));
    }

    public function test_paid_ending_reminder_ignores_override_subscriptions(): void
    {
        $c = $this->company();
        $sub = $this->paidSub($c, now()->addDay()->toDateString());
        $sub->update(['override_type' => 'temporary', 'override_until' => now()->addDays(10)]);
        $this->assertNull(SubscriptionAccessService::paidEndingReminder($c));
    }

    // --------------------------------------------- overrideReminder (banner)

    public function test_override_reminder_until_later_today(): void
    {
        $c = $this->company();
        $this->overrideSub($c, now()->endOfDay());

        $r = SubscriptionAccessService::overrideReminder($c);
        $this->assertNotNull($r);
        $this->assertSame('2026-08-01', $r['until']);
        // Calendar-day diff: expiring later today = 0 → banner shows "ends today".
        $this->assertSame(0, $r['days_left']);
    }

    public function test_override_reminder_plus_one_two_three_days(): void
    {
        foreach ([1, 2, 3] as $days) {
            $c = $this->company();
            $this->overrideSub($c, now()->addDays($days));
            $r = SubscriptionAccessService::overrideReminder($c);
            $this->assertNotNull($r, "override +{$days}d should return a reminder");
            $this->assertSame($days, $r['days_left'], "override +{$days}d days_left");
        }
    }

    public function test_override_reminder_past_until_is_null(): void
    {
        $c = $this->company();
        $this->overrideSub($c, now()->subDay());
        $this->assertNull(SubscriptionAccessService::overrideReminder($c));
    }

    public function test_override_reminder_reports_invoices_left(): void
    {
        $c = $this->company();
        $this->overrideSub($c, now()->addDay(), 10);
        $r = SubscriptionAccessService::overrideReminder($c);
        $this->assertNotNull($r);
        $this->assertSame(10, $r['invoices_left']);
    }

    // -------------------------------------------------- trialStatus (banner)

    public function test_trial_status_boundaries(): void
    {
        // Ends later today → days_left exactly 0 ("expires today"), still on trial.
        $c0 = $this->company();
        $this->trialSub($c0, now()->endOfDay());
        $s0 = SubscriptionAccessService::trialStatus($c0);
        $this->assertNotNull($s0);
        $this->assertTrue($s0['on_trial']);
        $this->assertSame(0, $s0['days_left']);

        foreach ([1, 2, 3] as $days) {
            $c = $this->company();
            $this->trialSub($c, now()->addDays($days));
            $s = SubscriptionAccessService::trialStatus($c);
            $this->assertNotNull($s, "trial +{$days}d should be on trial");
            $this->assertSame($days, $s['days_left'], "trial +{$days}d days_left");
        }
    }

    public function test_trial_status_null_when_expired(): void
    {
        // Past trial → hasAccess denies → lock modal owns messaging, banner silent.
        $c = $this->company();
        $this->trialSub($c, now()->subDay());
        $this->assertNull(SubscriptionAccessService::trialStatus($c));
    }

    public function test_trial_status_null_for_non_trial_plan_and_active_override(): void
    {
        $c1 = $this->company();
        $this->paidSub($c1, now()->addYear()->toDateString());
        $this->assertNull(SubscriptionAccessService::trialStatus($c1));

        $c2 = $this->company();
        $sub = $this->trialSub($c2, now()->addDay());
        $sub->update(['override_type' => 'temporary', 'override_until' => now()->addDays(30)]);
        $this->assertNull(SubscriptionAccessService::trialStatus($c2));
    }

    // ----------------------------------------- SendTrialReminders — trial emails

    public function test_trial_email_fires_at_2_days_not_3(): void
    {
        $cIn = $this->company(['email' => 'in@example.test']);
        $this->trialSub($cIn, now()->addDays(2), null);

        $cOut = $this->company(['email' => 'out@example.test']);
        $this->trialSub($cOut, now()->addDays(3), null);

        $this->runCommand();

        $this->assertSame(1, Notification::where('company_id', $cIn->id)->where('type', 'trial_reminder_day_1')->count());
        $this->assertSame(0, Notification::where('company_id', $cOut->id)->count());
        Mail::assertSent(\App\Mail\TrialReminderMail::class, 1);
    }

    public function test_trial_email_not_sent_for_expired_trial(): void
    {
        $c = $this->company();
        $this->trialSub($c, now()->subDay(), null);
        $this->runCommand();
        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
    }

    public function test_trial_email_dedup_on_second_run(): void
    {
        $c = $this->company();
        $this->trialSub($c, now()->addDay(), null);

        $this->runCommand();
        $this->runCommand();

        $this->assertSame(1, Notification::where('company_id', $c->id)->where('type', 'trial_reminder_day_1')->count());
        Mail::assertSent(\App\Mail\TrialReminderMail::class, 1);
    }

    // -------------------------------------- SendTrialReminders — override emails

    public function test_override_email_window_boundaries(): void
    {
        $today = $this->company(['email' => 'a@example.test']);
        $this->overrideSub($today, now()->endOfDay());          // later today → in window

        $earlier = $this->company(['email' => 'b@example.test']);
        $this->overrideSub($earlier, now()->subHours(2));        // earlier today (past) → out

        $plus2 = $this->company(['email' => 'c@example.test']);
        $this->overrideSub($plus2, now()->addDays(2));           // +2 → in

        $plus3 = $this->company(['email' => 'd@example.test']);
        $this->overrideSub($plus3, now()->addDays(3));           // +3 → out

        $this->runCommand();

        $this->assertSame(1, Notification::where('company_id', $today->id)->where('type', 'like', 'override_reminder_%')->count());
        $this->assertSame(0, Notification::where('company_id', $earlier->id)->count());
        $this->assertSame(1, Notification::where('company_id', $plus2->id)->where('type', 'like', 'override_reminder_%')->count());
        $this->assertSame(0, Notification::where('company_id', $plus3->id)->count());
    }

    public function test_fbrpos_temporary_override_email_fires_at_7_days_not_8(): void
    {
        // FBR POS temporary grants warn 7 days ahead (free-access expiry reminder, Aug 2026).
        $in = $this->company(['product_type' => 'fbrpos', 'email' => 'f7@example.test']);
        $this->overrideSub($in, now()->addDays(7));

        $out = $this->company(['product_type' => 'fbrpos', 'email' => 'f8@example.test']);
        $this->overrideSub($out, now()->addDays(8));

        // Non-fbrpos stays at the 2-day window even inside the widened query range.
        $di = $this->company(['product_type' => 'di', 'email' => 'd7@example.test']);
        $this->overrideSub($di, now()->addDays(7));

        $this->runCommand();

        $this->assertSame(1, Notification::where('company_id', $in->id)->where('type', 'like', 'override_reminder_%')->count());
        $this->assertSame(0, Notification::where('company_id', $out->id)->count());
        $this->assertSame(0, Notification::where('company_id', $di->id)->count());
    }

    public function test_override_reminder_returns_type(): void
    {
        $c = $this->company(['product_type' => 'fbrpos']);
        $this->overrideSub($c, now()->addDays(5));
        $r = SubscriptionAccessService::overrideReminder($c);
        $this->assertNotNull($r);
        $this->assertSame('temporary', $r['type']);
    }

    public function test_override_email_dedup_per_expiry_date(): void
    {
        $c = $this->company();
        $sub = $this->overrideSub($c, now()->addDay());

        $this->runCommand();
        $this->runCommand();
        $this->assertSame(1, Notification::where('company_id', $c->id)->count());

        // Extended override (NEW date within window) → warns again for the new date.
        $sub->update(['override_until' => now()->addDays(2)]);
        $this->runCommand();
        $this->assertSame(2, Notification::where('company_id', $c->id)->count());
    }

    // ------------------------------------------ SendTrialReminders — paid emails

    public function test_paid_email_window_boundaries(): void
    {
        $today = $this->company(['email' => 'p0@example.test']);
        $this->paidSub($today, now()->toDateString());           // today → in (startOfDay window)

        $plus2 = $this->company(['email' => 'p2@example.test']);
        $this->paidSub($plus2, now()->addDays(2)->toDateString()); // +2 → in

        $plus3 = $this->company(['email' => 'p3@example.test']);
        $this->paidSub($plus3, now()->addDays(3)->toDateString()); // +3 → out

        $past = $this->company(['email' => 'pp@example.test']);
        $this->paidSub($past, now()->subDay()->toDateString());    // past → out

        $this->runCommand();

        $this->assertSame(1, Notification::where('company_id', $today->id)->where('type', 'like', 'sub_renewal_reminder_%')->count());
        $this->assertSame(1, Notification::where('company_id', $plus2->id)->where('type', 'like', 'sub_renewal_reminder_%')->count());
        $this->assertSame(0, Notification::where('company_id', $plus3->id)->count());
        $this->assertSame(0, Notification::where('company_id', $past->id)->count());
        Mail::assertSent(\App\Mail\TrialReminderMail::class, 2);
    }

    public function test_pos_paid_email_quotes_current_plan_rates(): void
    {
        // Task 221: POS renewal reminder must quote the plan's CURRENT
        // pricing_plans rate (annual + quarterly when set) so the repriced
        // renewal is no surprise.
        $c = $this->company(['product_type' => 'pos', 'email' => 'pos@example.test']);
        $plan = $this->plan(false, null, [
            'name' => 'Unlimited',
            'product_type' => 'pos',
            'price' => 69999,
            'price_quarterly' => 19999,
        ]);
        $this->paidSub($c, now()->addDay()->toDateString(), $plan);

        $this->runCommand();

        Mail::assertSent(\App\Mail\TrialReminderMail::class, function ($mail) {
            $joined = implode(' ', $mail->paragraphs);
            return str_contains($joined, 'Unlimited')
                && str_contains($joined, 'Rs 69,999')
                && str_contains($joined, 'per year')
                && str_contains($joined, 'Rs 19,999')
                && str_contains($joined, 'per quarter');
        });
    }

    public function test_pos_paid_email_omits_quarterly_when_not_set(): void
    {
        $c = $this->company(['product_type' => 'pos', 'email' => 'pos2@example.test']);
        $plan = $this->plan(false, null, [
            'name' => 'Starter',
            'product_type' => 'pos',
            'price' => 24999,
            'price_quarterly' => null,
        ]);
        $this->paidSub($c, now()->addDay()->toDateString(), $plan);

        $this->runCommand();

        Mail::assertSent(\App\Mail\TrialReminderMail::class, function ($mail) {
            $joined = implode(' ', $mail->paragraphs);
            return str_contains($joined, 'Rs 24,999')
                && !str_contains($joined, 'per quarter');
        });
    }

    public function test_non_pos_paid_email_has_no_rate_line(): void
    {
        // DI/FBR POS rates unchanged — no rate quote for non-POS lines.
        $c = $this->company(['product_type' => 'di', 'email' => 'di@example.test']);
        $plan = $this->plan(false, null, [
            'name' => 'DI Plan',
            'product_type' => 'di',
            'price' => 4999,
        ]);
        $this->paidSub($c, now()->addDay()->toDateString(), $plan);

        $this->runCommand();

        Mail::assertSent(\App\Mail\TrialReminderMail::class, function ($mail) {
            $joined = implode(' ', $mail->paragraphs);
            return !str_contains($joined, 'Renewal rate');
        });
    }

    public function test_paid_email_requires_non_trial_plan_matching_banner(): void
    {
        // Same eligibility rule as paidEndingReminder(): trial plans excluded.
        $c = $this->company();
        $this->paidSub($c, now()->addDay()->toDateString(), $this->plan(true, 20));
        $this->runCommand();
        $this->assertSame(0, Notification::where('company_id', $c->id)->where('type', 'like', 'sub_renewal_reminder_%')->count());
    }

    public function test_paid_email_dedup_per_end_date(): void
    {
        $c = $this->company();
        $sub = $this->paidSub($c, now()->addDay()->toDateString());

        $this->runCommand();
        $this->runCommand();
        $this->assertSame(1, Notification::where('company_id', $c->id)->count());

        // Renewal to a new end_date re-arms the warning for the new period.
        $sub->update(['end_date' => now()->addDays(2)->toDateString()]);
        $this->runCommand();
        $this->assertSame(2, Notification::where('company_id', $c->id)->count());
    }

    public function test_internal_accounts_get_no_reminder_emails(): void
    {
        $c = $this->company(['is_internal_account' => true]);
        $this->paidSub($c, now()->addDay()->toDateString());
        $this->trialSub($this->company(['is_internal_account' => true]), now()->addDay());
        $this->overrideSub($this->company(['is_internal_account' => true]), now()->addDay());

        $this->runCommand();

        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
    }
}
