<?php
namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpDemoLaneGuardTest extends TestCase
{
    public function test_native_demo_bootstrap_refuses_missing_guard_without_loading_application(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/erp-demo-fixture.php'), '/tmp/not-demo', '/tmp/not-socket'], base_path(), ['RC_SAFE_RUN'=>'0']);
        $process->run();
        $this->assertSame(2,$process->getExitCode());
        $this->assertStringContainsString('guarded state and dedicated native socket required',$process->getErrorOutput());
    }
    public function test_standalone_fixture_identity_is_not_accepted_as_erp_demo(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/erp-demo-fixture.php'), '/tmp/taxnest-rc-browser-1000/safe-runtime/browser-state', '/tmp/taxnest-rc-mariadb-browser-1000/run/mariadb.sock'], base_path());
        $process->run();
        $this->assertSame(2,$process->getExitCode());
        $this->assertStringContainsString('ERP DEMO REFUSED',$process->getErrorOutput());
    }
}
