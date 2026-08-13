<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Services\WhatsAppBusinessApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #634 — Agent-offline owner alert via WhatsApp (TaxNest-central number).
 *
 * pos:agent-offline-alerts must try WhatsApp FIRST when the central number is
 * configured (company mobile normalized via PkPhone), and fall back to email
 * when WhatsApp is unconfigured, the number is unroutable, or the API send
 * fails. One-per-outage dedup (agent_offline_notified_at) is unchanged.
 * Minimal-schema pattern (AdminAgentHealthPanelTest).
 */
class AgentOfflineWhatsAppAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->timestamp('agent_offline_notified_at')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    protected function makeOfflineCompany(array $attrs = []): Company
    {
        $company = Company::forceCreate(array_merge([
            'name' => 'Frost & Brew',
            'product_type' => 'pos',
            'company_status' => 'active',
            'is_internal_account' => false,
            'email' => 'owner@example.com',
            'mobile' => '0300-1234567',
            'agent_enabled' => true,
            'agent_last_seen' => now()->subHours(5),
            'pos_printer_settings' => ['silent_print_enabled' => true],
        ], $attrs));

        $company->subscriptions()->create(['active' => true]);

        return $company;
    }

    protected function configureCentralWa(): void
    {
        SystemSetting::set(WhatsAppBusinessApi::CENTRAL_ENABLED_KEY, '1');
        SystemSetting::set(WhatsAppBusinessApi::CENTRAL_PHONE_ID_KEY, '111222333444555');
        SystemSetting::set(WhatsAppBusinessApi::CENTRAL_TOKEN_KEY, Crypt::encryptString('test-token'));
    }

    public function test_whatsapp_sent_first_and_email_skipped(): void
    {
        Mail::fake();
        Http::fake([
            WhatsAppBusinessApi::GRAPH_BASE . '/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);
        $this->configureCentralWa();
        $company = $this->makeOfflineCompany();

        $this->artisan('pos:agent-offline-alerts')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === WhatsAppBusinessApi::GRAPH_BASE . '/111222333444555/messages'
                && $data['to'] === '923001234567'
                && $data['template']['name'] === WhatsAppBusinessApi::DEFAULT_OFFLINE_TEMPLATE
                && $data['template']['components'][0]['parameters'][0]['text'] === 'Frost & Brew';
        });
        Mail::assertNothingSent();

        $company->refresh();
        $this->assertNotNull($company->agent_offline_notified_at);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_email_fallback_when_whatsapp_send_fails(): void
    {
        Mail::fake();
        Http::fake([
            WhatsAppBusinessApi::GRAPH_BASE . '/*' => Http::response(['error' => ['message' => 'Template not found']], 400),
        ]);
        $this->configureCentralWa();
        $company = $this->makeOfflineCompany();

        $this->artisan('pos:agent-offline-alerts')->assertExitCode(0);

        Mail::assertSent(\App\Mail\TrialReminderMail::class, 1);
        $company->refresh();
        $this->assertNotNull($company->agent_offline_notified_at);
    }

    public function test_email_only_when_central_wa_not_configured(): void
    {
        Mail::fake();
        Http::fake();
        $company = $this->makeOfflineCompany();

        $this->artisan('pos:agent-offline-alerts')->assertExitCode(0);

        Http::assertNothingSent();
        Mail::assertSent(\App\Mail\TrialReminderMail::class, 1);
        $this->assertNotNull($company->refresh()->agent_offline_notified_at);
    }

    public function test_email_fallback_when_mobile_not_routable(): void
    {
        Mail::fake();
        Http::fake();
        $this->configureCentralWa();
        $company = $this->makeOfflineCompany(['mobile' => 'abc', 'phone' => null]);

        $this->artisan('pos:agent-offline-alerts')->assertExitCode(0);

        Http::assertNothingSent();
        Mail::assertSent(\App\Mail\TrialReminderMail::class, 1);
        $this->assertNotNull($company->refresh()->agent_offline_notified_at);
    }

    public function test_one_alert_per_outage_dedup_still_holds(): void
    {
        Mail::fake();
        Http::fake([
            WhatsAppBusinessApi::GRAPH_BASE . '/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);
        $this->configureCentralWa();
        $this->makeOfflineCompany();

        $this->artisan('pos:agent-offline-alerts')->assertExitCode(0);
        $this->artisan('pos:agent-offline-alerts')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('notifications', 1);
    }
}
