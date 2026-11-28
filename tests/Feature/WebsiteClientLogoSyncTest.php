<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\WebsiteClient;
use App\Services\DiFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteClientLogoSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_the_company_logo_and_public_card_follows_profile_changes_after_consent(): void
    {
        Storage::fake('public');
        $company = $this->company();
        $original = $this->storeProfileLogo($company, 'company-logos/current.png');
        $preview = route('saas.admin.website-clients.company-logo', $company->id);
        $this->get($preview)->assertRedirect();
        $this->signInAdmin();
        $this->get(route('saas.admin.website-clients.create', ['company' => $company->id]))
            ->assertOk()->assertSee($preview, false)->assertDontSee('company-logos/current.png');
        $this->get($preview)->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($original);

        $this->activatePaidPackage($company);
        $entry = $this->createCard($company);
        $this->get(route('website-clients.logo', [$entry->id, $entry->revision]))->assertNotFound();
        $this->publish($entry);

        $entry->refresh();
        $logo = route('website-clients.logo', [$entry->id, $entry->revision]);
        $this->get('/')->assertOk()->assertSee($entry->display_name)->assertSee($logo, false)
            ->assertDontSee('company-logos/current.png');
        $this->get($logo)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($original);

        $replacement = $this->storeProfileLogo($company, 'company-logos/updated.jpg');
        $this->get($logo)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent($replacement);
        $company->update(['logo_path' => null]);
        $this->get($logo)->assertNotFound();
        $this->get('/')->assertOk()->assertSee($entry->display_name)->assertDontSee($logo, false);
        $this->assertSame(2, $entry->fresh()->revision);
    }

    public function test_name_only_card_picks_up_later_logo_and_uploaded_override_keeps_precedence(): void
    {
        Storage::fake('public');
        $company = $this->company();
        $this->signInAdmin();
        $this->activatePaidPackage($company);
        $entry = $this->createCard($company);
        $this->publish($entry);

        $entry->refresh();
        $logo = route('website-clients.logo', [$entry->id, $entry->revision]);
        $this->get('/')->assertOk()->assertSee($entry->display_name)->assertDontSee($logo, false);
        $this->get($logo)->assertNotFound();

        Storage::disk('public')->put('company-logos/not-an-image.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $company->update(['logo_path' => 'company-logos/not-an-image.png']);
        $this->get('/')->assertOk()->assertSee($entry->display_name)->assertDontSee($logo, false);
        $this->get($logo)->assertNotFound();

        $profile = $this->storeProfileLogo($company, 'company-logos/added.png');
        $this->get('/')->assertOk()->assertSee($logo, false);
        $this->get($logo)->assertOk()->assertContent($profile);

        $override = UploadedFile::fake()->image('public-override.jpg', 80, 80);
        $update = route('saas.admin.website-clients.update', $entry->id);
        $this->put($update, [
            'display_name' => $entry->display_name,
            'product' => $entry->product,
            'revision' => $entry->revision,
            'logo' => $override,
        ])->assertSessionHasNoErrors();
        $this->publish($entry->refresh(), sync: false);
        $entry->refresh();
        $newLogo = route('website-clients.logo', [$entry->id, $entry->revision]);
        $this->get($newLogo)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertContent($override->get());
        $this->get($logo)->assertNotFound();

        $this->put($update, [
            'display_name' => $entry->display_name,
            'product' => $entry->product,
            'revision' => $entry->revision,
            'remove_logo' => 1,
        ])->assertSessionHasNoErrors();
        $this->publish($entry->refresh(), sync: false);
        $entry->refresh();
        $nameOnlyLogo = route('website-clients.logo', [$entry->id, $entry->revision]);
        $this->get('/')->assertOk()->assertSee($entry->display_name)->assertDontSee($nameOnlyLogo, false);
        $this->get($nameOnlyLogo)->assertNotFound();
    }

    public function test_digital_invoice_preview_uses_enabled_branding_on_asaan(): void
    {
        Storage::fake('public');
        $company = $this->company('di');
        $path = 'branding-logos/invoice-brand.png';
        $bytes = UploadedFile::fake()->image('invoice-brand.png', 32, 32)->get();
        Storage::disk('public')->put($path, $bytes);
        $company->forceFill(['di_branding' => ['enabled' => true, 'logo_path' => $path]])->save();
        $this->signInAdmin();
        $preview = route('saas.admin.website-clients.company-logo', $company->id);
        DiFeatureService::flushGateCaches();
        $this->get($preview)->assertNotFound();

        $plan = PricingPlan::create(['name' => 'Asaan', 'product_type' => 'di', 'price' => 1000, 'is_trial' => false, 'invoice_limit' => 100]);
        Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'billing_cycle' => 'annual',
            'final_price' => 1000,
            'active' => true,
        ]);
        DiFeatureService::flushGateCaches();
        $this->get($preview)->assertOk()->assertContent($bytes);
        $company->forceFill(['di_branding' => ['enabled' => false, 'logo_path' => $path]])->save();
        $this->get($preview)->assertNotFound();
    }

    private function company(string $product = 'pos'): Company
    {
        return Company::create([
            'name' => 'Synthetic Profile Business',
            'ntn' => 'WCSYNC-'.strtoupper($product),
            'product_type' => $product,
            'status' => 'approved',
            'company_status' => 'active',
        ]);
    }

    private function signInAdmin(): void
    {
        $this->actingAs(AdminUser::create([
            'name' => 'Synthetic Logo Reviewer',
            'email' => 'logo-review@example.test',
            'password' => 'FictionalLogoReviewer123',
            'role' => 'super_admin',
        ]), 'admin');
    }

    private function activatePaidPackage(Company $company): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $plan = PricingPlan::create([
            'name' => 'Starter', 'product_type' => $company->product_type, 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100,
        ]);
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual', 'amount' => 1000, 'payment_method' => 'bank_transfer',
            'reference' => 'Synthetic logo payment', 'proof_path' => 'synthetic-logo-payment', 'status' => 'pending',
        ]);

        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => 1000,
        ])->assertSessionHas('success');
    }

    private function createCard(Company $company): WebsiteClient
    {
        $this->post(route('saas.admin.website-clients.store'), [
            'company_id' => $company->id, 'display_name' => $company->name, 'product' => $company->product_type,
        ])->assertSessionHas('success');

        return WebsiteClient::query()->where('company_id', $company->id)->sole();
    }

    private function publish(WebsiteClient $entry, bool $sync = true): void
    {
        $input = ['revision' => $entry->revision, 'action' => 'publish', 'live_use' => 1, 'consent' => 1];
        if ($sync) {
            $input['sync_company_logo'] = 1;
        }

        $this->post(route('saas.admin.website-clients.visibility', $entry->id), $input)->assertSessionHas('success');
    }

    private function storeProfileLogo(Company $company, string $path): string
    {
        $bytes = UploadedFile::fake()->image(basename($path), 32, 32)->get();
        Storage::disk('public')->put($path, $bytes);
        $company->update(['logo_path' => $path]);

        return $bytes;
    }
}
