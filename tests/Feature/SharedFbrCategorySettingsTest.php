<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Middleware\SchoolWorkspace;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedFbrCategorySettingsTest extends TestCase
{
    use RefreshDatabase;

    public static function categories(): array
    {
        return array_map(fn ($category) => [$category], ['education', 'hospital', 'clinic', 'retail', 'pharmacy', 'future-category']);
    }

    private function company(string $category): Company
    {
        $company = Company::create([
            'name' => 'Synthetic category company', 'ntn' => 'SYN-'.Str::random(8),
            'product_type' => 'fbrpos', 'pos_type' => $category, 'business_category' => $category,
            'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => false,
            'fbr_connection_mode' => 'cloud', 'fbr_pos_environment' => 'sandbox',
        ]);
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($user, 'fbrpos');
        app()->instance('currentCompanyId', $company->id);
        $this->withoutVite();
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        return $company;
    }

    private function request(array $data = [], string $method = 'POST'): Request
    {
        $request = Request::create('/fbr-pos/settings', $method, $data);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return $request;
    }

    #[DataProvider('categories')]
    public function test_every_category_reaches_the_same_settings_and_persists_the_same_flow(string $category): void
    {
        $company = $this->company($category);
        $other = Company::create(['name' => 'Other synthetic company', 'ntn' => 'SYN-'.Str::random(8)]);
        $otherBefore = $other->getAttributes();
        $controller = app(FbrPosController::class);
        $request = $this->request([], 'GET');
        $response = (new SchoolWorkspace)->handle($request, function () use ($controller, $request) {
            $view = $controller->fbrSettings($request);
            $this->assertSame('fbr-pos.settings', $view->name());
            return response($view->render());
        });
        $this->assertSame(200, $response->getStatusCode());
        foreach (['Submission Mode', 'FBR POS Configuration', 'Test Connection', 'fbr_pos_id', 'fbr_connection_mode'] as $label) {
            $this->assertStringContainsString($label, $response->getContent());
        }

        $controller->fbrSettings($this->request(['integration_toggle' => 'start']));
        $this->assertFalse($company->fresh()->fbr_reporting_enabled);
        $controller->fbrSettings($this->request([
            'fbr_pos_environment' => 'sandbox', 'fbr_connection_mode' => 'fiscal_device', 'fbr_pos_id' => '123456',
            'fbr_pos_token' => '11111111-2222-4333-8444-555555555555', 'fbr_access_code' => 'SYNTHETIC-CODE',
        ]));
        $company->refresh();
        $this->assertTrue($company->fbr_reporting_enabled);
        $this->assertTrue($company->agent_enabled);
        $this->assertNotEmpty($company->agent_api_key);
        $savedKey = $company->agent_api_key;
        $savedToken = $company->fbr_pos_token;
        $this->assertSame('SYNTHETIC-CODE', Crypt::decryptString($company->fbr_access_code));

        $controller->fbrSettings($this->request(['integration_toggle' => 'off']));
        $this->assertFalse($company->fresh()->fbr_reporting_enabled);
        $controller->fbrSettings($this->request(['fbr_pos_environment' => 'sandbox', 'fbr_connection_mode' => 'cloud', 'fbr_pos_token' => '', 'fbr_access_code' => '']));
        $company->refresh();
        $this->assertSame($savedKey, $company->agent_api_key);
        $this->assertSame($savedToken, $company->fbr_pos_token);
        $this->assertSame('cloud', $company->fbr_connection_mode);
        $controller->fbrSettings($this->request(['integration_toggle' => 'on']));
        $this->assertTrue($company->fresh()->fbr_reporting_enabled);
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
    }

    public function test_fiscal_device_connection_check_uses_agent_state_without_a_cloud_token(): void
    {
        $company = $this->company('education');
        $company->update(['fbr_connection_mode' => 'fiscal_device', 'agent_enabled' => true, 'agent_last_seen' => now()]);
        $response = app(FbrPosController::class)->testConnection();
        $this->assertTrue($response->getData(true)['success']);
        $company->update(['agent_last_seen' => now()->subDay()]);
        $this->assertFalse(app(FbrPosController::class)->testConnection()->getData(true)['success']);
    }

    public function test_cashier_cannot_change_company_fiscal_settings(): void
    {
        $company = $this->company('hospital');
        auth('fbrpos')->user()->forceFill(['role' => 'employee', 'pos_role' => 'pos_cashier'])->save();
        app(FbrPosController::class)->fbrSettings($this->request(['integration_toggle' => 'on']));
        $this->assertFalse($company->fresh()->fbr_reporting_enabled);
    }

    public function test_readonly_owner_cannot_save_shared_settings(): void
    {
        $this->company('hospital');
        session()->put('impersonation.readonly', true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(FbrPosController::class)->fbrSettings($this->request(['fbr_pos_environment' => 'production']));
    }
}
