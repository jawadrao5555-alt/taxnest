<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\WebsiteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
