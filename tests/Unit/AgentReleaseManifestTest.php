<?php

namespace Tests\Unit;

use App\Services\AgentReleaseManifest;
use Tests\TestCase;

class AgentReleaseManifestTest extends TestCase
{
    private function releaseAssets(): array
    {
        $tag = 'v1.13.5';
        $prefix = "https://github.com/jawadrao5555-alt/nestpos-releases/releases/download/{$tag}/";
        return [
            [
                'name' => 'TaxNest-PRA-Agent-Windows.zip',
                'url' => $prefix . 'TaxNest-PRA-Agent-Windows.zip',
                'size' => 1024,
                'digest' => 'sha256:' . str_repeat('a', 64),
            ],
            [
                'name' => 'TaxNest-Agent-Setup-1.13.5.exe',
                'url' => $prefix . 'TaxNest-Agent-Setup-1.13.5.exe',
                'size' => 2048,
                'digest' => 'sha256:' . str_repeat('b', 64),
            ],
            [
                'name' => AgentReleaseManifest::ASSET_NAME,
                'url' => $prefix . AgentReleaseManifest::ASSET_NAME,
                'size' => 300,
                'digest' => null,
            ],
        ];
    }

    private function manifest(): array
    {
        return [
            'schema_version' => 1,
            'product' => AgentReleaseManifest::PRODUCT,
            'version' => '1.13.5',
            'source_sha' => str_repeat('c', 40),
            'build_sha' => str_repeat('d', 40),
            'compatibility' => ['min_agent_version' => '1.3.0', 'max_agent_version' => '2.99.99'],
            'assets' => [
                ['name' => 'TaxNest-PRA-Agent-Windows.zip', 'size' => 1024, 'sha256' => str_repeat('a', 64)],
                ['name' => 'TaxNest-Agent-Setup-1.13.5.exe', 'size' => 2048, 'sha256' => str_repeat('b', 64)],
            ],
        ];
    }

    public function test_accepts_only_the_explicit_canonical_asset_inventory(): void
    {
        $validated = AgentReleaseManifest::validate(
            $this->manifest(),
            'v1.13.5',
            'jawadrao5555-alt/nestpos-releases',
            $this->releaseAssets()
        );

        $this->assertNotNull($validated);
        $this->assertSame('TaxNest-PRA-Agent-Windows.zip', $validated['zip']['name']);
        $this->assertSame(str_repeat('c', 40), $validated['source_sha']);
        $this->assertSame('1.3.0', $validated['compatibility']['min_agent_version']);
    }

    public function test_refuses_missing_hash_mismatched_size_and_an_extra_zip(): void
    {
        $badHash = $this->manifest();
        $badHash['assets'][0]['sha256'] = 'not-a-hash';
        $this->assertNull(AgentReleaseManifest::validate($badHash, 'v1.13.5', 'jawadrao5555-alt/nestpos-releases', $this->releaseAssets()));

        $assets = $this->releaseAssets();
        $assets[0]['size'] = 999;
        $this->assertNull(AgentReleaseManifest::validate($this->manifest(), 'v1.13.5', 'jawadrao5555-alt/nestpos-releases', $assets));

        $assets = $this->releaseAssets();
        $assets[] = ['name' => 'largest-untrusted.zip', 'url' => 'https://github.com/jawadrao5555-alt/nestpos-releases/releases/download/v1.13.5/largest-untrusted.zip', 'size' => 999999, 'digest' => null];
        $this->assertNull(AgentReleaseManifest::validate($this->manifest(), 'v1.13.5', 'jawadrao5555-alt/nestpos-releases', $assets));
    }
}