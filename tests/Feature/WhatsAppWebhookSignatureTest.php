<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Meta WhatsApp webhook — X-Hub-Signature-256 verification (Sep 2026).
 *
 *  - GET handshake (hub.verify_token) unchanged.
 *  - Company WITH wa_app_secret: POST must carry
 *    sha256=HMAC_SHA256(secret, raw body) or it is refused with 401 and the
 *    payload is NOT processed.
 *  - Company WITHOUT a secret: legacy accept-all behaviour is preserved
 *    (logged as unsigned) so live subscriptions keep working until the owner
 *    configures the secret.
 *  - The settings page never echoes the secret back.
 */
class WhatsAppWebhookSignatureTest extends TestCase
{
    private const SECRET = 'meta-app-secret-for-tests-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('product_type')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('onboarding_completed')->default(true);
            $table->boolean('wa_api_enabled')->default(false);
            $table->string('wa_phone_number_id')->nullable();
            $table->text('wa_api_token')->nullable();
            $table->string('wa_template_name')->nullable();
            $table->boolean('wa_attach_pdf')->default(true);
            $table->string('wa_webhook_verify_token')->nullable();
            $table->text('wa_app_secret')->nullable();
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
    }

    private function makeCompany(?string $secret): Company
    {
        $company = Company::create(['name' => 'Webhook Traders', 'product_type' => 'di']);
        $company->forceFill([
            'wa_webhook_verify_token' => 'verify-me-123',
            'wa_app_secret' => $secret,
        ])->save();

        return $company;
    }

    private function makeDelivery(Company $company, string $wamid): int
    {
        return DB::table('invoice_deliveries')->insertGetId([
            'invoice_id' => 1, 'company_id' => $company->id,
            'channel' => 'whatsapp', 'recipient' => '923001234567',
            'status' => 'sent', 'provider_message_id' => $wamid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(string $wamid, string $status): string
    {
        return json_encode(['entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => $wamid, 'status' => $status, 'timestamp' => '1700000000'],
        ]]]]]]]);
    }

    private function postWebhook(Company $company, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            "/webhooks/whatsapp/{$company->id}",
            [], [], [],
            $this->transformHeadersToServerVars(array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers)),
            $body
        );
    }

    private function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function test_secret_is_stored_encrypted_and_hidden(): void
    {
        $company = $this->makeCompany(self::SECRET);

        $raw = DB::table('companies')->where('id', $company->id)->value('wa_app_secret');
        $this->assertNotEmpty($raw);
        $this->assertNotSame(self::SECRET, $raw, 'must be encrypted at rest');
        $this->assertSame(self::SECRET, $company->fresh()->wa_app_secret, 'cast must decrypt on read');
        $this->assertArrayNotHasKey('wa_app_secret', $company->fresh()->toArray());
    }

    public function test_get_verify_handshake_still_works(): void
    {
        $company = $this->makeCompany(self::SECRET);

        $this->get("/webhooks/whatsapp/{$company->id}?hub_mode=subscribe&hub_verify_token=verify-me-123&hub_challenge=CH4LL")
            ->assertOk()->assertSee('CH4LL', false);

        $this->get("/webhooks/whatsapp/{$company->id}?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=CH4LL")
            ->assertStatus(403);
    }

    public function test_valid_signature_is_accepted_and_processed(): void
    {
        $company = $this->makeCompany(self::SECRET);
        $deliveryId = $this->makeDelivery($company, 'wamid.SIG1');
        $body = $this->payload('wamid.SIG1', 'delivered');

        $this->postWebhook($company, $body, ['X-Hub-Signature-256' => $this->sign($body)])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('delivered', DB::table('invoice_deliveries')->find($deliveryId)->status);
    }

    public function test_invalid_signature_is_rejected_and_not_processed(): void
    {
        $company = $this->makeCompany(self::SECRET);
        $deliveryId = $this->makeDelivery($company, 'wamid.SIG2');
        $body = $this->payload('wamid.SIG2', 'read');

        Log::shouldReceive('warning')->once()->withArgs(function ($message, $context = []) {
            return str_contains($message, 'mismatch')
                && !str_contains($message, self::SECRET)
                && !str_contains(json_encode($context), self::SECRET);
        });

        $this->postWebhook($company, $body, ['X-Hub-Signature-256' => $this->sign($body, 'wrong-secret')])
            ->assertStatus(401);

        $this->assertSame('sent', DB::table('invoice_deliveries')->find($deliveryId)->status);
    }

    public function test_tampered_body_fails_signature(): void
    {
        $company = $this->makeCompany(self::SECRET);
        $deliveryId = $this->makeDelivery($company, 'wamid.SIG3');
        $signed = $this->payload('wamid.SIG3', 'delivered');
        $tampered = $this->payload('wamid.SIG3', 'failed');

        $this->postWebhook($company, $tampered, ['X-Hub-Signature-256' => $this->sign($signed)])
            ->assertStatus(401);

        $this->assertSame('sent', DB::table('invoice_deliveries')->find($deliveryId)->status);
    }

    public function test_missing_signature_is_rejected_when_secret_configured(): void
    {
        $company = $this->makeCompany(self::SECRET);
        $deliveryId = $this->makeDelivery($company, 'wamid.SIG4');

        $this->postWebhook($company, $this->payload('wamid.SIG4', 'read'))->assertStatus(401);
        $this->postWebhook($company, $this->payload('wamid.SIG4', 'read'), ['X-Hub-Signature-256' => 'md5=abc'])->assertStatus(401);

        $this->assertSame('sent', DB::table('invoice_deliveries')->find($deliveryId)->status);
    }

    public function test_unsigned_post_accepted_when_no_secret_configured_legacy(): void
    {
        $company = $this->makeCompany(null);
        $deliveryId = $this->makeDelivery($company, 'wamid.LEG1');

        Log::shouldReceive('info')->atLeast()->once()->withArgs(fn ($m) => str_contains($m, 'UNSIGNED'));
        Log::shouldReceive('warning')->never();

        $this->postWebhook($company, $this->payload('wamid.LEG1', 'delivered'))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('delivered', DB::table('invoice_deliveries')->find($deliveryId)->status);
    }

    public function test_settings_form_stores_secret_encrypted_never_echoes_it_and_can_clear_it(): void
    {
        $company = $this->makeCompany(null);
        $user = \App\Models\User::create([
            'name' => 'Owner', 'email' => 'owner@webhook.test',
            'password' => \Illuminate\Support\Facades\Hash::make('Secret@123'),
            'company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true,
        ]);

        // The full page pulls in the app layout (subscriptions, banners…) which
        // this minimal schema does not carry — inspect the view model instead.
        $this->actingAs($user);
        $view = (new \App\Http\Controllers\CompanySettingsController())->whatsappSettings();
        $this->assertFalse($view->getData()['hasAppSecret']);

        $this->actingAs($user)->put('/company/whatsapp-settings', [
            'wa_webhook_verify_token' => 'verify-me-123',
            'wa_app_secret' => self::SECRET,
        ])->assertRedirect('/company/whatsapp-settings')->assertSessionHas('success');

        $company->refresh();
        $this->assertSame(self::SECRET, $company->wa_app_secret);
        $this->assertNotSame(self::SECRET, DB::table('companies')->where('id', $company->id)->value('wa_app_secret'));

        $view = (new \App\Http\Controllers\CompanySettingsController())->whatsappSettings();
        $this->assertTrue($view->getData()['hasAppSecret']);
        // Only the flag reaches the template; the secret itself is never echoed.
        $blade = file_get_contents(resource_path('views/company/whatsapp-settings.blade.php'));
        $this->assertStringNotContainsString('$company->wa_app_secret', $blade);
        $this->assertStringContainsString('name="wa_app_secret" value=""', $blade);

        // Blank field on re-save keeps the secret.
        $this->actingAs($user)->put('/company/whatsapp-settings', ['wa_app_secret' => ''])->assertSessionHas('success');
        $this->assertSame(self::SECRET, $company->fresh()->wa_app_secret);

        // Explicit clear removes it → webhook back to legacy acceptance.
        $this->actingAs($user)->put('/company/whatsapp-settings', ['wa_app_secret_clear' => 1])->assertSessionHas('success');
        $this->assertNull($company->fresh()->wa_app_secret);
    }

    public function test_unknown_company_without_signature_still_answers_ok_without_side_effects(): void
    {
        // No company row → no secret → legacy accept (nothing to update).
        $this->call('POST', '/webhooks/whatsapp/999', [], [], [],
            $this->transformHeadersToServerVars(['Content-Type' => 'application/json']),
            $this->payload('wamid.NONE', 'read'))->assertOk();
    }
}
