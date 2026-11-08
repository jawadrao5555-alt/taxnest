<?php

namespace Tests\Feature;

use App\Models\{AdminUser, AppUpdate, SystemSetting, TutorialVideo};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReleaseCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $actor;
    private AppUpdate $update;
    private TutorialVideo $video;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = AdminUser::create([
            'name' => 'Fictional release operator',
            'email' => 'release-operator@example.test',
            'password' => bcrypt('SyntheticOnly123!'),
            'role' => 'super_admin',
        ]);
        $this->update = AppUpdate::create([
            'title' => 'Fictional retained customer update',
            'points' => ['Existing customer wording'],
            'audience' => 'pos',
            'audience_family' => 'all',
            'is_published' => true,
        ]);
        $this->video = TutorialVideo::create([
            'slug' => 'fictional-release-capability-video',
            'product' => 'nestpos',
            'title' => 'Fictional retained tutorial',
            'description' => 'Synthetic only',
            'video_url' => '/videos/tutorials/does-not-need-to-exist.mp4',
            'category' => 'settings',
            'min_role' => 'any',
            'sort' => 9999,
            'is_published' => true,
            'show_public' => false,
            'controls_applied' => true,
            'audience_family' => 'all',
        ]);
        SystemSetting::set('pos_whats_new_enabled', '1', 'Synthetic release capability baseline');
    }

    public function test_cached_role_downgrade_cannot_change_customer_updates_or_tutorial_release_state(): void
    {
        $this->actingAs($this->actor, 'admin');
        // Keep the authenticated model cached as super_admin while the
        // persisted actor is downgraded by another request/administrator.
        DB::table('admin_users')->where('id', $this->actor->id)->update(['role' => 'support']);

        $updateBefore = $this->update->fresh()->getAttributes();
        $videoBefore = $this->video->fresh()->getAttributes();
        $settingBefore = SystemSetting::get('pos_whats_new_enabled');

        $requests = [
            fn () => $this->post('/admin/app-updates/feature-toggle'),
            fn () => $this->post("/admin/app-updates/{$this->update->id}/toggle"),
            fn () => $this->post("/admin/app-updates/{$this->update->id}/reannounce"),
            fn () => $this->delete("/admin/app-updates/{$this->update->id}/delete"),
            fn () => $this->post("/admin/app-updates/{$this->update->id}/update", [
                'title' => 'Unauthorized edit', 'points_text' => 'Must not persist',
                'audience' => 'pos', 'audience_family' => 'all', 'audience_scope' => 'all',
            ]),
            fn () => $this->post('/admin/app-updates', [
                'title' => 'Unauthorized publication', 'points_text' => 'Must not persist',
                'audience' => 'pos', 'audience_family' => 'all', 'audience_scope' => 'all',
            ]),
            fn () => $this->post("/admin/tutorial-videos/{$this->video->id}/toggle-published"),
            fn () => $this->post("/admin/tutorial-videos/{$this->video->id}/toggle-public"),
            fn () => $this->post("/admin/tutorial-videos/{$this->video->id}/gate", [
                'required_feature' => 'inventory_enabled', 'audience_family' => 'goods_retail',
            ]),
            fn () => $this->post("/admin/tutorial-videos/{$this->video->id}/role", [
                'min_role' => 'admin', 'audience_family' => 'services',
            ]),
        ];

        foreach ($requests as $request) {
            $request()->assertForbidden();
        }

        $this->assertSame($updateBefore, $this->update->fresh()->getAttributes());
        $this->assertSame($videoBefore, $this->video->fresh()->getAttributes());
        $this->assertSame($settingBefore, SystemSetting::get('pos_whats_new_enabled'));
        $this->assertDatabaseMissing('app_updates', ['title' => 'Unauthorized publication']);
        $this->assertSame('support', $this->actor->fresh()->role);
    }

    public function test_support_can_read_release_history_but_cannot_see_write_forms(): void
    {
        $this->actor->update(['role' => 'support']);

        $this->actingAs($this->actor, 'admin')->get('/admin/app-updates')
            ->assertOk()
            ->assertSee($this->update->title)
            ->assertSee('Release history is read-only')
            ->assertDontSee('/admin/app-updates/feature-toggle', false)
            ->assertDontSee('id="addUpdateModal"', false)
            ->assertDontSee("/admin/app-updates/{$this->update->id}/toggle", false);

        $this->actingAs($this->actor, 'admin')->get('/admin/tutorial-videos')
            ->assertOk()
            ->assertSee($this->video->title)
            ->assertSee('Tutorial release controls are read-only')
            ->assertDontSee("/admin/tutorial-videos/{$this->video->id}/toggle-published", false)
            ->assertDontSee("/admin/tutorial-videos/{$this->video->id}/gate", false);
    }

    public function test_existing_administrator_role_retains_release_authority(): void
    {
        $this->actor->update(['role' => 'admin']);

        $this->actingAs($this->actor, 'admin')
            ->post("/admin/app-updates/{$this->update->id}/toggle")
            ->assertRedirect('/admin/app-updates');
        $this->assertFalse((bool) $this->update->fresh()->is_published);

        $this->actingAs($this->actor, 'admin')
            ->post("/admin/tutorial-videos/{$this->video->id}/toggle-public")
            ->assertRedirect('/admin/tutorial-videos');
        $this->assertTrue((bool) $this->video->fresh()->show_public);
    }
}
