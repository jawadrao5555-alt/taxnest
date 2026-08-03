<?php

namespace Tests\Feature;

use App\Models\TutorialVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner's 3 Aug 2026 controls for task-merged tutorial videos:
 *  - offline billing video force-unpublished everywhere (until owner enables
 *    it manually from /admin/tutorial-videos);
 *  - new module videos get required_feature defaults (category + slug rules);
 *  - enforcement is once-per-row (controls_applied) so the owner's manual
 *    admin-panel changes are never overridden by the self-heal.
 */
class TutorialVideoOwnerControlsTest extends TestCase
{
    use RefreshDatabase;

    private function makeVideo(array $attrs): TutorialVideo
    {
        return TutorialVideo::create($attrs + [
            'title' => 'Test',
            'video_url' => '/videos/tutorials/x.mp4',
            'category' => 'shuruat',
            'is_published' => true,
            'show_public' => false,
            'controls_applied' => false,
        ]);
    }

    public function test_offline_video_is_force_unpublished_everywhere(): void
    {
        $v = $this->makeVideo(['slug' => 'offline-billing-tutorial', 'show_public' => true]);

        TutorialVideo::applyOwnerControls();
        $v->refresh();

        $this->assertFalse($v->is_published);
        $this->assertFalse($v->show_public);
        $this->assertTrue($v->controls_applied);
    }

    public function test_category_and_slug_gate_defaults(): void
    {
        $rows = [
            ['slug' => 'x-restaurant-video', 'category' => 'restaurant', 'expected' => 'restaurant'],
            ['slug' => 'x-riders-video', 'category' => 'riders', 'expected' => 'riders_enabled'],
            ['slug' => 'rider-live-tracking', 'category' => 'riders', 'expected' => 'rider_tracking_enabled'],
            ['slug' => 'x-deals-video', 'category' => 'deals', 'expected' => 'deals_enabled'],
            ['slug' => 'team-custom-access', 'category' => 'settings', 'expected' => 'custom_access_enabled'],
            ['slug' => 'qr-menu-tutorial', 'category' => 'restaurant', 'expected' => 'qr_menu_enabled'],
            ['slug' => 'day-close-tutorial', 'category' => 'reports', 'expected' => null], // core stays NULL
        ];
        foreach ($rows as $r) {
            $this->makeVideo(['slug' => $r['slug'], 'category' => $r['category']]);
        }

        TutorialVideo::applyOwnerControls();

        foreach ($rows as $r) {
            $this->assertSame(
                $r['expected'],
                TutorialVideo::where('slug', $r['slug'])->first()->required_feature,
                "gate for {$r['slug']}"
            );
        }
    }

    public function test_explicit_gate_from_seeding_migration_is_kept(): void
    {
        $this->makeVideo(['slug' => 'restaurant-special', 'category' => 'restaurant', 'required_feature' => 'kot']);

        TutorialVideo::applyOwnerControls();

        $this->assertSame('kot', TutorialVideo::where('slug', 'restaurant-special')->first()->required_feature);
    }

    public function test_owner_manual_enable_survives_later_self_heal_runs(): void
    {
        $v = $this->makeVideo(['slug' => 'offline-billing-tutorial']);
        TutorialVideo::applyOwnerControls();

        // Owner enables it from the admin panel later.
        $v->refresh();
        $v->update(['is_published' => true, 'show_public' => true]);

        TutorialVideo::applyOwnerControls(); // page-load self-heal runs again
        $v->refresh();

        $this->assertTrue($v->is_published, 'self-heal must not override the owner');
        $this->assertTrue($v->show_public);
    }

    public function test_offline_video_hidden_on_public_and_pos_pages(): void
    {
        $this->makeVideo(['slug' => 'offline-billing-tutorial', 'show_public' => true]);

        $this->get('/tutorials')->assertOk()->assertDontSee('offline-billing-tutorial');
    }
}
