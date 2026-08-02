<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WHATSAPP BUSINESS API — Phase 2 direct send (Task: bina tap ke)
 *
 *  1. Settings save encrypts the token (TEXT column) and blank token keeps
 *     the stored one; enabling without credentials is rejected loudly.
 *  2. send-info exposes wa_api_configured (drives the modal's mode choice).
 *  3. mode=api → Graph API template send, delivery row carries the wamid,
 *     NO wa.me URL in the response.
 *  4. Graph failure → failed delivery row + explicit 502 (no silent wa.me
 *     fallback).
 *  5. mode=api while unconfigured → 422, no delivery row.
 *  6. Default (link) mode is untouched — wa.me URL still returned.
 *  7. Webhook: verify handshake honors the per-company verify token; status
 *     posts move sent→delivered→read forward-only; failed captures the error;
 *     cross-company wamids never update.
 */
class WhatsAppBusinessApiSendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('onboarding_completed')->default(true);
            $table->string('product_type')->nullable();
            $table->boolean('wa_api_enabled')->default(false);
            $table->string('wa_phone_number_id')->nullable();
            $table->text('wa_api_token')->nullable();
            $table->string('wa_template_name')->nullable();
            $table->boolean('wa_attach_pdf')->default(true);
            $table->string('wa_webhook_verify_token')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('internal_invoice_number')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('status')->default('draft');
            $table->string('buyer_name')->nullable();
            $table->string('buyer_ntn')->nullable();
            $table->string('buyer_cnic')->nullable();
            $table->text('buyer_address')->nullable();
            $table->string('buyer_registration_type')->nullable();
            $table->string('destination_province')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('share_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('description')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->text('address')->nullable();
            $table->string('province')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('registration_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('channel', 20);
            $table->string('recipient', 255);
            $table->string('status', 20)->default('sent');
            $table->text('error')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('changes_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Mail::fake();
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Sender Traders',
            'email' => 'owner@sender.test',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'di',
        ], $attrs));
    }

    private function configureWa(Company $company, array $overrides = []): void
    {
        $company->forceFill(array_merge([
            'wa_api_enabled' => true,
            'wa_phone_number_id' => '111222333444555',
            'wa_api_token' => Crypt::encryptString('test-wa-token'),
            'wa_template_name' => 'invoice_notification',
            'wa_attach_pdf' => true,
            'wa_webhook_verify_token' => 'verify-me-123',
        ], $overrides))->save();
    }

    private function makeUser(Company $company, string $role = 'company_admin'): User
    {
        return User::create([
            'name' => 'DI User ' . uniqid(),
            'email' => uniqid() . '@sender.test',
            'password' => Hash::make('Secret@123'),
            'company_id' => $company->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeInvoice(Company $company, array $attrs = []): int
    {
        return DB::table('invoices')->insertGetId(array_merge([
            'company_id' => $company->id,
            'invoice_number' => 'INV-' . uniqid(),
            'status' => 'submitted',
            'buyer_name' => 'Buyer Steel Works',
            'buyer_ntn' => '7654321',
            'total_amount' => 46500.00,
            'share_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    // ─── 1. settings save ────────────────────────────────────────────────

    public function test_settings_save_encrypts_token_and_blank_keeps_existing(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);

        $this->actingAs($user)->put('/company/whatsapp-settings', [
            'wa_api_enabled' => 1,
            'wa_phone_number_id' => '999888777',
            'wa_api_token' => 'super-secret-token',
            'wa_template_name' => 'invoice_notification',
            'wa_attach_pdf' => 1,
            'wa_webhook_verify_token' => 'vt-1',
        ])->assertRedirect('/company/whatsapp-settings')->assertSessionHas('success');

        $company->refresh();
        $this->assertTrue((bool) $company->wa_api_enabled);
        $this->assertNotSame('super-secret-token', $company->wa_api_token, 'Token must be stored encrypted');
        $this->assertSame('super-secret-token', Crypt::decryptString($company->wa_api_token));

        // Blank token on re-save keeps the old one
        $this->actingAs($user)->put('/company/whatsapp-settings', [
            'wa_api_enabled' => 1,
            'wa_phone_number_id' => '999888777',
            'wa_api_token' => '',
            'wa_attach_pdf' => 0,
        ])->assertSessionHas('success');

        $company->refresh();
        $this->assertSame('super-secret-token', Crypt::decryptString($company->wa_api_token));
        $this->assertFalse((bool) $company->wa_attach_pdf);
    }

    public function test_enabling_without_credentials_is_rejected_loudly(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);

        $this->actingAs($user)->put('/company/whatsapp-settings', [
            'wa_api_enabled' => 1,
            'wa_phone_number_id' => '',
            'wa_api_token' => '',
        ])->assertSessionHas('error');

        $this->assertFalse((bool) $company->refresh()->wa_api_enabled);
    }

    // ─── 2. send-info flag ───────────────────────────────────────────────

    public function test_send_info_exposes_wa_api_configured_flag(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        $this->actingAs($user)->getJson("/invoice/{$invoiceId}/send-info")
            ->assertOk()->assertJsonPath('wa_api_configured', false);

        $this->configureWa($company);

        $this->actingAs($user)->getJson("/invoice/{$invoiceId}/send-info")
            ->assertOk()->assertJsonPath('wa_api_configured', true);
    }

    // ─── 3-4. direct send ────────────────────────────────────────────────

    public function test_api_mode_sends_template_and_stores_wamid(): void
    {
        $company = $this->makeCompany();
        $this->configureWa($company);
        $user = $this->makeUser($company, 'employee');
        $invoiceId = $this->makeInvoice($company);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST123']],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-whatsapp", [
            'phone' => '0300-1234567',
            'mode' => 'api',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonMissingPath('wa_url')
            ->assertJsonPath('delivery.status', 'sent');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($request->url(), '/111222333444555/messages')
                && $body['type'] === 'template'
                && $body['template']['name'] === 'invoice_notification'
                && $body['to'] === '923001234567';
        });

        $delivery = DB::table('invoice_deliveries')->where('invoice_id', $invoiceId)->first();
        $this->assertSame('wamid.TEST123', $delivery->provider_message_id);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('923001234567', $delivery->recipient);
    }

    public function test_api_failure_logs_failed_delivery_and_returns_502(): void
    {
        $company = $this->makeCompany();
        $this->configureWa($company);
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Template name does not exist'],
            ], 400),
        ]);

        $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-whatsapp", [
            'phone' => '03001234567',
            'mode' => 'api',
        ])->assertStatus(502)->assertJsonPath('status', 'error');

        $delivery = DB::table('invoice_deliveries')->where('invoice_id', $invoiceId)->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('Template name does not exist', $delivery->error);
        $this->assertNull($delivery->provider_message_id);
    }

    // ─── 5. unconfigured api mode ────────────────────────────────────────

    public function test_api_mode_without_configuration_is_422_with_no_delivery(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        Http::fake();

        $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-whatsapp", [
            'phone' => '03001234567',
            'mode' => 'api',
        ])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('invoice_deliveries')->count());
    }

    // ─── 6. wa.me fallback untouched ─────────────────────────────────────

    public function test_link_mode_still_returns_wa_url_even_when_api_configured(): void
    {
        $company = $this->makeCompany();
        $this->configureWa($company);
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        Http::fake();

        $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-whatsapp", [
            'phone' => '03001234567',
        ])->assertOk()->assertJsonPath('status', 'ok')
          ->assertJsonPath('wa_url', fn ($url) => str_starts_with($url, 'https://wa.me/923001234567'));

        Http::assertNothingSent();
    }

    // ─── 7. webhook ──────────────────────────────────────────────────────

    public function test_webhook_verify_handshake(): void
    {
        $company = $this->makeCompany();
        $this->configureWa($company); // verify token: verify-me-123

        $this->get("/webhooks/whatsapp/{$company->id}?hub_mode=subscribe&hub_verify_token=verify-me-123&hub_challenge=CH4LL")
            ->assertOk()->assertSee('CH4LL', false);

        $this->get("/webhooks/whatsapp/{$company->id}?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=CH4LL")
            ->assertStatus(403);
    }

    private function statusPayload(string $wamid, string $status, array $errors = []): array
    {
        $s = ['id' => $wamid, 'status' => $status, 'timestamp' => '1700000000'];
        if ($errors) $s['errors'] = $errors;
        return ['entry' => [['changes' => [['value' => ['statuses' => [$s]]]]]]];
    }

    public function test_webhook_moves_status_forward_only_and_failed_captures_error(): void
    {
        $company = $this->makeCompany();
        $this->configureWa($company);
        $invoiceId = $this->makeInvoice($company);

        $deliveryId = DB::table('invoice_deliveries')->insertGetId([
            'invoice_id' => $invoiceId, 'company_id' => $company->id,
            'channel' => 'whatsapp', 'recipient' => '923001234567',
            'status' => 'sent', 'provider_message_id' => 'wamid.X1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = "/webhooks/whatsapp/{$company->id}";

        $this->postJson($url, $this->statusPayload('wamid.X1', 'delivered'))->assertOk();
        $this->assertSame('delivered', DB::table('invoice_deliveries')->find($deliveryId)->status);

        $this->postJson($url, $this->statusPayload('wamid.X1', 'read'))->assertOk();
        $this->assertSame('read', DB::table('invoice_deliveries')->find($deliveryId)->status);

        // Late "delivered" must NOT downgrade "read"
        $this->postJson($url, $this->statusPayload('wamid.X1', 'delivered'))->assertOk();
        $this->assertSame('read', DB::table('invoice_deliveries')->find($deliveryId)->status);

        // failed wins + captures the error
        $this->postJson($url, $this->statusPayload('wamid.X1', 'failed', [
            ['title' => 'Message undeliverable', 'message' => 'Recipient not on WhatsApp'],
        ]))->assertOk();
        $row = DB::table('invoice_deliveries')->find($deliveryId);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Recipient not on WhatsApp', $row->error);
    }

    public function test_webhook_never_updates_other_companies_deliveries(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany(['name' => 'Other Co', 'email' => 'other@b.test']);
        $this->configureWa($companyA);
        $this->configureWa($companyB);
        $invoiceId = $this->makeInvoice($companyA);

        $deliveryId = DB::table('invoice_deliveries')->insertGetId([
            'invoice_id' => $invoiceId, 'company_id' => $companyA->id,
            'channel' => 'whatsapp', 'recipient' => '923001234567',
            'status' => 'sent', 'provider_message_id' => 'wamid.A1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Same wamid arriving on company B's endpoint must be ignored
        $this->postJson("/webhooks/whatsapp/{$companyB->id}", $this->statusPayload('wamid.A1', 'read'))->assertOk();
        $this->assertSame('sent', DB::table('invoice_deliveries')->find($deliveryId)->status);
    }
}
