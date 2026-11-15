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
    public function test_browser_login_targets_credentials_form_not_language_picker(): void
    {
        $source=file_get_contents(base_path('scripts/erp-demo-browser.mjs'));
        $this->assertStringContainsString('form[action$="/health/login"] button[type=submit]', $source);
        $template=file_get_contents(resource_path('views/health/auth/login.blade.php'));
        $this->assertStringContainsString("url('/health/guest-language')", $template);
        $this->assertStringContainsString("url('/health/login')", $template);
    }

    public function test_browser_proof_rejects_redirected_allowed_pages(): void
    {
        $source=file_get_contents(base_path('scripts/erp-demo-browser.mjs'));
        $this->assertStringContainsString('assert.equal(page.url(),baseUrl+allowed', $source);
    }
    public function test_native_fixture_explicitly_checks_linked_clinician(): void
    {
        $source=file_get_contents(base_path('scripts/erp-demo-fixture.php'));
        $this->assertStringContainsString('$clinician->user_id !== $doctorLogin->id', $source);
        $this->assertStringContainsString("active clinician must link to demo doctor login", $source);
    }

}
