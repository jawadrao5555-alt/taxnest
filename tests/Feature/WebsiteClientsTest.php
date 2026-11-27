<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
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

        $this->post(route('saas.admin.website-clients.store'), [
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
        $entry = WebsiteClient::create(['display_name' => 'Synthetic Books', 'product' => 'di']);
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
        $this->post($visibility, ['revision' => 1, 'action' => 'publish', 'consent' => 1, 'live_use' => 1])->assertRedirect($index);
        $this->get('/')->assertOk()->assertSee('Synthetic Market Public')->assertDontSee($future->name);
        $this->get('/digital-invoice')->assertOk()->assertDontSee('Synthetic Market Public');
    }

    public function test_linked_card_and_logo_hide_when_company_product_changes_or_company_becomes_inactive(): void
    {
        $company = Company::create(['name' => 'Synthetic Posted Account', 'ntn' => 'WC-SYNTHETIC-POSTED', 'product_type' => 'di']);
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
}
