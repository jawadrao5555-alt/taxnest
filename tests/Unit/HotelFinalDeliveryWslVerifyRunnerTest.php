<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Static regression for the Hotel WSL gate MariaDB probe defect:
 * default `mysql -h127.0.0.1` hits system 3306 unix_socket/localhost and
 * skips hotel concurrency/isolation even when workspace MariaDB on 3307 is up.
 */
class HotelFinalDeliveryWslVerifyRunnerTest extends TestCase
{
    private function runner(): string
    {
        return file_get_contents(base_path('scripts/hotel-final-delivery-wsl-verify.sh'));
    }

    #[Test]
    public function runner_probes_loopback_mariadb_on_3306_and_3307_over_tcp(): void
    {
        $src = $this->runner();
        $this->assertStringContainsString('--protocol=tcp', $src);
        $this->assertStringContainsString('3307', $src);
        $this->assertStringContainsString('hotel-mariadb-concurrency-check.sh', $src);
        $this->assertStringContainsString('hotel-mariadb-isolation-board-check.sh', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/mysql -h127\.0\.0\.1 -utaxnest_dev -ptaxnest_local_dev_only -e .SELECT 1./',
            $src
        );
    }

    #[Test]
    public function runner_stays_loopback_and_does_not_call_fiscal_endpoints(): void
    {
        $src = $this->runner();
        $this->assertStringContainsString('127.0.0.1', $src);
        $this->assertStringContainsString('cloud-local-hotel-smoke.mjs', $src);
        $this->assertStringNotContainsString('taxnest.pk', $src);
        $this->assertStringNotContainsString('live-screen-smoke', $src);
    }
}
