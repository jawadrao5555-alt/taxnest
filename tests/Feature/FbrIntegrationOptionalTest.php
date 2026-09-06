<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\User;
use App\Services\FbrIntegrationDecisionService;
use App\Support\QrImage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Optional FBR integration (Sep 2026) — FBR reporting is a CHOICE, not a
 * requirement for an FBR POS shop.
 *
 * Locked here:
 *  - a freshly registered FBR shop starts with reporting OFF;
 *  - the one-time decision card is owner-only (company_admin), never for
 *    confined roles, pending companies, read-only impersonation, a snoozed
 *    session, or a shop that already decided (explicitly or by doing);
 *  - the reporting toggle REFUSES to turn ON while the integration is not
 *    configured (422, server truth in 'enabled', what is missing named);
 *  - completing the setup for a shop that chose "connect" turns reporting ON
 *    by itself (settings save / agent key);
 *  - a reporting-OFF bill prints as a Sale Receipt with a LOCAL simple QR and
 *    no FBR / retry wording, and never an external QR host;
 *  - "Without FBR" converts ONLY config-only failures (never anything that
 *    reached FBR), audited and idempotent;
 *  - the FAILED counters are zero for a reporting-OFF shop.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrIntegrationOptionalTest.php
 */
class FbrIntegrationOptionalTest extends TestCase
{
    private const POS_ID = '812345';
    /** Raw IMS tokens are UUID-shaped; FbrService::looksLikeRawFbrToken accepts them as-is. */
    private const TOKEN = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        QrImage::fake();
        Mail::fake();
        Notification::fake();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('owner_name')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('province')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('website')->nullable();
            $t->string('status')->default('approved');
            $t->string('company_status')->default('approved');
            $t->string('product_type')->nullable();
            $t->string('pos_type')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('restaurant_mode')->default(false);
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->string('pra_environment')->nullable();
            $t->string('pos_integration_mode')->nullable();
            $t->unsignedBigInteger('requested_plan_id')->nullable();
            $t->boolean('fbr_pos_enabled')->default(true);
            $t->string('fbr_pos_environment')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(true);
            $t->string('fbr_connection_mode')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->string('agent_api_key')->nullable();
            $t->boolean('agent_submits_pra')->nullable();
            $t->text('fbr_pos_token')->nullable();
            $t->string('fbr_pos_id')->nullable();
            $t->string('fbr_integration_decision', 20)->nullable();
            $t->timestamp('fbr_integration_decided_at')->nullable();
            $t->unsignedBigInteger('fbr_integration_decided_by')->nullable();
            $t->decimal('standard_tax_rate', 8, 2)->nullable();
            $t->unsignedBigInteger('franchise_id')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->string('print_paper_size')->nullable();
            $t->string('order_match_style')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('username')->nullable();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->decimal('price', 12, 2)->default(0);
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('offline_enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->string('billing_cycle')->nullable();
            $t->decimal('discount_percent', 8, 2)->default(0);
            $t->decimal('final_price', 12, 2)->default(0);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->boolean('active')->default(true);
            $t->string('override_type')->nullable();
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->string('override_reason')->nullable();
            $t->unsignedBigInteger('override_by')->nullable();
            $t->timestamps();
        });
        Schema::create('registered_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('credential_type');
            $t->string('credential_value', 191);
            $t->string('product_type')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['credential_type', 'credential_value']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->boolean('archived')->default(false);
            $t->timestamps();
        });
        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('fbr_response_code')->nullable();
            $t->text('fbr_response')->nullable();
            $t->text('fbr_error_message')->nullable();
            $t->string('fbr_submission_hash')->nullable();
            $t->unsignedTinyInteger('fbr_auto_retry_count')->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedSmallInteger('token_no')->nullable();
            $t->string('order_code', 10)->nullable();
            $t->timestamps();
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
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('sha256_hash')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        QrImage::resetFake();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function company(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Optional FBR Mart', 'product_type' => 'fbrpos',
            'status' => 'approved', 'company_status' => 'approved',
            'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => false,
            'ntn' => '1234567',
        ], $attrs));
    }

    private function user(Company $c, string $role = 'company_admin', string $posRole = 'pos_admin'): User
    {
        static $n = 0;
        $n++;
        return User::create([
            'name' => "U{$n}", 'email' => "u{$n}@optfbr.test", 'password' => Hash::make('Secret@12345'),
            'company_id' => $c->id, 'role' => $role, 'pos_role' => $posRole, 'is_active' => true,
        ]);
    }

    private function bill(Company $c, array $attrs = []): FbrPosTransaction
    {
        static $i = 0;
        $i++;
        return FbrPosTransaction::create(array_merge([
            'company_id' => $c->id, 'invoice_number' => 'P' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'invoice_mode' => 'fbr', 'fbr_status' => null, 'status' => 'completed',
            'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500, 'payment_method' => 'cash',
        ], $attrs));
    }

    private function log(FbrPosTransaction $t, array $attrs = []): void
    {
        DB::table('fbr_pos_logs')->insert(array_merge([
            'company_id' => $t->company_id, 'transaction_id' => $t->id,
            'status' => 'failed', 'response_code' => null, 'response_payload' => null,
            'error_message' => 'FBR POS ID or token not configured',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function configure(Company $c): Company
    {
        $c->forceFill(['fbr_pos_id' => self::POS_ID, 'fbr_pos_token' => self::TOKEN])->save();
        return $c->fresh();
    }

    private function svc(): FbrIntegrationDecisionService
    {
        return app(FbrIntegrationDecisionService::class);
    }

    // ── 1. safe default ──────────────────────────────────────────────────

    public function test_new_fbr_shop_registers_with_reporting_off_and_undecided(): void
    {
        $resp = $this->post('/fbr-pos/register', [
            'company_name' => 'Fresh Medical Store', 'company_ntn' => '7654321',
            'name' => 'Owner', 'email' => 'owner@fresh.test',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            'pos_type' => 'retail',
        ]);
        $resp->assertSessionHasNoErrors();

        $c = Company::where('name', 'Fresh Medical Store')->firstOrFail();
        $this->assertFalse((bool) $c->fbr_reporting_enabled, 'a new FBR shop must NOT start with reporting ON');
        $this->assertNull($c->fbrIntegrationDecision());
        $this->assertTrue($c->fbrIntegrationUndecided(), 'brand-new shop is the decision-card audience');
        $this->assertSame('off', $c->fbrIntegrationState());
    }

    // ── 2. decision-card audience ────────────────────────────────────────

    public function test_decision_card_audience_rules(): void
    {
        $c = $this->company();
        $admin = $this->user($c);
        $svc = $this->svc();

        $this->assertTrue($svc->shouldShowDecisionCard($admin, $c, true), 'undecided admin on dashboard sees it');
        $this->assertFalse($svc->shouldShowDecisionCard($admin, $c, false), 'never off the dashboard / sale screen');

        foreach ([['company_user', 'pos_cashier'], ['company_user', 'waiter'], ['company_user', 'kitchen'], ['company_user', 'pos_manager']] as [$role, $posRole]) {
            $this->assertFalse($svc->shouldShowDecisionCard($this->user($c, $role, $posRole), $c, true), "{$posRole} never sees the card");
        }

        $pending = $this->company(['status' => 'pending', 'company_status' => 'pending']);
        $this->assertFalse($svc->shouldShowDecisionCard($this->user($pending), $pending, true), 'pending companies never see it');

        session(['impersonation' => ['readonly' => true]]);
        $this->assertFalse($svc->shouldShowDecisionCard($admin, $c, true), 'read-only view-as never sees it');
        session()->forget('impersonation');

        session([FbrIntegrationDecisionService::SESSION_LATER => true]);
        $this->assertFalse($svc->shouldShowDecisionCard($admin, $c, true), '"later" snoozes for the session (no loop)');
        session()->forget(FbrIntegrationDecisionService::SESSION_LATER);

        // Decided by doing: ON + configured, or ON + a bill FBR already accepted.
        $configured = $this->configure($this->company(['fbr_reporting_enabled' => true]));
        $this->assertFalse($configured->fbrIntegrationUndecided(), 'configured ON shop is not undecided');

        $accepted = $this->company(['fbr_reporting_enabled' => true]);
        $this->bill($accepted, ['fbr_status' => 'submitted', 'fbr_invoice_number' => '7000000009999127']);
        $this->assertFalse($accepted->fbrIntegrationUndecided(), 'a shop FBR already accepted a bill from is not undecided');

        // Legacy shop: ON at registration, never configured, nothing accepted → undecided.
        $legacy = $this->company(['fbr_reporting_enabled' => true]);
        $this->bill($legacy, ['fbr_status' => 'config_error']);
        $this->assertTrue($legacy->fbrIntegrationUndecided(), 'legacy ON-but-never-set-up shop IS undecided');
        $this->assertSame('setup_pending', $legacy->fbrIntegrationState());

        // Explicit choice ends it either way.
        $svc->chooseWithoutFbr($c, $admin->id);
        $this->assertFalse($c->fresh()->fbrIntegrationUndecided());
        $this->assertFalse($svc->shouldShowDecisionCard($admin, $c->fresh(), true));
    }

    public function test_decision_endpoint_is_owner_only_and_records_the_choice(): void
    {
        $c = $this->company();
        $cashier = $this->user($c, 'company_user', 'pos_cashier');
        $this->actingAs($cashier, 'fbrpos')
            ->postJson('/fbr-pos/integration/decision', ['choice' => 'without_fbr'])
            ->assertStatus(403);
        $this->assertNull($c->fresh()->fbrIntegrationDecision(), 'a cashier cannot decide for the shop');

        $admin = $this->user($c);
        $this->actingAs($admin, 'fbrpos')
            ->postJson('/fbr-pos/integration/decision', ['choice' => 'later'])
            ->assertOk()->assertJson(['success' => true, 'choice' => 'later']);
        $this->assertNull($c->fresh()->fbrIntegrationDecision(), '"later" records nothing');
        $this->assertTrue((bool) session(FbrIntegrationDecisionService::SESSION_LATER));

        $this->actingAs($admin, 'fbrpos')
            ->postJson('/fbr-pos/integration/decision', ['choice' => 'without_fbr'])
            ->assertOk()->assertJson(['success' => true, 'choice' => 'without_fbr', 'enabled' => false]);
        $c->refresh();
        $this->assertSame(Company::FBR_DECISION_WITHOUT, $c->fbrIntegrationDecision());
        $this->assertFalse((bool) $c->fbr_reporting_enabled);
        $this->assertSame($admin->id, (int) $c->fbr_integration_decided_by);
        $this->assertNotNull($c->fbr_integration_decided_at);

        // Connect (unconfigured) → decision recorded, reporting stays OFF, sent to settings.
        $this->actingAs($admin, 'fbrpos')
            ->postJson('/fbr-pos/integration/decision', ['choice' => 'connect'])
            ->assertOk()->assertJson(['success' => true, 'choice' => 'connect', 'enabled' => false, 'redirect' => '/fbr-pos/settings']);
        $c->refresh();
        $this->assertSame(Company::FBR_DECISION_CONNECT, $c->fbrIntegrationDecision());
        $this->assertFalse((bool) $c->fbr_reporting_enabled, 'connect without details cannot turn reporting ON');
        $this->assertSame('setup_pending', $c->fbrIntegrationState());

        // Connect (already configured) → ON right away.
        $c2 = $this->configure($this->company());
        $this->actingAs($this->user($c2), 'fbrpos')
            ->postJson('/fbr-pos/integration/decision', ['choice' => 'connect'])
            ->assertOk()->assertJson(['enabled' => true]);
        $this->assertTrue((bool) $c2->fresh()->fbr_reporting_enabled);
    }

    // ── 3. ON requires configuration ─────────────────────────────────────

    public function test_toggle_refuses_to_turn_on_while_unconfigured(): void
    {
        $c = $this->company();
        $admin = $this->user($c);

        $resp = $this->actingAs($admin, 'fbrpos')->postJson('/fbr-pos/api/toggle-fbr-reporting');
        $resp->assertStatus(422)
            ->assertJson(['success' => false, 'enabled' => false, 'settings_url' => '/fbr-pos/settings'])
            ->assertJsonPath('missing', ['pos_id', 'token']);
        $this->assertStringContainsString(__('pos.fbr_missing_pos_id'), $resp->json('message'));
        $this->assertStringContainsString(__('pos.fbr_missing_token'), $resp->json('message'));
        $this->assertFalse((bool) $c->fresh()->fbr_reporting_enabled, 'refusal must not flip the flag');

        // Fiscal-device mode: POS ID present but agent not paired → 'agent' is what is missing.
        $c->forceFill(['fbr_pos_id' => self::POS_ID, 'fbr_connection_mode' => 'fiscal_device'])->save();
        $this->actingAs($admin, 'fbrpos')->postJson('/fbr-pos/api/toggle-fbr-reporting')
            ->assertStatus(422)->assertJsonPath('missing', ['agent']);

        // Configured → ON works, and turning ON is itself the "connect" decision.
        $c->forceFill(['fbr_connection_mode' => 'cloud', 'fbr_pos_token' => self::TOKEN])->save();
        $this->actingAs($admin, 'fbrpos')->postJson('/fbr-pos/api/toggle-fbr-reporting')
            ->assertOk()->assertJson(['success' => true, 'enabled' => true]);
        $c->refresh();
        $this->assertTrue((bool) $c->fbr_reporting_enabled);
        $this->assertSame(Company::FBR_DECISION_CONNECT, $c->fbrIntegrationDecision());
        $this->assertSame('on', $c->fbrIntegrationState());

        // OFF is always allowed (and keeps the connect decision — a pause is not "without FBR").
        $this->actingAs($admin, 'fbrpos')->postJson('/fbr-pos/api/toggle-fbr-reporting')
            ->assertOk()->assertJson(['success' => true, 'enabled' => false]);
        $c->refresh();
        $this->assertFalse((bool) $c->fbr_reporting_enabled);
        $this->assertSame(Company::FBR_DECISION_CONNECT, $c->fbrIntegrationDecision());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'fbr_reporting_changed')->count());
    }

    public function test_settings_save_refuses_on_and_auto_enables_after_setup_for_connect_shops(): void
    {
        $c = $this->company();
        $admin = $this->user($c);

        // integration_toggle=on while unconfigured → refused, stays OFF.
        $this->actingAs($admin, 'fbrpos')->post('/fbr-pos/settings', ['integration_toggle' => 'on']);
        $this->assertFalse((bool) $c->fresh()->fbr_reporting_enabled, 'settings page cannot force ON unconfigured');

        // Shop chose "connect" earlier; saving POS ID + token completes the setup → auto ON.
        $this->svc()->chooseConnect($c, $admin->id);
        $this->assertFalse((bool) $c->fresh()->fbr_reporting_enabled);

        $this->actingAs($admin, 'fbrpos')->post('/fbr-pos/settings', [
            'fbr_pos_id' => self::POS_ID, 'fbr_pos_token' => self::TOKEN,
            'fbr_pos_environment' => 'sandbox', 'fbr_connection_mode' => 'cloud',
        ]);
        $c->refresh();
        $this->assertTrue($c->fbrPosIntegrationConfigured(), 'settings save stored the credentials');
        $this->assertTrue((bool) $c->fbr_reporting_enabled, 'completing the setup turns reporting ON by itself');
        $this->assertSame('on', $c->fbrIntegrationState());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fbr_reporting_changed')->count());

        // A shop that chose "without FBR" is NEVER auto-enabled by a settings save.
        $c2 = $this->company();
        $this->svc()->chooseWithoutFbr($c2, null);
        $this->configure($c2);
        $this->assertFalse($this->svc()->maybeAutoEnableReporting($c2, null));
        $this->assertFalse((bool) $c2->fresh()->fbr_reporting_enabled);
    }

    public function test_agent_key_pairing_auto_enables_a_connect_shop_in_fiscal_device_mode(): void
    {
        $plan = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'fbrpos', 'price' => 1999,
            'offline_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c = $this->company(['fbr_pos_id' => self::POS_ID, 'fbr_connection_mode' => 'fiscal_device']);
        DB::table('subscriptions')->insert([
            'company_id' => $c->id, 'pricing_plan_id' => $plan, 'active' => true,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->user($c);
        $this->svc()->chooseConnect($c, $admin->id);
        $this->assertSame(['agent'], $c->fresh()->fbrIntegrationMissing());

        \App\Services\PosFeatureService::flushGateCaches();
        $this->actingAs($admin, 'fbrpos')->post('/fbr-pos/agent/generate');
        $c->refresh();
        $this->assertNotEmpty($c->agent_api_key, 'key must land regardless of the auto-ON hook');
        $this->assertTrue((bool) $c->agent_enabled);
        $this->assertTrue((bool) $c->fbr_reporting_enabled, 'pairing the agent was the last missing piece → ON');
    }

    // ── 4. simple-QR receipt for non-integrated bills ────────────────────

    public function test_reporting_off_bill_prints_sale_receipt_with_local_simple_qr(): void
    {
        $c = $this->company(['print_paper_size' => 'thermal', 'invoice_display_prefs' => ['pos_style' => ['bold' => false]]]);
        $txn = $this->bill($c, ['fbr_status' => null, 'total_amount' => 750, 'subtotal' => 750]);
        $item = new FbrPosTransactionItem(['item_name' => 'Panadol', 'uom' => 'U', 'quantity' => 1, 'unit_price' => 750, 'subtotal' => 750]);
        $item->id = 1;
        $txn->setRelation('items', collect([$item]));
        $txn->setRelation('company', $c);
        $txn->setRelation('creator', null);

        foreach (['thermal', 'thermal58'] as $paper) {
            $c->print_paper_size = $paper;
            QrImage::resetFake();
            QrImage::fake();
            $html = view('fbr-pos.receipt', ['transaction' => $txn, 'company' => $c])->render();

            $this->assertStringContainsString(__('pos.receipt_sale_receipt'), $html, "SALE RECEIPT badge ({$paper})");
            $this->assertStringContainsString('data:image/png;base64,', $html, "QR rendered locally ({$paper})");
            $this->assertStringNotContainsString('qrserver', $html, "no external QR host ({$paper})");
            $this->assertStringNotContainsString(__('pos.rcpt_will_retry'), $html, "no retry hint ({$paper})");
            $this->assertStringNotContainsString(__('pos.rcpt_fbr_pending'), $html, "no FBR pending badge ({$paper})");
            $this->assertStringNotContainsString(__('pos.rcpt_fbr_integrated'), $html, "no INTEGRATED WITH FBR footer ({$paper})");
            $this->assertStringNotContainsString('FBR:', $html, "no fiscal line ({$paper})");

            $payloads = QrImage::recorded();
            $this->assertCount(1, $payloads, "exactly one QR ({$paper})");
            $qr = json_decode($payloads[0], true);
            $this->assertIsArray($qr, 'simple QR carries a details payload');
            $this->assertSame($txn->invoice_number, $qr['inv']);
            $this->assertSame($c->name, $qr['business']);
            $this->assertSame('1234567', $qr['ntn']);
            $this->assertEquals(750, $qr['total']);
        }

        // Fiscalised bills are untouched: bare FBR number in the QR, FBR badge kept.
        $fiscal = $this->bill($c, ['fbr_status' => 'submitted', 'fbr_invoice_number' => '7000000009999127']);
        $fiscal->setRelation('items', collect([$item]));
        $fiscal->setRelation('company', $c);
        $fiscal->setRelation('creator', null);
        QrImage::resetFake();
        QrImage::fake();
        $html = view('fbr-pos.receipt', ['transaction' => $fiscal, 'company' => $c])->render();
        $this->assertSame(['7000000009999127'], QrImage::recorded());
        $this->assertStringContainsString('FBR: 7000000009999127', $html);
    }

    public function test_simple_qr_payload_omits_ntn_when_absent_and_marks_converted_bills_non_integrated(): void
    {
        $c = $this->company(['ntn' => null]);
        $txn = $this->bill($c);
        $qr = json_decode($txn->simpleQrPayload($c), true);
        $this->assertArrayNotHasKey('ntn', $qr);
        $this->assertTrue($txn->isNonIntegratedBill());
        $this->assertTrue($this->bill($c, ['fbr_status' => 'local', 'invoice_mode' => 'fbr'])->isNonIntegratedBill(), 'legacy fbr/local = plain bill');
        $this->assertFalse($this->bill($c, ['fbr_status' => 'local', 'invoice_mode' => 'local'])->isNonIntegratedBill(), 'a deliberate provisional is not a final sale receipt');
        foreach (['submitted', 'pending', 'failed', 'config_error', 'offline'] as $st) {
            $this->assertFalse($this->bill($c, ['fbr_status' => $st])->isNonIntegratedBill(), "{$st} keeps FBR treatment");
        }
    }

    // ── 5. clean-up of config-only failures ──────────────────────────────

    public function test_without_fbr_converts_only_config_only_failures(): void
    {
        $c = $this->company(['fbr_reporting_enabled' => true]);
        $admin = $this->user($c);

        $convertA = $this->bill($c, ['fbr_status' => 'config_error']);                       // no log at all
        $convertB = $this->bill($c, ['fbr_status' => 'failed']);                             // failed, no log
        $convertC = $this->bill($c, ['fbr_status' => 'failed']);                             // failed, config-only log
        $this->log($convertC);
        $convertD = $this->bill($c, ['fbr_status' => 'config_error', 'fbr_response_code' => 'CFG', 'fbr_auto_retry_count' => 3]);

        $keepSubmitted = $this->bill($c, ['fbr_status' => 'submitted', 'fbr_invoice_number' => '7000000000000001']);
        $keepOffline   = $this->bill($c, ['fbr_status' => 'offline']);
        $keepPending   = $this->bill($c, ['fbr_status' => 'pending']);
        $keepPendingLog = $this->bill($c, ['fbr_status' => 'pending']);
        $this->log($keepPendingLog, ['status' => 'pending', 'error_message' => null]);
        $keepFailedReal = $this->bill($c, ['fbr_status' => 'failed']);                       // FBR actually answered
        $this->log($keepFailedReal, ['response_code' => '400', 'response_payload' => '{"Code":"400"}', 'error_message' => 'Invalid HS code']);
        $keepFailedFiscal = $this->bill($c, ['fbr_status' => 'failed', 'fbr_invoice_number' => '7000000000000002']);
        $keepProvisional = $this->bill($c, ['fbr_status' => 'local', 'invoice_mode' => 'local']);
        $keepFailedTimeout = $this->bill($c, ['fbr_status' => 'failed']);                    // reached the wire, no reply
        $this->log($keepFailedTimeout, ['error_message' => 'cURL error 28: timed out']);

        $other = $this->company(['name' => 'Other Shop', 'fbr_reporting_enabled' => true]);
        $otherBill = $this->bill($other, ['fbr_status' => 'config_error']);

        $r = $this->svc()->chooseWithoutFbr($c, $admin->id);
        $this->assertSame(4, $r['converted']);

        foreach ([$convertA, $convertB, $convertC, $convertD] as $b) {
            $b->refresh();
            $this->assertNull($b->fbr_status, "bill {$b->invoice_number} converted to a plain bill");
            $this->assertTrue($b->isNonIntegratedBill());
        }
        $this->assertNull($convertD->fresh()->fbr_response_code);
        $this->assertSame(0, (int) $convertD->fresh()->fbr_auto_retry_count);

        $this->assertSame('submitted', $keepSubmitted->fresh()->fbr_status);
        $this->assertSame('offline', $keepOffline->fresh()->fbr_status);
        $this->assertSame('pending', $keepPending->fresh()->fbr_status);
        $this->assertSame('pending', $keepPendingLog->fresh()->fbr_status);
        $this->assertSame('failed', $keepFailedReal->fresh()->fbr_status, 'a bill FBR rejected is never rewritten');
        $this->assertSame('failed', $keepFailedFiscal->fresh()->fbr_status);
        $this->assertSame('local', $keepProvisional->fresh()->fbr_status);
        $this->assertSame('failed', $keepFailedTimeout->fresh()->fbr_status, 'a transport failure is a real attempt');
        $this->assertSame('config_error', $otherBill->fresh()->fbr_status, 'never crosses companies');

        $c->refresh();
        $this->assertFalse((bool) $c->fbr_reporting_enabled);
        $this->assertSame(Company::FBR_DECISION_WITHOUT, $c->fbrIntegrationDecision());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fbr_config_only_failures_converted')->where('company_id', $c->id)->count());

        // Idempotent: a rerun converts nothing and writes no second conversion audit row.
        $this->assertSame(0, $this->svc()->convertConfigOnlyFailures($c->fresh(), $admin->id));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'fbr_config_only_failures_converted')->where('company_id', $c->id)->count());
    }

    public function test_support_command_converts_per_company_and_dry_run_touches_nothing(): void
    {
        $c = $this->company(['fbr_reporting_enabled' => true]);
        $a = $this->bill($c, ['fbr_status' => 'config_error']);
        $real = $this->bill($c, ['fbr_status' => 'failed']);
        $this->log($real, ['response_code' => '401', 'response_payload' => '{}', 'error_message' => 'Unauthorized']);

        $this->artisan('fbrpos:convert-config-failures', ['company' => $c->id, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame('config_error', $a->fresh()->fbr_status, 'dry run changes nothing');

        $this->artisan('fbrpos:convert-config-failures', ['company' => $c->id])->assertExitCode(0);
        $this->assertNull($a->fresh()->fbr_status);
        $this->assertSame('failed', $real->fresh()->fbr_status);

        $this->artisan('fbrpos:convert-config-failures', ['company' => $c->id])->assertExitCode(0);
    }

    // ── 6. counters are zero for a reporting-OFF shop ────────────────────

    public function test_failed_bill_counters_are_zero_for_a_reporting_off_shop(): void
    {
        $c = $this->company(['fbr_reporting_enabled' => false]);
        $admin = $this->user($c);
        // Stale rows that should never light the FAILED pill while the shop runs without FBR.
        $this->bill($c, ['fbr_status' => 'failed']);
        $this->bill($c, ['fbr_status' => 'config_error']);

        $this->actingAs($admin, 'fbrpos')->getJson('/fbr-pos/api/failed-bills')
            ->assertOk()
            ->assertJson(['reporting_off' => true]);
        $json = $this->actingAs($admin, 'fbrpos')->getJson('/fbr-pos/api/failed-bills')->json();
        foreach ($json as $k => $v) {
            if (is_int($v) && $k !== 'reporting_off') {
                $this->assertSame(0, $v, "counter {$k} must be zero for a reporting-OFF shop");
            }
            if (is_array($v)) {
                $this->assertSame([], $v, "list {$k} must be empty for a reporting-OFF shop");
            }
        }

        // Retry-all is refused while reporting is OFF (nothing may enter the FBR queue).
        $this->actingAs($admin, 'fbrpos')->post('/fbr-pos/fail-queue/retry-all')->assertRedirect();
        $this->assertSame(2, FbrPosTransaction::where('company_id', $c->id)->whereIn('fbr_status', ['failed', 'config_error'])->count());

        // Same shop switched ON (configured) → the counters see the rows again.
        $this->configure($c);
        $this->svc()->setReporting($c->fresh(), true, $admin->id);
        $on = $this->actingAs($admin, 'fbrpos')->getJson('/fbr-pos/api/failed-bills')->assertOk()->json();
        $this->assertArrayNotHasKey('reporting_off', $on);
        $this->assertGreaterThan(0, max(array_filter($on, 'is_int') ?: [0]), 'counters live again once reporting is ON');
    }
}
