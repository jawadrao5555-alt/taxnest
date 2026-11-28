<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Enterprise360MonitorPreviewTest extends TestCase
{
    public function test_synthetic_preview_executes_without_a_database(): void
    {
        self::assertSame(0, Artisan::call('enterprise360:preview-monitor'));

        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial', $result['coverage']);
        self::assertSame('82500', $result['fresh_subtotal_paisa']);
        self::assertSame(['fresh', 'unknown'], array_column($result['installations'], 'status'));
        self::assertNull($result['net_paisa']);
    }

    public function test_preview_is_denied_outside_local_and_testing(): void
    {
        $this->app->instance('env', 'production');

        $this->artisan('enterprise360:preview-monitor')
            ->expectsOutput('The synthetic Monitor preview is available in local and testing environments.')
            ->assertFailed();
    }
}
