<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantCommission;
use App\Models\ConsultantInvite;
use App\Models\ConsultantProfile;
use App\Models\User;
use App\Models\PricingPlan;
use App\Services\ConsultantService;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TAX CONSULTANT CONSOLE — CONSENT & MONEY INVARIANTS
 *
 * 1. A consultant can NEVER reach a client company without client-side
 *    consent (approve or invite code). Pending/revoked/unlinked = 403.
 * 2. Revoking a link kicks a switched-in consultant out within ONE request.
 * 3. Exit always restores the consultant's own login.
 * 4. Referral attribution is first-touch at signup; invalid codes fail loudly.
 * 5. Commissions: one per recorded payment (subscription row), never for
 *    trials/zero amounts, rate from the consultant's profile.
 */
class ConsultantConsoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('pending');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->boolean('onboarding_completed')->default(true);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->unsignedBigInteger('referred_by_user_id')->nullable();
            $table->string('referral_code_used')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->decimal('discount_percent', 8, 2)->default(0);
            $table->decimal('final_price', 12, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->string('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('scope')->default('all');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('registered_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('credential_type');
            $table->string('credential_value', 191);
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['credential_type', 'credential_value']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('consultant_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('referral_code', 20)->unique();
            $table->string('status', 20)->default('active');
            $table->decimal('commission_rate', 5, 2)->default(10.00);
            $table->string('payout_notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('consultant_client_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consultant_user_id');
            $table->unsignedBigInteger('company_id');
            $table->string('status', 20)->default('pending');
            $table->string('initiated_by', 20)->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('revoked_by', 20)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['consultant_user_id', 'company_id']);
        });

        Schema::create('consultant_invites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('code', 20)->unique();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('used_by_user_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consultant_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consultant_user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('source', 30)->default('subscription');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by_admin_id')->nullable();
            $table->string('payout_reference')->nullable();
            $table->timestamps();
        });

        Mail::fake();
        Notification::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        static $i = 0;
        $i++;
        return Company::create(array_merge([
            'name' => "Company $i",
            'ntn' => "100000$i-$i",
            'product_type' => 'di',
            'status' => 'active',
            'company_status' => 'active',
            'onboarding_completed' => true,
        ], $attrs));
    }

    private function makeAdmin(Company $company, array $attrs = []): User
    {
        static $j = 0;
        $j++;
        return User::create(array_merge([
            'name' => "Admin $j",
            'email' => "admin$j@test.pk",
            'password' => Hash::make('secret-pass-123'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ], $attrs));
    }

    /** Consultant = DI company admin with an active consultant profile. */
    private function makeConsultant(): array
    {
        $company = $this->makeCompany();
        $user = $this->makeAdmin($company);
        $profile = ConsultantService::activateProfile($user);
        return [$user, $profile, $company];
    }

    // ── 1. Consent: switch is unreachable without an ACTIVE link ────────

    public function test_switch_forbidden_without_any_link(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $this->makeAdmin($client);

        $resp = $this->actingAs($consultant)->post("/consultant/switch/{$client->id}");

        $resp->assertForbidden();
        $this->assertAuthenticatedAs($consultant);
        $this->assertFalse(session()->has(ConsultantService::SESSION_KEY));
    }

    public function test_switch_forbidden_while_link_pending_or_revoked(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $this->makeAdmin($client);

        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'pending',
            'initiated_by' => 'consultant',
        ]);

        $this->actingAs($consultant)->post("/consultant/switch/{$client->id}")->assertForbidden();

        $link->update(['status' => 'revoked', 'revoked_by' => 'client', 'revoked_at' => now()]);
        $this->actingAs($consultant)->post("/consultant/switch/{$client->id}")->assertForbidden();
    }

    public function test_console_lists_only_actively_linked_companies(): void
    {
        [$consultant] = $this->makeConsultant();
        $active = $this->makeCompany(['name' => 'Linked Co']);
        $pending = $this->makeCompany(['name' => 'Pending Co']);
        $stranger = $this->makeCompany(['name' => 'Stranger Co']);

        ConsultantClientLink::create(['consultant_user_id' => $consultant->id, 'company_id' => $active->id, 'status' => 'active']);
        ConsultantClientLink::create(['consultant_user_id' => $consultant->id, 'company_id' => $pending->id, 'status' => 'pending']);

        $rows = ConsultantService::clientsWithHealth($consultant->id);

        $ids = array_map(fn ($r) => $r['company']->id, $rows);
        $this->assertSame([$active->id], $ids, 'Console must show ONLY active-link companies');
        $this->assertNotContains($pending->id, $ids);
        $this->assertNotContains($stranger->id, $ids);
    }

    // ── 2. Full consent flow: request → approve → switch → exit ─────────

    public function test_request_approve_switch_exit_flow(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany(['name' => 'Client Co']);
        $clientAdmin = $this->makeAdmin($client);

        // Consultant requests by NTN → pending.
        $this->actingAs($consultant)
            ->post('/consultant/request', ['ntn' => $client->ntn])
            ->assertRedirect('/consultant');

        $link = ConsultantClientLink::where('consultant_user_id', $consultant->id)
            ->where('company_id', $client->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('pending', $link->status);

        // Client admin approves — the consent moment.
        $this->actingAs($clientAdmin)
            ->post("/company/consultants/links/{$link->id}/approve")
            ->assertRedirect('/company/consultants');
        $this->assertSame('active', $link->fresh()->status);

        // Consultant switches in: web guard becomes the CLIENT admin + flag set.
        $this->actingAs($consultant)
            ->post("/consultant/switch/{$client->id}")
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($clientAdmin->fresh());
        $flag = session(ConsultantService::SESSION_KEY);
        $this->assertIsArray($flag);
        $this->assertSame($consultant->id, $flag['consultant_user_id']);
        $this->assertSame($client->id, $flag['client_company_id']);

        // Switch is audited with the CLIENT company scope + consultant as actor.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'consultant_switch_in',
            'company_id' => $client->id,
            'user_id' => $consultant->id,
        ]);

        // Exit restores the consultant's own login and clears the flag.
        $this->post('/consultant/exit')->assertRedirect('/consultant');
        $this->assertAuthenticatedAs($consultant->fresh());
        $this->assertFalse(session()->has(ConsultantService::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'consultant_switch_out',
            'company_id' => $client->id,
        ]);
    }

    // ── 3. Revoke mid-session forces exit within one request ────────────

    public function test_client_revoke_kicks_switched_consultant_on_next_request(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $clientAdmin = $this->makeAdmin($client);

        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'active',
        ]);

        $this->actingAs($consultant)->post("/consultant/switch/{$client->id}")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($clientAdmin->fresh());

        // Client (via another device/session) revokes consent.
        $link->update(['status' => 'revoked', 'revoked_by' => 'client', 'revoked_at' => now()]);

        // The very next request in the switched session must force-exit.
        $this->get('/dashboard')->assertRedirect('/consultant');
        $this->assertAuthenticatedAs($consultant->fresh());
        $this->assertFalse(session()->has(ConsultantService::SESSION_KEY));
    }

    // ── 4. Invite codes: single-use, expiring, client-generated ─────────

    public function test_invite_code_redeem_links_and_is_single_use(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $clientAdmin = $this->makeAdmin($client);

        // Client admin generates the code (consent artifact).
        $this->actingAs($clientAdmin)
            ->post('/company/consultants/invite')
            ->assertRedirect('/company/consultants');

        $invite = ConsultantInvite::where('company_id', $client->id)->first();
        $this->assertNotNull($invite);

        // Consultant redeems → active link immediately.
        $this->actingAs($consultant)
            ->post('/consultant/redeem', ['invite_code' => $invite->code])
            ->assertRedirect('/consultant');

        $link = ConsultantClientLink::where('consultant_user_id', $consultant->id)
            ->where('company_id', $client->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('active', $link->status);
        $this->assertNotNull($invite->fresh()->used_at);

        // Single use: a second consultant cannot reuse it.
        [$other] = $this->makeConsultant();
        $this->actingAs($other)
            ->post('/consultant/redeem', ['invite_code' => $invite->code])
            ->assertRedirect('/consultant')
            ->assertSessionHas('error');
        $this->assertNull(ConsultantClientLink::where('consultant_user_id', $other->id)
            ->where('company_id', $client->id)->first());
    }

    public function test_expired_or_revoked_invite_cannot_be_redeemed(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();

        $expired = ConsultantInvite::create([
            'company_id' => $client->id,
            'code' => 'CI-EXPIRED1',
            'expires_at' => now()->subHour(),
        ]);
        $revoked = ConsultantInvite::create([
            'company_id' => $client->id,
            'code' => 'CI-REVOKED1',
            'expires_at' => now()->addDay(),
            'revoked_at' => now(),
        ]);

        foreach ([$expired, $revoked] as $invite) {
            $this->actingAs($consultant)
                ->post('/consultant/redeem', ['invite_code' => $invite->code])
                ->assertSessionHas('error');
        }
        $this->assertSame(0, ConsultantClientLink::count());
    }

    // ── 5. Referral attribution at signup ───────────────────────────────

    public function test_referral_code_attributes_signup_and_invalid_code_fails(): void
    {
        [$consultant, $profile] = $this->makeConsultant();

        $resp = $this->post('/register', [
            'name' => 'Referred Owner',
            'email' => 'referred@test.pk',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name' => 'Referred Traders',
            'company_ntn' => '9999999-9',
            'referral_code' => strtolower($profile->referral_code), // case-insensitive
        ]);
        $resp->assertRedirect('/login');

        $company = Company::where('ntn', '9999999-9')->first();
        $this->assertNotNull($company);
        $this->assertSame($consultant->id, $company->referred_by_user_id);
        $this->assertSame($profile->referral_code, $company->referral_code_used);

        // Attribution does NOT auto-link console access — consent still required.
        $this->assertSame(0, ConsultantClientLink::where('company_id', $company->id)->count());

        // Invalid code = loud validation error, no company created.
        $bad = $this->post('/register', [
            'name' => 'Other Owner',
            'email' => 'other@test.pk',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name' => 'Other Traders',
            'company_ntn' => '8888888-8',
            'referral_code' => 'TC-DOESNOTEXIST',
        ]);
        $bad->assertSessionHasErrors('referral_code');
        $this->assertNull(Company::where('ntn', '8888888-8')->first());
    }

    // ── 6. Commissions from admin-recorded payments ─────────────────────

    public function test_commission_created_once_per_recorded_payment(): void
    {
        [$consultant, $profile] = $this->makeConsultant();
        $profile->update(['commission_rate' => 20.00]);

        $referred = $this->makeCompany(['referred_by_user_id' => $consultant->id]);
        $plan = PricingPlan::create([
            'name' => 'DI Pro',
            'product_type' => 'di',
            'price' => 2000,
            'is_trial' => false,
            'invoice_limit' => 500,
        ]);

        // Admin records a payment (single funnel).
        $sub1 = SubscriptionAssignmentService::assign($referred->id, $plan->id, 'monthly');

        $c = ConsultantCommission::where('subscription_id', $sub1->id)->first();
        $this->assertNotNull($c, 'Commission must be created for a referred company payment');
        $this->assertSame($consultant->id, $c->consultant_user_id);
        $this->assertSame('pending', $c->status);
        $this->assertEqualsWithDelta((float) $sub1->final_price * 0.20, (float) $c->amount, 0.01);

        // Duplicate-safe per subscription row.
        ConsultantService::recordCommissionForSubscription($sub1);
        $this->assertSame(1, ConsultantCommission::where('subscription_id', $sub1->id)->count());

        // A renewal (new subscription row) earns again.
        $sub2 = SubscriptionAssignmentService::assign($referred->id, $plan->id, 'annual');
        $this->assertSame(2, ConsultantCommission::count());

        // Non-referred companies never generate commissions.
        $normal = $this->makeCompany();
        SubscriptionAssignmentService::assign($normal->id, $plan->id, 'monthly');
        $this->assertSame(2, ConsultantCommission::count());
    }

    public function test_no_commission_for_trials_or_disabled_consultants(): void
    {
        [$consultant, $profile] = $this->makeConsultant();
        $referred = $this->makeCompany(['referred_by_user_id' => $consultant->id]);

        $trial = PricingPlan::create([
            'name' => 'DI Trial', 'product_type' => 'di', 'price' => 0, 'is_trial' => true, 'invoice_limit' => 20,
        ]);
        SubscriptionAssignmentService::assign($referred->id, $trial->id, 'monthly');
        $this->assertSame(0, ConsultantCommission::count(), 'Trials must not earn commissions');

        $paid = PricingPlan::create([
            'name' => 'DI Pro', 'product_type' => 'di', 'price' => 2000, 'is_trial' => false, 'invoice_limit' => 500,
        ]);
        $profile->update(['status' => 'disabled']);
        SubscriptionAssignmentService::assign($referred->id, $paid->id, 'monthly');
        $this->assertSame(0, ConsultantCommission::count(), 'Disabled consultants earn nothing new');
    }

    // ── 7. Client-side authority checks ─────────────────────────────────

    public function test_foreign_company_admin_cannot_approve_someone_elses_link(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $this->makeAdmin($client);

        $otherCompany = $this->makeCompany();
        $otherAdmin = $this->makeAdmin($otherCompany);

        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'pending',
        ]);

        $this->actingAs($otherAdmin)
            ->post("/company/consultants/links/{$link->id}/approve")
            ->assertForbidden();
        $this->assertSame('pending', $link->fresh()->status);
    }

    public function test_switch_rejected_for_non_di_or_inactive_client(): void
    {
        [$consultant] = $this->makeConsultant();

        $posClient = $this->makeCompany(['product_type' => 'pos']);
        $this->makeAdmin($posClient);
        ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id, 'company_id' => $posClient->id, 'status' => 'active',
        ]);

        $this->actingAs($consultant)
            ->post("/consultant/switch/{$posClient->id}")
            ->assertRedirect('/consultant');
        $this->assertAuthenticatedAs($consultant);
        $this->assertFalse(session()->has(ConsultantService::SESSION_KEY));

        $suspended = $this->makeCompany(['company_status' => 'suspended']);
        $this->makeAdmin($suspended);
        ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id, 'company_id' => $suspended->id, 'status' => 'active',
        ]);

        $this->actingAs($consultant)
            ->post("/consultant/switch/{$suspended->id}")
            ->assertRedirect('/consultant');
        $this->assertFalse(session()->has(ConsultantService::SESSION_KEY));
    }

    // ── Email notifications (task: consultant email ittila) ─────────────

    public function test_link_request_approve_reject_revoke_emails(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $clientAdmin = $this->makeAdmin($client);

        // Request → client admin gets an email.
        ConsultantService::requestLink($consultant, $client->ntn);
        Mail::assertQueued(\App\Mail\ConsultantNotificationMail::class, fn ($m) => $m->hasTo($clientAdmin->email));

        // Approve → consultant gets an email.
        $link = ConsultantClientLink::where('consultant_user_id', $consultant->id)->where('company_id', $client->id)->first();
        ConsultantService::approveLink($link, $clientAdmin);
        Mail::assertQueued(\App\Mail\ConsultantNotificationMail::class, fn ($m) => $m->hasTo($consultant->email));

        // Client revoke → consultant gets an email (2nd one to consultant).
        ConsultantService::revokeLink($link->fresh(), 'client', $clientAdmin->id);
        $toConsultant = 0;
        Mail::assertQueued(\App\Mail\ConsultantNotificationMail::class, function ($m) use ($consultant, &$toConsultant) {
            if ($m->hasTo($consultant->email)) {
                $toConsultant++;
            }
            return true;
        });
        $this->assertSame(2, $toConsultant);
    }

    public function test_consultant_self_cancel_sends_no_email_to_consultant(): void
    {
        [$consultant] = $this->makeConsultant();
        $client = $this->makeCompany();
        $this->makeAdmin($client);

        $link = ConsultantClientLink::create([
            'consultant_user_id' => $consultant->id,
            'company_id' => $client->id,
            'status' => 'pending',
        ]);

        ConsultantService::revokeLink($link, 'consultant', $consultant->id);

        Mail::assertNotQueued(\App\Mail\ConsultantNotificationMail::class, fn ($m) => $m->hasTo($consultant->email));
    }

    public function test_commission_recorded_queues_email_to_consultant(): void
    {
        [$consultant, $profile] = $this->makeConsultant();
        $client = $this->makeCompany(['referred_by_user_id' => $consultant->id]);
        $plan = PricingPlan::create(['name' => 'DI Pro', 'product_type' => 'di', 'price' => 1000, 'is_trial' => false]);

        $sub = \App\Models\Subscription::create([
            'company_id' => $client->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'final_price' => 1000,
            'active' => true,
        ]);

        $commission = ConsultantService::recordCommissionForSubscription($sub);
        $this->assertNotNull($commission);
        Mail::assertQueued(\App\Mail\ConsultantNotificationMail::class, fn ($m) => $m->hasTo($consultant->email));
    }
}
