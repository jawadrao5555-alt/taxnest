<?php

namespace Tests\Feature;

use App\Models\TutorialVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorialAccessibilityMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        file_put_contents(public_path('videos/synthetic-accessibility-test.mp4'), 'synthetic fixture, not a real video');
    }

    protected function tearDown(): void
    {
        @unlink(public_path('videos/synthetic-accessibility-test.mp4'));
        parent::tearDown();
    }

    public function test_public_search_never_reveals_private_or_superseded_tutorials(): void
    {
        foreach (['public', 'private', 'superseded'] as $kind) {
            TutorialVideo::create(['slug' => 'synthetic-'.$kind, 'title' => 'SYN FIND '.$kind, 'product' => 'nestpos', 'category' => 'billing', 'video_url' => '/videos/synthetic-accessibility-test.mp4', 'is_published' => true, 'show_public' => $kind !== 'private', 'superseded' => $kind === 'superseded', 'controls_applied' => true]);
        }
        $this->get('/tutorials?q=SYN%20FIND')->assertOk()->assertSee('SYN FIND public')->assertDontSee('SYN FIND private')->assertDontSee('SYN FIND superseded')->assertSee('aria-label="SYN FIND public"', false)->assertSee('tutorial-search')->assertSee('Captions abhi faraham nahin kiye gaye.');
        $this->get('/tutorials?q=unmatched-synthetic-zz')->assertOk()->assertDontSee('SYN FIND public');
    }

    public function test_release_admin_metadata_keeps_publication_and_audience_unchanged(): void
    {
        $admin = \App\Models\AdminUser::create(['name' => 'Synthetic release owner', 'email' => 'synthetic-release@example.test', 'password' => 'unused', 'role' => 'super_admin']);
        $v = TutorialVideo::create(['slug' => 'synthetic-admin-metadata', 'title' => 'Synthetic private tutorial', 'product' => 'nestpos', 'category' => 'billing', 'video_url' => '/videos/synthetic-accessibility-test.mp4', 'is_published' => false, 'show_public' => false, 'min_role' => 'admin', 'controls_applied' => true]);
        $this->actingAs($admin, 'admin')->post('/admin/tutorial-videos/'.$v->id.'/metadata', ['transcript' => 'Synthetic transcript', 'release_version' => 'test-only', 'superseded' => true])->assertRedirect();
        $v->refresh();
        $this->assertSame('Synthetic transcript', $v->transcript);
        $this->assertFalse($v->is_published);
        $this->assertFalse($v->show_public);
        $this->assertSame('admin', $v->min_role);
        $this->actingAs($admin, 'admin')->postJson('/admin/tutorial-videos/'.$v->id.'/metadata', ['caption_path' => 'https://external.example/video.vtt', 'superseded' => false])->assertUnprocessable();
    }

    public function test_metadata_view_escapes_transcript_and_preserves_missing_caption_truth(): void
    {
        $v = new TutorialVideo(['title' => 'Synthetic', 'transcript' => '<script>never run</script>', 'release_version' => 'test-build', 'reviewed_on' => '2026-01-01']);
        $html = view('tutorials._metadata', compact('v'))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('test-build', $html);
        $this->assertStringContainsString('Captions pending',$html);
    }
}
