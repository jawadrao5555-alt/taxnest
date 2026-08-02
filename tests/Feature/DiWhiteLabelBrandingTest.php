<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\DiBrandingService;
use App\Services\DiFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 140: DI Premium white-label branding (gate key `white_label`).
 *
 * Locks in:
 *  - DiBrandingService fails closed: branding only activates when the plan
 *    gate allows AND the company toggled it on; everything else = defaults.
 *  - All 4 DI PDF templates + public share page + delivery email apply the
 *    accent / footer lines / logo and can hide the platform credit line.
 *  - FBR-required elements (FBR invoice number, QR, tax breakdown) render
 *    regardless of branding choices.
 *  - Non-premium output is byte-for-byte free of custom branding, and the
 *    BW template stays pure B&W when branding is off.
 *  - /company/branding settings page: premium admins get the form,
 *    non-premium get the upgrade nudge; PUT persists (incl. logo upload),
 *    is rejected for non-premium plans, and validates the accent color.
 */
class DiWhiteLabelBrandingTest extends TestCase
{
    /** 1x1 transparent PNG (valid binary for service-level logo tests). */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const ACCENT = '#7c3aed'; // never used by any default template

    protected function setUp(): void
    {
        parent::setUp();

        DiFeatureService::flushGateCaches();
        DiBrandingService::flushCache();

        Storage::fake('public');

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('owner_name')->nullable();
            $t->string('ntn')->nullable();
            $t->string('cnic')->nullable();
            $t->string('strn')->nullable();
            $t->string('registration_no')->nullable();
            $t->string('fbr_registration_no')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('mobile')->nullable();
            $t->string('business_activity')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('province')->nullable();
            $t->string('website')->nullable();
            $t->string('product_type')->default('di');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('force_watermark')->default(false);
            $t->boolean('onboarding_completed')->default(true);
            $t->text('di_branding')->nullable();
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
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('dark_mode')->default(false);
            $t->string('language')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
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
            $t->string('product_type')->default('di');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->default(-1);
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

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number')->nullable();
            $t->string('internal_invoice_number')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('status')->default('draft');
            $t->string('fbr_status')->nullable();
            $t->string('invoice_date')->nullable();
            $t->string('due_date')->nullable();
            $t->string('buyer_name')->nullable();
            $t->string('buyer_ntn')->nullable();
            $t->string('buyer_cnic')->nullable();
            $t->string('buyer_phone')->nullable();
            $t->string('buyer_address')->nullable();
            $t->string('buyer_province')->nullable();
            $t->string('buyer_registration_type')->nullable();
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->decimal('wht_rate', 8, 2)->nullable();
            $t->decimal('wht_amount', 14, 2)->nullable();
            $t->decimal('net_receivable', 14, 2)->nullable();
            $t->text('qr_data')->nullable();
            $t->string('share_uuid')->nullable();
            $t->text('notes')->nullable();
            $t->string('po_number')->nullable();
            $t->string('currency')->nullable();
            $t->string('scenario_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('description')->nullable();
            $t->string('hs_code')->nullable();
            $t->decimal('quantity', 12, 2)->default(1);
            $t->string('uom')->nullable();
            $t->string('default_uom')->nullable();
            $t->decimal('price', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('further_tax', 14, 2)->default(0);
            $t->decimal('further_tax_rate', 8, 2)->default(0);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->string('schedule_type')->nullable();
            $t->string('sro_schedule_no')->nullable();
            $t->string('serial_no')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function makeCompany(string $planName = 'Premium', ?array $branding = null): Company
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Branded Traders',
            'ntn' => '1234567',
            'fbr_registration_no' => '1234567',
            'address' => 'Shop 1, Mall Road',
            'city' => 'Lahore',
            'province' => 'Punjab',
            'email' => 'co@example.com',
            'phone' => '0300-0000000',
            'product_type' => 'di',
            'status' => 'active',
            'company_status' => 'active',
            'onboarding_completed' => true,
            'di_branding' => $branding !== null ? json_encode($branding) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => $planName,
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->addDays(355)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Company::find($companyId);
    }

    /** Full branding payload as saved by the settings form. */
    private function brandingOn(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'logo_path' => null,
            'accent' => self::ACCENT,
            'footer_line1' => 'Karobar ki sharait apply hoti hain',
            'footer_line2' => 'Shukriya - Branded Traders',
            'hide_platform' => true,
        ], $overrides);
    }

    private function putLogoOnDisk(string $path = 'branding-logos/test-logo.png'): string
    {
        Storage::disk('public')->put($path, base64_decode(self::PNG_1PX));

        return $path;
    }

    private function makeInvoice(Company $company): Invoice
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $company->id,
            'invoice_number' => 'C18DI00042',
            'fbr_invoice_number' => 'FBR1234567890142',
            'status' => 'locked',
            'fbr_status' => 'production',
            'invoice_date' => now()->toDateString(),
            'buyer_name' => 'Buyer & Co',
            'buyer_ntn' => '7654321',
            'buyer_address' => 'Blue Area, Islamabad',
            'buyer_registration_type' => 'Registered',
            'total_amount' => 1180,
            'wht_rate' => 0,
            'wht_amount' => 0,
            'net_receivable' => 1180,
            'qr_data' => json_encode([
                'sellerNTNCNIC' => '1234567',
                'fbr_invoice_number' => 'FBR1234567890142',
                'invoiceDate' => now()->toDateString(),
                'totalValues' => 1180,
            ]),
            'share_uuid' => 'share-uuid-co-' . $company->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $invoiceId,
                'description' => 'Steel Pipe 2 inch',
                'hs_code' => '7306.3000',
                'quantity' => 10,
                'uom' => 'Numbers, pieces, units',
                'price' => 50,
                'tax' => 90,
                'tax_rate' => 18,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $invoiceId,
                'description' => 'Steel Sheet 3mm',
                'hs_code' => '7208.5100',
                'quantity' => 5,
                'uom' => 'Numbers, pieces, units',
                'price' => 100,
                'tax' => 90,
                'tax_rate' => 18,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return Invoice::withoutGlobalScope(CompanyScope::class)
            ->with('items', 'company')
            ->find($invoiceId);
    }

    private function makeAdmin(Company $company): User
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Admin',
            'email' => 'admin-co-' . $company->id . '@example.com',
            'password' => bcrypt('secret-password'),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($userId);
    }

    private const PDF_TEMPLATES = ['pdf-bw', 'pdf-modern', 'pdf-professional', 'pdf-annex'];

    /** Mirrors the $data ShareController/InvoiceController pass to loadView(). */
    private function renderPdf(string $template, Invoice $invoice): string
    {
        return view('invoice.' . $template, [
            'invoice' => $invoice,
            'showWatermark' => false,
            'isDraft' => false,
            'subtotal' => 1000.0,
            'totalTax' => 180.0,
            'wht_rate' => 0,
            'wht_amount' => 0,
            'net_receivable' => 1180.0,
            // Distinct from PNG_1PX so logo assertions can't false-match the QR.
            'qrBase64' => 'data:image/png;base64,FAKEQRPAYLOADFORTESTONLY==',
            'fbrLogoBase64' => '',
        ])->render();
    }

    // ------------------------------------------------------------------
    // Service gating
    // ------------------------------------------------------------------

    public function test_non_premium_company_gets_defaults_even_with_branding_enabled(): void
    {
        $company = $this->makeCompany('Retail', $this->brandingOn());

        $brand = DiBrandingService::forCompany($company);

        $this->assertFalse($brand['active']);
        $this->assertFalse($brand['allowed']);
        $this->assertNull($brand['accent']);
        $this->assertSame([], $brand['footer_lines']);
        $this->assertFalse($brand['hide_platform']);
        $this->assertNull($brand['logo_data_uri']);
    }

    public function test_premium_company_with_toggle_on_gets_active_branding(): void
    {
        $logoPath = $this->putLogoOnDisk();
        $company = $this->makeCompany('Premium', $this->brandingOn(['logo_path' => $logoPath]));

        $brand = DiBrandingService::forCompany($company);

        $this->assertTrue($brand['active']);
        $this->assertTrue($brand['allowed']);
        $this->assertSame(self::ACCENT, $brand['accent']);
        $this->assertSame('#ffffff', $brand['accent_text']); // dark purple → white text
        $this->assertSame(['Karobar ki sharait apply hoti hain', 'Shukriya - Branded Traders'], $brand['footer_lines']);
        $this->assertTrue($brand['hide_platform']);
        $this->assertStringStartsWith('data:image/png;base64,', $brand['logo_data_uri']);
        $this->assertNotNull($brand['logo_url']);
    }

    public function test_premium_company_with_toggle_off_stays_default(): void
    {
        $company = $this->makeCompany('Premium', $this->brandingOn(['enabled' => false]));

        $brand = DiBrandingService::forCompany($company);

        $this->assertFalse($brand['active']);
        $this->assertTrue($brand['allowed']); // gate open, but toggle wins
        $this->assertNull($brand['accent']);
        $this->assertSame([], $brand['footer_lines']);
        $this->assertFalse($brand['hide_platform']);
    }

    public function test_accent_is_revalidated_at_render_time(): void
    {
        // A hostile/corrupt DB value must never reach a <style> block.
        $company = $this->makeCompany('Premium', $this->brandingOn([
            'accent' => 'red;}body{display:none}',
        ]));

        $brand = DiBrandingService::forCompany($company);

        $this->assertTrue($brand['active']);
        $this->assertNull($brand['accent']);

        $this->assertNull(DiBrandingService::sanitizeAccent('#GGGGGG'));
        $this->assertNull(DiBrandingService::sanitizeAccent('0a4d5c'));
        $this->assertNull(DiBrandingService::sanitizeAccent('#0a4d5c99'));
        $this->assertSame('#0a4d5c', DiBrandingService::sanitizeAccent('#0A4D5C'));

        $this->assertSame('#111111', DiBrandingService::contrastText('#ffffff'));
        $this->assertSame('#ffffff', DiBrandingService::contrastText('#0a4d5c'));
    }

    // ------------------------------------------------------------------
    // PDF templates
    // ------------------------------------------------------------------

    public function test_all_pdf_templates_apply_branding_for_premium_company(): void
    {
        $logoPath = $this->putLogoOnDisk();
        $company = $this->makeCompany('Premium', $this->brandingOn(['logo_path' => $logoPath]));
        $invoice = $this->makeInvoice($company);

        foreach (self::PDF_TEMPLATES as $template) {
            DiBrandingService::flushCache();
            $html = $this->renderPdf($template, $invoice);

            $this->assertStringContainsString(self::ACCENT, $html, "$template: accent missing");
            $this->assertStringContainsString('Karobar ki sharait apply hoti hain', $html, "$template: footer line missing");
            $this->assertStringContainsString('data:image/png;base64,' . self::PNG_1PX, $html, "$template: embedded logo missing");
            $this->assertStringNotContainsString('Invoice Management System', $html, "$template: platform credit not hidden");

            // FBR-required elements survive every branding choice.
            $this->assertStringContainsString('FBR1234567890142', $html, "$template: FBR invoice number missing");
            $this->assertStringContainsString('FAKEQRPAYLOADFORTESTONLY==', $html, "$template: QR image missing");
            $this->assertStringContainsString('Sales Tax', $html, "$template: tax breakdown missing");
        }
    }

    public function test_all_pdf_templates_stay_default_for_non_premium_company(): void
    {
        $logoPath = $this->putLogoOnDisk();
        $company = $this->makeCompany('Retail', $this->brandingOn(['logo_path' => $logoPath]));
        $invoice = $this->makeInvoice($company);

        foreach (self::PDF_TEMPLATES as $template) {
            DiBrandingService::flushCache();
            $html = $this->renderPdf($template, $invoice);

            $this->assertStringNotContainsString(self::ACCENT, $html, "$template: accent leaked to non-premium");
            $this->assertStringNotContainsString('Karobar ki sharait apply hoti hain', $html, "$template: footer leaked");
            $this->assertStringNotContainsString('data:image/png;base64,' . self::PNG_1PX, $html, "$template: logo leaked");
            $this->assertStringContainsString('Invoice Management System', $html, "$template: platform credit missing");
            $this->assertStringContainsString('FBR1234567890142', $html, "$template: FBR invoice number missing");
        }
    }

    public function test_bw_template_stays_pure_when_premium_toggle_off(): void
    {
        $company = $this->makeCompany('Premium', $this->brandingOn(['enabled' => false]));
        $invoice = $this->makeInvoice($company);

        $html = $this->renderPdf('pdf-bw', $invoice);

        $this->assertStringNotContainsString(self::ACCENT, $html);
        $this->assertStringContainsString('Invoice Management System', $html);
    }

    // ------------------------------------------------------------------
    // Public share page
    // ------------------------------------------------------------------

    public function test_share_page_applies_branding(): void
    {
        $logoPath = $this->putLogoOnDisk();
        $company = $this->makeCompany('Premium', $this->brandingOn(['logo_path' => $logoPath]));
        $invoice = $this->makeInvoice($company);

        $response = $this->get('/share/invoice/' . $invoice->share_uuid);

        $response->assertOk();
        $response->assertSee(self::ACCENT, false);
        $response->assertSee('Karobar ki sharait apply hoti hain');
        $response->assertDontSee('Powered by TaxNest');
        $response->assertSee('FBR1234567890142');
    }

    public function test_share_page_stays_default_for_non_premium(): void
    {
        $company = $this->makeCompany('Retail', $this->brandingOn());
        $invoice = $this->makeInvoice($company);

        $response = $this->get('/share/invoice/' . $invoice->share_uuid);

        $response->assertOk();
        $response->assertDontSee(self::ACCENT, false);
        $response->assertDontSee('Karobar ki sharait apply hoti hain');
        $response->assertSee('Powered by TaxNest');
        $response->assertSee('FBR1234567890142');
    }

    // ------------------------------------------------------------------
    // Delivery email view
    // ------------------------------------------------------------------

    public function test_delivery_email_view_applies_branding(): void
    {
        $company = $this->makeCompany('Premium', $this->brandingOn());
        $invoice = $this->makeInvoice($company);

        $html = view('emails.invoice-delivery', [
            'invoice' => $invoice,
            'shareUrl' => 'https://example.com/share/invoice/' . $invoice->share_uuid,
        ])->render();

        $this->assertStringContainsString(self::ACCENT, $html);
        $this->assertStringContainsString('Karobar ki sharait apply hoti hain', $html);
        $this->assertStringNotContainsString('Powered by TaxNest', $html);
        $this->assertStringContainsString('FBR1234567890142', $html);
        $this->assertStringContainsString('Sales Tax', $html);
    }

    public function test_delivery_email_view_default_for_non_premium(): void
    {
        $company = $this->makeCompany('Retail', $this->brandingOn());
        $invoice = $this->makeInvoice($company);

        $html = view('emails.invoice-delivery', [
            'invoice' => $invoice,
            'shareUrl' => 'https://example.com/share/invoice/' . $invoice->share_uuid,
        ])->render();

        $this->assertStringNotContainsString(self::ACCENT, $html);
        $this->assertStringContainsString('Powered by TaxNest', $html);
        $this->assertStringContainsString('FBR1234567890142', $html);
    }

    // ------------------------------------------------------------------
    // Settings page + update endpoint
    // ------------------------------------------------------------------

    public function test_premium_admin_sees_branding_form(): void
    {
        $company = $this->makeCompany('Premium');
        $user = $this->makeAdmin($company);

        $response = $this->actingAs($user)->get('/company/branding');

        $response->assertOk();
        $response->assertSee('name="accent_color"', false);
        $response->assertSee('name="branding_enabled"', false);
    }

    public function test_non_premium_admin_sees_upgrade_nudge(): void
    {
        $company = $this->makeCompany('Retail');
        $user = $this->makeAdmin($company);

        $response = $this->actingAs($user)->get('/company/branding');

        $response->assertOk();
        $response->assertSee('DI Premium');
        $response->assertDontSee('name="accent_color"', false);
    }

    public function test_update_persists_settings_and_logo_for_premium(): void
    {
        $company = $this->makeCompany('Premium');
        $user = $this->makeAdmin($company);

        $response = $this->actingAs($user)->put('/company/branding', [
            'branding_enabled' => '1',
            'accent_color' => '#0A4D5C',
            'footer_line1' => 'Thank you for your business',
            'footer_line2' => 'Returns within 7 days',
            'hide_platform_branding' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $response->assertRedirect('/company/branding');
        $response->assertSessionHas('success');

        $saved = Company::find($company->id)->di_branding;
        $this->assertTrue($saved['enabled']);
        $this->assertSame('#0a4d5c', $saved['accent']);
        $this->assertSame('Thank you for your business', $saved['footer_line1']);
        $this->assertTrue($saved['hide_platform']);
        $this->assertStringStartsWith(DiBrandingService::LOGO_DIR . '/', $saved['logo_path']);
        Storage::disk('public')->assertExists($saved['logo_path']);

        DiBrandingService::flushCache();
        $brand = DiBrandingService::forCompany(Company::find($company->id));
        $this->assertTrue($brand['active']);
        $this->assertSame('#0a4d5c', $brand['accent']);

        $this->assertDatabaseHas('security_logs', ['action' => 'di_branding_updated']);
    }

    public function test_update_rejected_for_non_premium_saves_nothing(): void
    {
        $company = $this->makeCompany('Retail');
        $user = $this->makeAdmin($company);

        $response = $this->actingAs($user)->put('/company/branding', [
            'branding_enabled' => '1',
            'accent_color' => '#0A4D5C',
        ]);

        $response->assertRedirect('/company/branding');
        $response->assertSessionHas('error');
        $this->assertNull(Company::find($company->id)->di_branding);
    }

    public function test_update_rejects_invalid_accent(): void
    {
        $company = $this->makeCompany('Premium');
        $user = $this->makeAdmin($company);

        $response = $this->actingAs($user)
            ->from('/company/branding')
            ->put('/company/branding', [
                'branding_enabled' => '1',
                'accent_color' => 'javascript:alert(1)',
            ]);

        $response->assertRedirect('/company/branding');
        $response->assertSessionHasErrors('accent_color');
        $this->assertNull(Company::find($company->id)->di_branding);
    }

    public function test_remove_logo_deletes_file_and_clears_path(): void
    {
        $logoPath = $this->putLogoOnDisk();
        $company = $this->makeCompany('Premium', $this->brandingOn(['logo_path' => $logoPath]));
        $user = $this->makeAdmin($company);

        $response = $this->actingAs($user)->put('/company/branding', [
            'branding_enabled' => '1',
            'remove_logo' => '1',
        ]);

        $response->assertRedirect('/company/branding');
        $response->assertSessionHas('success');

        $saved = Company::find($company->id)->di_branding;
        $this->assertNull($saved['logo_path']);
        Storage::disk('public')->assertMissing($logoPath);
    }
}
