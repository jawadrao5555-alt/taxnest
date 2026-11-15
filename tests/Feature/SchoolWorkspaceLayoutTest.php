<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

class SchoolWorkspaceLayoutTest extends TestCase
{
    private function renderWorkspace(string $locale = 'en', bool $owner = true): string
    {
        $this->withoutVite();
        app()->setLocale($locale);
        $company = new Company(['name' => 'Synthetic Academy', 'product_type' => 'fbrpos',
            'business_category' => 'education', 'pos_type' => 'education', 'pos_cashier_dayclose' => false]);
        $user = new User(['name' => 'Synthetic Staff', 'role' => $owner ? 'company_admin' : 'staff', 'pos_role' => 'pos_cashier']);
        auth('fbrpos')->setUser($user);
        return view('fbr-pos.school-fees.layout', ['company' => $company,
            'schoolCanConfigure' => $owner, 'slot' => new \Illuminate\Support\HtmlString('<h1>Shared page</h1>')])->render();
    }

    public function test_shared_pages_use_school_navigation_and_explicit_off_status(): void
    {
        $html = $this->renderWorkspace();
        $this->assertStringContainsString('tnMadadgar(', $html);
        foreach (['school-fiscal-status', 'FBR OFF', '/fbr-pos/team', '/fbr-pos/school-fees/printing',
            '/fbr-pos/billing', '/fbr-pos/school-fees/legacy-retail', 'Shared page', 'school-language'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['/fbr-pos/products', '/fbr-pos/deliveries', 'FBR ON', 'CERTIFIED', '/fbr-pos/create'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertStringContainsString('event.persisted', $html);
        $this->assertStringNotContainsString('sessionStorage.clear', $html);
    }

    public function test_manage_as_exit_is_visible_instead_of_blocked_logout(): void
    {
        session()->put('impersonation', ['readonly' => false, 'company_name' => 'Synthetic Academy']);
        $html = $this->renderWorkspace();
        $this->assertStringContainsString(route('saas.admin.impersonation.stop'), $html);
        $this->assertStringContainsString('Exit Manage as Company', $html);
        $this->assertStringNotContainsString('action="/fbr-pos/logout"', $html);
    }

    public function test_cashier_menu_does_not_expose_owner_settings_and_all_locales_resolve(): void
    {
        foreach (['en', 'rur', 'ur'] as $locale) {
            $html = $this->renderWorkspace($locale, false);
            $this->assertStringNotContainsString('school_workspace.', $html);
            $this->assertStringNotContainsString('/fbr-pos/team', $html);
            $this->assertStringNotContainsString('/fbr-pos/billing', $html);
            $this->assertStringContainsString('/fbr-pos/my-profile', $html);
        }
    }
    public function test_shared_team_billing_and_profile_render_through_school_component(): void
    {
        $this->renderWorkspace();
        $company = new Company(['name' => 'Synthetic Academy', 'product_type' => 'fbrpos',
            'business_category' => 'education', 'pos_type' => 'education']);
        auth('fbrpos')->user()->setRelation('company', $company);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        $cases = [
            ['fbr-pos.team', ['team' => collect(), 'teamPasswords' => [], 'branches' => collect(), 'quota' => []]],
            ['fbr-pos.billing', ['company' => $company, 'plans' => collect(), 'currentSubscription' => null,
                'billingHistory' => null, 'billingDocumentRoutes' => []]],
            ['fbr-pos.my-profile', ['user' => auth('fbrpos')->user()]],
        ];
        foreach ($cases as [$view, $data]) {
            $html = view($view, $data)->render();
            $this->assertStringContainsString('school-fiscal-status', $html);
            $this->assertStringNotContainsString('/fbr-pos/products', $html);
            $this->assertStringNotContainsString('FBR ON', $html);
        }
    }

}
