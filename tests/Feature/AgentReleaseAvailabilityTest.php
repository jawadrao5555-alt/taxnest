<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentManagementController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentReleaseAvailabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('taxnest_agent_latest_release');
        Http::clearResolvedInstance('http');
        parent::tearDown();
    }

    public function test_current_release_without_manifest_is_not_advertised_as_an_update(): void
    {
        Cache::forget('taxnest_agent_latest_release');
        Http::fake([
            'https://api.github.com/repos/jawadrao5555-alt/nestpos-releases/releases/latest' => Http::response([
                'tag_name' => 'v1.13.5',
                'assets' => [[
                    'name' => 'TaxNest-PRA-Agent-Windows.zip',
                    'browser_download_url' => 'https://github.com/jawadrao5555-alt/nestpos-releases/releases/download/v1.13.5/TaxNest-PRA-Agent-Windows.zip',
                    'size' => 1024,
                ]],
            ]),
            '*' => Http::response([], 404),
        ]);

        $release = AgentManagementController::latestReleaseInfo();

        $this->assertFalse($release['available']);
        $this->assertSame('v1.13.5', $release['tag']);
        $this->assertSame('manifest_missing_or_ambiguous', $release['reason']);
        $this->assertSame([], $release['assets']);
    }

    public function test_missing_manifest_returns_an_honest_download_error_for_old_and_new_clients(): void
    {
        Cache::put('taxnest_agent_latest_release', [
            'tag' => 'v1.13.5',
            'assets' => [],
            'available' => false,
            'reason' => 'manifest_missing_or_ambiguous',
        ], 600);

        $response = $this->get(route('public.agent.download', ['type' => 'exe']));

        $response->assertStatus(503)
            ->assertJsonPath('reason', 'manifest_missing_or_ambiguous')
            ->assertJsonPath('error', 'Agent release is temporarily unavailable because its canonical manifest could not be verified.');
    }

    public function test_existing_download_and_admin_status_surfaces_explain_verification_pause_and_health_dimensions(): void
    {
        $downloads = (string) file_get_contents(resource_path('views/downloads.blade.php'));
        $admin = (string) file_get_contents(resource_path('views/saas-admin/companies/show.blade.php'));

        $this->assertStringContainsString('data-agent-release-unavailable', $downloads);
        $this->assertStringContainsString('Existing agents remain supported and will not self-update', $downloads);
        $this->assertStringContainsString('data-agent-release-unavailable', $admin);
        $this->assertStringContainsString('Printer Health', $admin);
        $this->assertStringContainsString('Fiscal Connectivity', $admin);
        $this->assertStringContainsString('Callback Queue', $admin);
    }
}