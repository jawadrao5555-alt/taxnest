<?php

namespace Tests\Feature;

use App\Http\Middleware\CompanyIsolation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DiFbrSetupOnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('company_status');
            $t->string('product_type'); $t->boolean('onboarding_completed');
            $t->boolean('is_internal_account')->default(false);
            $t->softDeletes(); $t->timestamps();
        });
        foreach (['First tenant', 'Second tenant'] as $name) {
            Company::forceCreate(['name' => $name, 'company_status' => 'active',
                'product_type' => 'di', 'onboarding_completed' => false]);
        }
    }

    public function test_new_tenants_can_use_setup_json_endpoints_without_crossing_company_context(): void
    {
        foreach ([1, 2] as $companyId) {
            $this->actingAs((new User())->forceFill([
                'id' => $companyId, 'company_id' => $companyId,
                'role' => 'company_admin', 'is_active' => true,
            ]));
            foreach (['company/fbr-settings-ajax', 'company/test-connection', 'company/sandbox-test/config', 'company/sandbox-test/token'] as $path) {
                $request = Request::create('/'.$path, 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
                $response = (new CompanyIsolation())->handle($request, function () use ($companyId) {
                    $this->assertSame($companyId, app('currentCompanyId'));
                    return new Response('setup reached');
                });
                $this->assertSame('setup reached', $response->getContent(), $path);
            }
            $response = (new CompanyIsolation())->handle(Request::create('/mis'), fn () => new Response('unexpected'));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringEndsWith('/onboarding', $response->headers->get('Location'));
        }
    }
}
