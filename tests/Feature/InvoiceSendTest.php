<?php

namespace Tests\Feature;

use App\Mail\InvoiceShareMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DI INVOICE → BUYER via EMAIL / WHATSAPP (Task 136)
 *
 *  1. Email send: InvoiceShareMail to the typed address, invoice_deliveries
 *     row (channel/recipient/user), sent_email activity log.
 *  2. WhatsApp send: PK number normalized into a wa.me URL, delivery row on
 *     the NORMALIZED number, sent_whatsapp activity log.
 *  3. Unroutable phone → 422 with Roman-Urdu error, NO delivery row.
 *  4. send-info prefills from the matched CustomerProfile (NTN match) and
 *     self-heals a missing share_uuid.
 *  5. save_to_profile=1 writes the contact back (updates matched profile /
 *     creates one from buyer fields); off by default — no silent writes.
 *  6. Cross-company invoices are unreachable (CompanyScope 404).
 */
class InvoiceSendTest extends TestCase
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
            $table->string('product_type')->nullable();
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

    /** Raw insert so tests control share_uuid (Model::create would auto-set it). */
    private function makeInvoice(Company $company, array $attrs = []): int
    {
        return DB::table('invoices')->insertGetId(array_merge([
            'company_id' => $company->id,
            'invoice_number' => 'INV-' . uniqid(),
            'status' => 'submitted',
            'buyer_name' => 'Buyer Steel Works',
            'buyer_ntn' => '7654321',
            'buyer_address' => 'Industrial Area, Lahore',
            'buyer_registration_type' => 'Registered',
            'total_amount' => 46500.00,
            'share_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    // ─── 1. email send ───────────────────────────────────────────────────

    public function test_email_send_dispatches_mail_and_logs_delivery_and_activity(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        $response = $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-email", [
            'email' => 'Buyer@Example.COM',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('delivery.channel', 'email')
            ->assertJsonPath('delivery.recipient', 'buyer@example.com')
            ->assertJsonPath('delivery.status', 'sent');

        Mail::assertSent(InvoiceShareMail::class, function (InvoiceShareMail $mail) {
            return $mail->hasTo('buyer@example.com');
        });

        $delivery = DB::table('invoice_deliveries')->where('invoice_id', $invoiceId)->first();
        $this->assertNotNull($delivery);
        $this->assertSame('email', $delivery->channel);
        $this->assertSame('buyer@example.com', $delivery->recipient, 'Recipient must be stored lowercased');
        $this->assertSame('sent', $delivery->status);
        $this->assertEquals($user->id, $delivery->user_id);
        $this->assertEquals($company->id, $delivery->company_id);

        $activity = DB::table('invoice_activity_logs')->where('invoice_id', $invoiceId)->first();
        $this->assertNotNull($activity, 'sent_email must land in the invoice timeline');
        $this->assertSame('sent_email', $activity->action);
    }

    public function test_email_validation_rejects_garbage_address(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        $this->actingAs($user)
            ->postJson("/invoice/{$invoiceId}/send-email", ['email' => 'not-an-email'])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('invoice_deliveries')->count());
        Mail::assertNothingSent();
    }

    // ─── 2. whatsapp send ────────────────────────────────────────────────

    public function test_whatsapp_send_normalizes_number_and_returns_wa_url(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company, 'employee');
        $invoiceId = $this->makeInvoice($company);

        $response = $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-whatsapp", [
            'phone' => '0300-1234567',
        ]);

        $response->assertOk()->assertJsonPath('status', 'ok');

        $waUrl = $response->json('wa_url');
        $this->assertStringStartsWith('https://wa.me/923001234567?text=', $waUrl);
        $this->assertStringContainsString('Invoice', rawurldecode($waUrl));
        $this->assertStringContainsString('/share/invoice/', rawurldecode($waUrl), 'Message must carry the public share link');

        $delivery = DB::table('invoice_deliveries')->where('invoice_id', $invoiceId)->first();
        $this->assertNotNull($delivery);
        $this->assertSame('whatsapp', $delivery->channel);
        $this->assertSame('923001234567', $delivery->recipient, 'WhatsApp recipient must be stored normalized');
        $this->assertEquals($user->id, $delivery->user_id);

        $this->assertSame(
            'sent_whatsapp',
            DB::table('invoice_activity_logs')->where('invoice_id', $invoiceId)->value('action')
        );
    }

    public function test_whatsapp_unroutable_number_is_422_with_no_delivery_row(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        $this->actingAs($user)
            ->postJson("/invoice/{$invoiceId}/send-whatsapp", ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, DB::table('invoice_deliveries')->count());
        $this->assertSame(0, DB::table('invoice_activity_logs')->count());
    }

    // ─── 3. send-info prefill + share_uuid self-heal ─────────────────────

    public function test_send_info_prefills_from_ntn_matched_profile_and_heals_share_uuid(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);

        DB::table('customer_profiles')->insert([
            'company_id' => $company->id,
            'name' => 'Buyer Steel Works',
            'ntn' => '7654321',
            'email' => 'accounts@buyersteel.pk',
            'phone' => '0300-9998877',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Legacy row: NO share_uuid.
        $invoiceId = $this->makeInvoice($company, ['share_uuid' => null]);

        $response = $this->actingAs($user)->getJson("/invoice/{$invoiceId}/send-info");

        $response->assertOk()
            ->assertJsonPath('email', 'accounts@buyersteel.pk')
            ->assertJsonPath('phone', '0300-9998877')
            ->assertJsonPath('has_profile', true);

        $uuid = DB::table('invoices')->where('id', $invoiceId)->value('share_uuid');
        $this->assertNotEmpty($uuid, 'send-info must self-heal a missing share_uuid');
        $this->assertStringContainsString("/share/invoice/{$uuid}", $response->json('share_url'));
    }

    // ─── 4. contact save-back ────────────────────────────────────────────

    public function test_save_to_profile_updates_matched_profile_email(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);

        DB::table('customer_profiles')->insert([
            'company_id' => $company->id,
            'name' => 'Buyer Steel Works',
            'ntn' => '7654321',
            'email' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = $this->makeInvoice($company);

        $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-email", [
            'email' => 'new-contact@buyersteel.pk',
            'save_to_profile' => 1,
        ])->assertOk()->assertJsonPath('profile_saved', true);

        $this->assertSame(
            'new-contact@buyersteel.pk',
            DB::table('customer_profiles')->where('company_id', $company->id)->value('email')
        );
    }

    public function test_save_to_profile_creates_profile_from_buyer_fields_when_none_matches(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-whatsapp", [
            'phone' => '0301 7654321',
            'save_to_profile' => 1,
        ])->assertOk()->assertJsonPath('profile_saved', true);

        $profile = DB::table('customer_profiles')->where('company_id', $company->id)->first();
        $this->assertNotNull($profile, 'Profile must be created from the invoice buyer fields');
        $this->assertSame('Buyer Steel Works', $profile->name);
        $this->assertSame('7654321', $profile->ntn);
        $this->assertSame('0301 7654321', $profile->phone, 'Phone saved as typed (raw), not normalized');
        $this->assertSame('Registered', $profile->registration_type);
    }

    public function test_no_profile_write_without_save_to_profile(): void
    {
        $company = $this->makeCompany();
        $user = $this->makeUser($company);
        $invoiceId = $this->makeInvoice($company);

        $this->actingAs($user)->postJson("/invoice/{$invoiceId}/send-email", [
            'email' => 'buyer@example.com',
        ])->assertOk()->assertJsonPath('profile_saved', false);

        $this->assertSame(0, DB::table('customer_profiles')->count());
    }

    // ─── 5. tenant isolation ─────────────────────────────────────────────

    public function test_cross_company_invoice_is_unreachable(): void
    {
        $companyA = $this->makeCompany(['name' => 'Company A']);
        $companyB = $this->makeCompany(['name' => 'Company B']);
        $intruder = $this->makeUser($companyB);
        $invoiceId = $this->makeInvoice($companyA);

        $response = $this->actingAs($intruder)->postJson("/invoice/{$invoiceId}/send-email", [
            'email' => 'steal@example.com',
        ]);

        $this->assertContains($response->status(), [403, 404], 'Cross-tenant send must be blocked');
        $this->assertSame(0, DB::table('invoice_deliveries')->count());
        Mail::assertNothingSent();
    }
}
