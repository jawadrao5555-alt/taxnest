<?php

namespace Tests\Feature;

use App\Models\{AdminAnnouncement, AdminUser, AppUpdate, Company, Survey, SystemSetting, TutorialVideo};
use App\Models\FeatureSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReleaseCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $actor;
    private AppUpdate $update;
    private TutorialVideo $video;
    private AdminAnnouncement $announcement;
    private Survey $survey;
    private FeatureSuggestion $suggestion;

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
        $releaseCompany = Company::create([
            'name' => 'Fictional release history company',
            'ntn' => 'RELEASE-CAPABILITY-ONLY',
            'product_type' => 'pos',
        ]);
        $announcementCreator = \App\Models\User::factory()->create(['company_id' => $releaseCompany->id]);
        $this->announcement = AdminAnnouncement::create([
            'title' => 'Fictional retained announcement',
            'message' => 'Synthetic release history only',
            'type' => 'info',
            'target' => 'all',
            'audience_panel' => 'all',
            'is_active' => true,
            'created_by' => $announcementCreator->id,
        ]);
        $this->survey = Survey::create([
            'title' => 'Fictional retained survey',
            'questions' => [[
                'key' => 'q1', 'text' => 'Synthetic question?', 'options' => [
                    ['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No'],
                ],
            ]],
            'audience' => 'pos_all',
            'is_published' => true,
        ]);
        $this->suggestion = FeatureSuggestion::create([
            'company_id' => $announcementCreator->company_id,
            'user_id' => $announcementCreator->id,
            'product' => 'pos',
            'title' => 'Fictional retained suggestion',
            'status' => 'pending',
        ]);
        SystemSetting::set('pos_whats_new_enabled', '1', 'Synthetic release capability baseline');
        SystemSetting::set('pos_surveys_enabled', '1', 'Synthetic survey capability baseline');
    }

    public function test_cached_role_downgrade_cannot_change_customer_updates_or_tutorial_release_state(): void
    {
        $this->actingAs($this->actor, 'admin');
        // Keep the authenticated model cached as super_admin while the
        // persisted actor is downgraded by another request/administrator.
        DB::table('admin_users')->where('id', $this->actor->id)->update(['role' => 'support']);

        $updateBefore = $this->update->fresh()->getAttributes();
        $videoBefore = $this->video->fresh()->getAttributes();
        $announcementBefore = $this->announcement->fresh()->getAttributes();
        $surveyBefore = $this->survey->fresh()->getAttributes();
        $suggestionBefore = $this->suggestion->fresh()->getAttributes();
        $settingBefore = SystemSetting::get('pos_whats_new_enabled');
        $surveySettingBefore = SystemSetting::get('pos_surveys_enabled');

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
            fn () => $this->post('/admin/announcements', [
                'title' => 'Unauthorized announcement', 'message' => 'Must not persist',
                'type' => 'info', 'target' => 'all', 'audience_panel' => 'all', 'audience_scope' => 'all',
            ]),
            fn () => $this->post("/admin/announcements/{$this->announcement->id}/toggle"),
            fn () => $this->delete("/admin/announcements/{$this->announcement->id}/delete"),
            fn () => $this->post('/admin/surveys/feature-toggle'),
            fn () => $this->post('/admin/surveys', [
                'title' => 'Unauthorized survey', 'questions_json' => '[]',
                'audience' => 'pos_all',
            ]),
            fn () => $this->post("/admin/surveys/{$this->survey->id}/update", [
                'title' => 'Unauthorized survey edit', 'questions_json' => '[]',
                'audience' => 'pos_all',
            ]),
            fn () => $this->post("/admin/surveys/{$this->survey->id}/toggle-close"),
            fn () => $this->delete("/admin/surveys/{$this->survey->id}"),
            fn () => $this->post("/admin/feature-suggestions/{$this->suggestion->id}/status", [
                'status' => 'completed', 'admin_note' => 'Must not persist',
            ]),
            fn () => $this->delete("/admin/feature-suggestions/{$this->suggestion->id}/delete"),
            fn () => $this->post('/admin/sales', []),
            fn () => $this->post('/admin/sales/999999/toggle'),
            fn () => $this->delete('/admin/sales/999999'),
        ];

        foreach ($requests as $request) {
            $request()->assertForbidden();
        }

        $this->assertSame($updateBefore, $this->update->fresh()->getAttributes());
        $this->assertSame($videoBefore, $this->video->fresh()->getAttributes());
        $this->assertSame($announcementBefore, $this->announcement->fresh()->getAttributes());
        $this->assertSame($surveyBefore, $this->survey->fresh()->getAttributes());
        $this->assertSame($suggestionBefore, $this->suggestion->fresh()->getAttributes());
        $this->assertSame($settingBefore, SystemSetting::get('pos_whats_new_enabled'));
        $this->assertSame($surveySettingBefore, SystemSetting::get('pos_surveys_enabled'));
        $this->assertDatabaseMissing('app_updates', ['title' => 'Unauthorized publication']);
        $this->assertDatabaseMissing('admin_announcements', ['title' => 'Unauthorized announcement']);
        $this->assertDatabaseMissing('surveys', ['title' => 'Unauthorized survey']);
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

        $this->actingAs($this->actor, 'admin')->get('/admin/announcements')
            ->assertOk()
            ->assertSee($this->announcement->title)
            ->assertSee('Announcement history is read-only')
            ->assertDontSee('id="addAnnouncementModal"', false)
            ->assertDontSee("/admin/announcements/{$this->announcement->id}/toggle", false)
            ->assertDontSee("/admin/announcements/{$this->announcement->id}/delete", false);

        $this->actingAs($this->actor, 'admin')->get('/admin/surveys')
            ->assertOk()
            ->assertSee($this->survey->title)
            ->assertSee('Survey release history and results are read-only')
            ->assertSee(route('admin.surveys.show', $this->survey->id), false)
            ->assertDontSee('id="createSurveyModal"', false)
            ->assertDontSee('id="editSurveyModal"', false)
            ->assertDontSee(route('admin.surveys.feature-toggle'), false)
            ->assertDontSee(route('admin.surveys.toggle-close', $this->survey->id), false);

        $this->actingAs($this->actor, 'admin')->get('/admin/feature-suggestions')
            ->assertOk()
            ->assertSee($this->suggestion->title)
            ->assertSee('Feature-suggestion demand, responses and decision history are read-only for this account.')
            ->assertDontSee("/admin/feature-suggestions/{$this->suggestion->id}/status", false)
            ->assertDontSee("/admin/feature-suggestions/{$this->suggestion->id}/delete", false);
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

        $this->actingAs($this->actor, 'admin')
            ->post("/admin/announcements/{$this->announcement->id}/toggle")
            ->assertRedirect('/admin/announcements');
        $this->assertFalse((bool) $this->announcement->fresh()->is_active);

        $this->actingAs($this->actor, 'admin')
            ->post("/admin/surveys/{$this->survey->id}/toggle-close")
            ->assertRedirect();
        $this->assertNotNull($this->survey->fresh()->closed_at);

        $this->actingAs($this->actor, 'admin')->get('/admin/feature-suggestions')
            ->assertOk()
            ->assertDontSee('Feature-suggestion demand, responses and decision history are read-only for this account.')
            ->assertSee("/admin/feature-suggestions/{$this->suggestion->id}/status", false)
            ->assertSee("/admin/feature-suggestions/{$this->suggestion->id}/delete", false);
    }
}
