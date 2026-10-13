<?php

namespace Tests\Feature;

use App\Services\EligibleDeploymentPullRequestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeploymentEligibilityRecoveryTest extends TestCase
{
    private const BASE = 'https://api.github.com/repos/jawadrao5555-alt/taxnest';

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_check_rate_limit_is_visible_and_scan_stops_until_retry_window(): void
    {
        Http::fake([
            self::BASE.'/pulls/55' => Http::response($this->pr(), 200),
            self::BASE.'/pulls/56' => Http::response($this->pr(56), 200),
            self::BASE.'/pulls*' => Http::response([$this->pr(), $this->pr(56)], 200),
            self::BASE.'/commits/*' => Http::response(['message' => 'private upstream text'], 403, ['Retry-After' => '300']),
        ]);
        $service = new EligibleDeploymentPullRequestService;
        $this->assertSame([], $service->eligible());
        $this->assertNotNull($service->availabilityWarning());
        $this->assertStringContainsString('HTTP 403', $service->availabilityWarning());
        $this->assertStringNotContainsString('private upstream text', $service->availabilityWarning());
        Http::assertSentCount(2);
        $this->travel(2)->minutes();
        $next = new EligibleDeploymentPullRequestService;
        $this->assertSame([], $next->eligible());
        $this->assertStringContainsString('HTTP 403', $next->availabilityWarning());
        Http::assertSentCount(2);
        $this->travel(4)->minutes();
        $next->eligible();
        Http::assertSentCount(4);
    }

    public function test_healthy_list_reuses_binding_and_short_display_cache(): void
    {
        Http::fake([
            self::BASE.'/pulls/55' => Http::response($this->pr(), 200),
            self::BASE.'/pulls/56' => Http::response($this->pr(56), 200),
            self::BASE.'/pulls*' => Http::response([$this->pr()], 200),
            self::BASE.'/commits/*' => Http::response($this->green(), 200),
        ]);
        $service = new EligibleDeploymentPullRequestService;
        $this->assertSame(55, $service->eligible()[0]['number']);
        $this->assertNull($service->availabilityWarning());
        $this->assertSame(55, (new EligibleDeploymentPullRequestService)->eligible()[0]['number']);
        Http::assertSentCount(2);
        $this->travel(61)->seconds();
        $service->eligible();
        Http::assertSentCount(4);
    }

    public function test_cached_display_never_authorizes_changed_or_failed_head(): void
    {
        $head = self::SHA;
        Http::fake(function ($request) use (&$head) {
            if (str_contains($request->url(), '/commits/')) {
                return Http::response($head === self::SHA ? $this->green() : ['check_runs' => []], 200);
            }

            return Http::response(str_contains($request->url(), '/pulls/55')
                ? array_replace_recursive($this->pr(), ['head' => ['sha' => $head]])
                : [$this->pr()], 200);
        });
        $service = new EligibleDeploymentPullRequestService;
        $this->assertSame(self::SHA, $service->eligible()[0]['head_sha']);
        $head = str_repeat('b', 40);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('validate check is not green');
        $service->resolve(55);
    }

    public function test_non_green_pr_is_excluded_without_upstream_warning(): void
    {
        Http::fake([
            self::BASE.'/pulls/55' => Http::response($this->pr(), 200),
            self::BASE.'/pulls/56' => Http::response($this->pr(56), 200),
            self::BASE.'/pulls*' => Http::response([$this->pr()], 200),
            self::BASE.'/commits/*' => Http::response(['check_runs' => [['name' => 'validate', 'status' => 'completed', 'conclusion' => 'failure']]], 200),
        ]);
        $service = new EligibleDeploymentPullRequestService;
        $this->assertSame([], $service->eligible());
        $this->assertNull($service->availabilityWarning());
    }

    public function test_pr_returns_after_github_recovers_and_display_cache_expires(): void
    {
        Http::fake([
            self::BASE.'/pulls*' => Http::response([$this->pr()], 200),
            self::BASE.'/commits/*' => Http::sequence()->push([], 403)->push($this->green(), 200),
        ]);
        $service = new EligibleDeploymentPullRequestService;
        $this->assertSame([], $service->eligible());
        $this->assertNotNull($service->availabilityWarning());
        $this->travel(61)->seconds();
        $this->assertSame(55, $service->eligible()[0]['number']);
        $this->assertNull($service->availabilityWarning());
        Http::assertSentCount(4);
    }

    public function test_connection_failure_is_visible_and_does_not_create_fake_eligibility(): void
    {
        Http::fake([
            self::BASE.'/pulls/55' => Http::response($this->pr(), 200),
            self::BASE.'/pulls/56' => Http::response($this->pr(56), 200),
            self::BASE.'/pulls*' => Http::response([$this->pr()], 200),
            self::BASE.'/commits/*' => Http::failedConnection(),
        ]);
        $service = new EligibleDeploymentPullRequestService;
        $this->assertSame([], $service->eligible());
        $this->assertNotNull($service->availabilityWarning());
        $this->assertStringContainsString('temporarily unavailable', $service->availabilityWarning());
    }

    private function green(): array
    {
        return ['check_runs' => [['name' => 'validate', 'status' => 'completed', 'conclusion' => 'success']]];
    }

    private function pr(int $number = 55): array
    {
        return ['number' => $number, 'title' => 'Fictional release', 'state' => 'open', 'draft' => false,
            'html_url' => 'https://github.com/jawadrao5555-alt/taxnest/pull/'.$number,
            'base' => ['ref' => 'main', 'repo' => ['full_name' => 'jawadrao5555-alt/taxnest']],
            'head' => ['sha' => self::SHA, 'ref' => 'cursor/fixture', 'repo' => ['full_name' => 'jawadrao5555-alt/taxnest']]];
    }
}
