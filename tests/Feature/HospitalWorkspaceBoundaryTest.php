<?php

namespace Tests\Feature;

use App\Http\Middleware\SchoolWorkspace;
use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Synthetic route-boundary tests, independent of the School UI's hidden controls. */
class HospitalWorkspaceBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type');
            $t->string('pos_type'); $t->string('business_category');
            $t->boolean('fbr_reporting_enabled')->default(true);
            $t->softDeletes(); $t->timestamps();
        });
        Company::withoutEvents(fn () => Company::create([
            'id' => 1, 'name' => 'Synthetic Hospital', 'product_type' => 'fbrpos',
            'pos_type' => 'clinic', 'business_category' => 'clinic',
        ]));
        app()->instance('currentCompanyId', 1);
    }

    public static function prohibitedMutations(): array
    {
        return array_map(fn ($path) => [$path], [
            'store', 'api/toggle-universal', 'api/toggle-auto-kot',
            'settings/inventory-toggle', 'settings/kot-reprint-toggle',
            'settings/restock-toggle', 'settings/quick-type',
            'receipt-settings', 'printer-settings', 'business-profile',
            'transactions/19/retry-fbr', 'transactions/19/update-and-retry',
            'fail-queue/retry-all', 'fail-queue/19/reconcile',

            'products', 'purchases', 'deals', 'delivery', 'wholesale', 'school-fees', 'school-fees/settings/fee-head',
        ]);
    }

    #[DataProvider('prohibitedMutations')]
    public function test_school_cannot_reach_retail_mutations_through_direct_urls(string $path): void
    {
        $reached = false;
        try {
            (new SchoolWorkspace)->handle(Request::create('/fbr-pos/'.$path, 'POST'), function () use (&$reached) {
                $reached = true;
                return response('mutated');
            });
            $this->fail('Hospital mutation must be blocked before its controller.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertFalse($reached);
        $this->assertSame('clinic', Company::find(1)->business_category);
    }

    public function test_stale_retail_navigation_redirects_without_a_cacheable_response(): void
    {
        foreach (['create', 'products', 'transactions/19', 'reports', 'fail-queue'] as $path) {
            $r = (new SchoolWorkspace)->handle(Request::create('/fbr-pos/'.$path), fn () => response('retail'));
            $this->assertSame(302, $r->getStatusCode());
            $this->assertStringEndsWith('/fbr-pos/hospital', $r->headers->get('Location'));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        }
    }

    public function test_school_shared_settings_redirect_to_school_workspace(): void
    {
        foreach (['customize', 'business-profile', 'receipt-settings', 'printer-settings', 'branches'] as $path) {
            $r = (new SchoolWorkspace)->handle(Request::create('/fbr-pos/'.$path), fn () => response('retail settings'));
            $this->assertStringEndsWith('/fbr-pos/hospital/settings', $r->headers->get('Location'));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        }
    }

    public function test_school_supported_routes_are_available_and_not_cached(): void
    {
        foreach (['settings', 'settings/identities', 'test-connection', 'desktop/agent-pairing', 'hospital', 'hospital/settings', 'hospital/legacy-retail/19', 'team', 'team/10', 'billing', 'my-profile', 'api/boot-check'] as $path) {
            $r = (new SchoolWorkspace)->handle(Request::create('/fbr-pos/'.$path), fn () => response('supported'));
            $this->assertSame('supported', $r->getContent());
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        }
    }

    public function test_existing_retail_and_pra_settings_are_not_intercepted(): void
    {
        foreach ([['fbrpos', 'retail'], ['pos', 'clinic']] as [$product, $category]) {
            Company::find(1)->forceFill(['product_type' => $product, 'pos_type' => $category, 'business_category' => $category])->saveQuietly();
            $r = (new SchoolWorkspace)->handle(Request::create('/fbr-pos/settings/inventory-toggle', 'POST'), fn () => response('existing behavior'));
            $this->assertSame('existing behavior', $r->getContent());
        }
    }

    public function test_route_group_actually_installs_the_school_boundary(): void
    {
        foreach (['fbrpos.store', 'fbrpos.settings.inventory-toggle', 'fbrpos.retryFbr', 'fbrpos.integration.decision', 'fbrpos.hospital.receipt'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains(SchoolWorkspace::class, $route->gatherMiddleware(), $name);
        }
    }

    public function test_configured_hospital_fiscal_flags_enable_only_the_fbr_agent_path(): void
    {
        $hospital = Company::find(1)->forceFill([
            'fbr_reporting_enabled' => true, 'agent_enabled' => true,
            'fbr_pos_enabled' => true, 'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_id' => '9999',
        ]);
        $this->assertTrue($hospital->fbr_reporting_enabled);
        $this->assertTrue($hospital->fbrPosIntegrationConfigured());
        $this->assertTrue($hospital->agentServesFbr());
        $this->assertFalse($hospital->agentHandlesPra());
        $this->assertFalse($hospital->fbrIntegrationUndecided());
    }
}
