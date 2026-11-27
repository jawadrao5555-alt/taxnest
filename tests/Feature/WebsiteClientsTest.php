<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\WebsiteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebsiteClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_client_details_appear_on_the_homepage_and_matching_product_page(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Our Clients');
        $this->actingAs($this->admin('admin'), 'admin')->get(route('saas.admin.website-clients.create'))->assertOk();
        $company = Company::create(['name' => 'Synthetic Retail Studio', 'ntn' => 'WC-SYNTHETIC-RETAIL', 'product_type' => 'pos']);
        $this->payForPackage($company);

        $this->post(route('saas.admin.website-clients.store'), [
            'company_id' => $company->id,
            'display_name' => 'Synthetic Retail Studio',
            'product' => 'pos',
            'logo' => UploadedFile::fake()->image('logo.png', 160, 90),
        ])->assertRedirect(route('saas.admin.website-clients.index'));

        $entry = WebsiteClient::firstOrFail();
        $draftLogo = route('website-clients.logo', [$entry->id, $entry->revision]);
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);
        $this->getJson($draftLogo)->assertNotFound();
        $this->get(route('saas.admin.website-clients.logo', [$entry->id, $entry->revision]))
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        $visibility = route('saas.admin.website-clients.visibility', $entry->id);
        $this->post($visibility, ['revision' => $entry->revision, 'action' => 'publish'])
            ->assertSessionHasErrors(['consent', 'live_use']);
        $this->post($visibility, [
            'revision' => $entry->revision, 'action' => 'publish', 'live_use' => 1, 'consent' => 1,
        ])->assertRedirect(route('saas.admin.website-clients.index'));

        $entry->refresh();
        $publishedLogo = route('website-clients.logo', [$entry->id, $entry->revision]);
        auth('admin')->logout();
        $this->get('/')->assertOk()->assertSee('Our Clients')->assertSee($entry->display_name)->assertSee($publishedLogo, false);
        $this->get('/pos')->assertOk()->assertSee($entry->display_name);
        $this->get('/digital-invoice')->assertOk()->assertDontSee($entry->display_name);
        $this->get($publishedLogo)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->admin('super_admin'), 'admin')->post($visibility, [
            'revision' => $entry->revision, 'action' => 'unpublish',
        ])->assertRedirect(route('saas.admin.website-clients.index'));
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);
        $this->getJson($publishedLogo)->assertNotFound();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'website_client_unpublished', 'target_id' => $entry->id]);
    }

    public function test_editing_reopens_review_and_requires_fresh_approval_of_the_saved_revision(): void
    {
        $company = Company::create(['name' => 'Synthetic Books', 'ntn' => 'WC-SYNTHETIC-BOOKS', 'product_type' => 'di']);
        $this->payForPackage($company);
        $entry = WebsiteClient::create(['display_name' => 'Synthetic Books', 'product' => 'di']);
        $entry->forceFill(['company_id' => $company->id])->save();
        $this->actingAs($this->admin('super_admin'), 'admin');
        $visibility = route('saas.admin.website-clients.visibility', $entry->id);
        $this->post($visibility, ['action' => 'publish', 'revision' => 1, 'live_use' => 1, 'consent' => 1])->assertSessionHasNoErrors();
        $this->get('/digital-invoice')->assertOk()->assertSee('Synthetic Books')->assertSee('Our Clients');
        $this->put(route('saas.admin.website-clients.update', $entry->id), [
            'display_name' => 'Synthetic Books Updated', 'product' => 'di', 'revision' => 2,
        ])->assertSessionHasNoErrors();

        $this->post($visibility, ['action' => 'publish', 'revision' => 2, 'live_use' => 1, 'consent' => 1])
            ->assertSessionHasErrors('revision');
        $this->assertNull($entry->fresh()->published_at);
        $this->get('/')->assertOk()->assertDontSee('Synthetic Books');
    }

    public function test_support_roles_cannot_access_the_editor_or_upload_and_active_content_is_rejected(): void
    {
        $entry = WebsiteClient::create(['display_name' => 'Synthetic Draft', 'product' => 'di']);
        $this->get(route('saas.admin.website-clients.index'))->assertRedirect();
        $this->actingAs($this->admin('support'), 'admin')->get(route('saas.admin.website-clients.index'))->assertForbidden();
        $this->post(route('saas.admin.website-clients.visibility', $entry->id), [
            'action' => 'publish', 'revision' => 1, 'live_use' => 1, 'consent' => 1,
        ])->assertForbidden();

        $this->actingAs($this->admin('admin'), 'admin')->post(route('saas.admin.website-clients.store'), [
            'display_name' => 'Synthetic SVG',
            'product' => 'pos',
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ])->assertSessionHasErrors('logo');

        $this->assertDatabaseCount('website_clients', 1);
    }

    public function test_current_and_future_company_candidates_can_be_linked_but_need_review_before_publication(): void
    {
        $store = route('saas.admin.website-clients.store');
        $index = route('saas.admin.website-clients.index');
        $company = Company::create(['name' => 'Synthetic Market', 'ntn' => 'WC-SYNTHETIC-MARKET', 'product_type' => 'pos', 'pos_integration_mode' => 'standalone']);
        $future = Company::create(['name' => 'Synthetic Future Practice', 'ntn' => 'WC-SYNTHETIC-FUTURE', 'product_type' => 'erps', 'status' => 'pending']);
        $internal = Company::create(['name' => 'Synthetic Internal Group', 'ntn' => 'WC-SYNTHETIC-INTERNAL', 'is_internal_account' => true]);
        $archived = Company::create(['name' => 'Synthetic Archived Group', 'ntn' => 'WC-SYNTHETIC-ARCHIVED']);
        $archived->delete();

        $this->actingAs($this->admin('admin'), 'admin');
        $this->get($index)->assertOk()->assertSee($company->name)->assertSee('Standalone POS')
            ->assertDontSee($future->name)->assertDontSee($internal->name)->assertDontSee($archived->name);
        $this->get($index.'?q='.urlencode($company->account_code))->assertOk()->assertSee($company->name);
        $this->get('/')->assertOk()->assertDontSee($company->name);

        $companyDraft = route('saas.admin.website-clients.create', ['company' => $company->id]);
        $this->get($companyDraft)->assertOk()->assertSee('value="Synthetic Market"', false)
            ->assertSee('name="product" value="standalone"', false);
        $this->assertDatabaseCount('website_clients', 0);

        $fields = ['company_id' => $company->id, 'display_name' => 'Synthetic Market Public', 'product' => 'standalone'];
        $this->post($store, array_replace($fields, ['company_id' => $internal->id, 'product' => 'di']))
            ->assertSessionHasErrors('company_id');
        $this->post($store, array_replace($fields, ['product' => 'di']))->assertSessionHasErrors('product');
        $this->post($store, $fields)->assertRedirect($index);
        $entry = WebsiteClient::firstOrFail();
        $this->assertEquals($company->id, $entry->company_id);
        $this->assertArrayNotHasKey('company_id', $entry->toArray());
        $this->post($store, $fields)->assertSessionHasErrors('company_id');
        $this->assertDatabaseCount('website_clients', 1);
        $this->get($companyDraft)->assertRedirect(route('saas.admin.website-clients.edit', $entry->id));

        $this->assertTrue(WebsiteClient::publicCards()->isEmpty());
        $future->update(['status' => 'approved']);
        $this->get($index)->assertOk()->assertSee($future->name)->assertSee('TaxNest ERP');
        $this->assertDatabaseCount('website_clients', 1);

        $visibility = route('saas.admin.website-clients.visibility', $entry->id);
        $this->post($visibility, ['revision' => 1, 'action' => 'publish'])->assertSessionHasErrors(['consent', 'live_use']);
        $this->post($visibility, ['revision' => 1, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])
            ->assertSessionHasErrors('subscription');
        $this->payForPackage($company);
        $this->post($visibility, ['revision' => 1, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])->assertRedirect($index);
        $this->get('/')->assertOk()->assertSee('Synthetic Market Public')->assertDontSee($future->name);
        $this->get('/digital-invoice')->assertOk()->assertDontSee('Synthetic Market Public');
    }

    public function test_company_id_lookup_links_from_admin_screens_and_handles_unavailable_ids(): void
    {
        $company = Company::create(['name' => 'Synthetic ID Account', 'ntn' => 'WC-SYNTHETIC-ID', 'product_type' => 'di']);
        $pending = Company::create(['name' => 'Synthetic Pending Account', 'ntn' => 'WC-SYNTHETIC-PENDING', 'status' => 'pending']);
        $index = route('saas.admin.website-clients.index');
        $create = route('saas.admin.website-clients.create');
        $lookup = route('saas.admin.website-clients.create', ['company' => $company->id]);

        $this->actingAs($this->admin('support'), 'admin')->get($lookup)->assertForbidden();
        $this->actingAs($this->admin('admin'), 'admin');
        $this->get($index)->assertOk()->assertSee('Find by ID')->assertSee('Company ID '.$company->id);
        $this->get($create)->assertOk()->assertSee('Find by ID')->assertSee('Add manually');
        $this->get(route('saas.admin.companies.show', $company->id))->assertOk()
            ->assertSee('Review for Our Clients')->assertSee($lookup, false);
        $this->get(route('saas.admin.companies.show', $pending->id))->assertOk()->assertDontSee('Review for Our Clients');
        $this->get($lookup)->assertOk()->assertSee('Company ID '.$company->id)->assertSee('value="Synthetic ID Account"', false);
        $this->get(route('saas.admin.website-clients.create', ['company' => $company->account_code]))
            ->assertOk()->assertSee('value="Synthetic ID Account"', false);

        foreach (['', 'not-an-id', '0', ['id' => 1]] as $invalid) {
            $this->get(route('saas.admin.website-clients.create', ['company' => $invalid]))
                ->assertRedirect($index)->assertSessionHasErrors(['company' => 'Enter a valid Company ID or account code.']);
        }

        foreach ([$pending->id, $pending->id + 1000] as $unavailable) {
            $this->get(route('saas.admin.website-clients.create', ['company' => $unavailable]))
                ->assertRedirect($index)->assertSessionHasErrors(['company' => 'No eligible company was found for this ID. Check the ID in Companies.'])
                ->assertSessionHasInput('company', (string) $unavailable);
        }

        $this->assertDatabaseCount('website_clients', 0);
    }

    public function test_linked_card_and_logo_hide_when_company_product_changes_or_company_becomes_inactive(): void
    {
        $company = Company::create(['name' => 'Synthetic Posted Account', 'ntn' => 'WC-SYNTHETIC-POSTED', 'product_type' => 'di']);
        $this->payForPackage($company);
        $this->actingAs($this->admin('admin'), 'admin');
        $this->post(route('saas.admin.website-clients.store'), [
            'company_id' => $company->id,
            'display_name' => 'Synthetic Posted Client',
            'product' => 'di',
            'logo' => UploadedFile::fake()->image('logo.png', 160, 90),
        ])->assertSessionHasNoErrors();
        $entry = WebsiteClient::firstOrFail();
        $visibility = route('saas.admin.website-clients.visibility', $entry->id);
        $this->post($visibility, ['revision' => 1, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])->assertSessionHasNoErrors();
        $publicLogo = route('website-clients.logo', [$entry->id, 2]);
        $this->get($publicLogo)->assertOk();

        $company->update(['product_type' => 'pos']);
        $this->get('/')->assertOk()->assertDontSee('Synthetic Posted Client');
        $this->getJson($publicLogo)->assertNotFound();
        $this->post($visibility, ['revision' => 2, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])->assertSessionHasErrors('product');

        $company->update(['product_type' => 'di', 'company_status' => 'suspended']);
        $this->get('/')->assertOk()->assertDontSee('Synthetic Posted Client');
        $this->getJson($publicLogo)->assertNotFound();
        $this->post($visibility, ['revision' => 2, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])->assertSessionHasErrors('company_id');

        $company->update(['company_status' => 'active']);
        $company->delete();
        $this->get('/')->assertOk()->assertDontSee('Synthetic Posted Client');
        $this->getJson($publicLogo)->assertNotFound();
        $company->forceDelete();
        $this->assertDatabaseMissing('website_clients', ['id' => $entry->id]);
    }

    public function test_renewal_restores_the_same_approved_card_and_logo_until_explicitly_unpublished(): void
    {
        $company = Company::create(['name' => 'Synthetic Renewal Account', 'ntn' => 'WC-SYNTHETIC-RENEWAL', 'product_type' => 'di']);
        $paid = $this->payForPackage($company);
        $this->actingAs($this->admin('admin'), 'admin');
        $this->post(route('saas.admin.website-clients.store'), [
            'company_id' => $company->id, 'display_name' => 'Synthetic Renewal Partner', 'product' => 'di',
            'logo' => UploadedFile::fake()->image('logo.png', 160, 90),
        ])->assertSessionHasNoErrors();
        $entry = WebsiteClient::firstOrFail();
        $visibility = route('saas.admin.website-clients.visibility', $entry->id);
        $this->post($visibility, ['revision' => 1, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])->assertSessionHasNoErrors();
        $publicLogo = route('website-clients.logo', [$entry->id, 2]);

        $this->travel(31)->days();
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);
        $this->get('/digital-invoice')->assertOk()->assertDontSee($entry->display_name);
        $this->getJson($publicLogo)->assertNotFound();
        $this->get(route('saas.admin.website-clients.index'))->assertOk()->assertSee('No active paid subscription');
        $this->assertNotNull($entry->fresh()->published_at);

        PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $paid->pricing_plan_id,
            'request_type' => 'subscription', 'status' => 'pending', 'amount' => 1000, 'proof_path' => 'synthetic/proof.pdf',
        ]);
        $this->get(route('saas.admin.website-clients.index'))->assertOk()->assertSee('Payment pending');
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);

        $paid->update(['active' => false]);
        $this->payForPackage($company);
        $this->get('/digital-invoice')->assertOk()->assertSee($entry->display_name);
        $this->get($publicLogo)->assertOk();

        $this->post($visibility, ['action' => 'unpublish', 'revision' => 2])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);
        $this->getJson($publicLogo)->assertNotFound();
        $this->travelBack();
    }

    public function test_hidden_approved_cards_do_not_take_a_public_grid_slot(): void
    {
        $eligible = [];
        for ($index = 0; $index < 7; $index++) {
            $company = Company::create(['name' => 'Synthetic ERP '.$index, 'ntn' => 'WC-SYNTHETIC-SLOT-'.$index, 'product_type' => 'erps']);
            if ($index < 6) {
                $this->payForPackage($company);
                $eligible[] = $company->name;
            }
            $card = new WebsiteClient(['display_name' => $company->name, 'product' => 'erp']);
            $card->forceFill(['company_id' => $company->id, 'published_at' => now()->addMinutes($index)])->save();
        }

        $this->assertSame(array_reverse($eligible), WebsiteClient::publicCards('erp')->pluck('display_name')->all());
        $this->get('/nest-erps')->assertOk()->assertSee($eligible[0])->assertDontSee('Synthetic ERP 6');
    }

    public function test_a_legacy_manual_card_can_be_linked_but_stays_private_until_approved_again(): void
    {
        $company = Company::create(['name' => 'Synthetic Manual Account', 'ntn' => 'WC-SYNTHETIC-MANUAL', 'product_type' => 'di']);
        $this->payForPackage($company);
        $entry = WebsiteClient::create(['display_name' => 'Synthetic Existing Card', 'product' => 'di']);
        $entry->forceFill(['published_at' => now()])->save();
        $this->actingAs($this->admin('admin'), 'admin');
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);
        $edit = route('saas.admin.website-clients.edit', ['client' => $entry->id, 'company' => $company->id]);
        $this->get($edit)->assertOk()->assertSee('name="company_id" value="'.$company->id.'"', false);
        $this->put(route('saas.admin.website-clients.update', $entry->id), [
            'company_id' => $company->id, 'display_name' => $entry->display_name, 'product' => 'di', 'revision' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->published_at);
        $this->get('/')->assertOk()->assertDontSee($entry->display_name);
        $this->post(route('saas.admin.website-clients.visibility', $entry->id), [
            'revision' => 2, 'action' => 'publish', 'consent' => 1, 'live_use' => 1,
        ])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee($entry->display_name);
    }

    public function test_rollback_keeps_client_details_and_only_unpublishes_linked_cards(): void
    {
        $company = Company::create(['name' => 'Synthetic Rollback Account', 'ntn' => 'WC-SYNTHETIC-ROLLBACK', 'product_type' => 'di']);
        $linked = WebsiteClient::create(['display_name' => 'Synthetic Linked Card', 'product' => 'di']);
        $linked->forceFill(['company_id' => $company->id, 'published_at' => now()])->save();
        $manual = WebsiteClient::create(['display_name' => 'Synthetic Manual Card', 'product' => 'di']);
        $manual->forceFill(['published_at' => now()])->save();

        $migration = require database_path('migrations/2027_01_01_000023_link_website_clients_to_companies.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('website_clients', 'company_id'));
        $this->assertDatabaseHas('website_clients', ['id' => $linked->id, 'display_name' => $linked->display_name, 'published_at' => null]);
        $this->assertNotNull($manual->fresh()->published_at);

        $migration->up();
        $this->assertNull($linked->fresh()->company_id);
    }

    private function admin(string $role): AdminUser
    {
        return AdminUser::create([
            'name' => 'Synthetic Admin',
            'email' => $role.'@example.test',
            'password' => 'fictional-test-password',
            'role' => $role,
        ]);
    }

    private function payForPackage(Company $company): Subscription
    {
        $plan = PricingPlan::create([
            'name' => 'Synthetic Showcase Plan', 'product_type' => $company->product_type,
            'price' => 1000, 'invoice_limit' => 100, 'is_trial' => false,
        ]);
        $subscription = Subscription::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(),
            'active' => true, 'final_price' => 1000,
        ]);
        SubscriptionInvoice::create([
            'company_id' => $company->id, 'subscription_id' => $subscription->id,
            'amount' => 1000, 'due_date' => now()->toDateString(), 'paid_at' => now(), 'status' => 'paid',
        ]);

        return $subscription;
    }
}
