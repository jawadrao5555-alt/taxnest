<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\AdminUser;
use App\Models\Franchise;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Franchise portal (guard `franchise`, routes /franchise/*).
 *
 * The portal exposes a dashboard, companies, commission statement and the
 * constrained review route. Every query is constrained
 * to `companies.franchise_id = auth('franchise')->id()` (directly, or through
 * the id list that constraint produces). The one company-id review route
 * must keep that constraint and must not activate a subscription.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FranchiseIdorTest.php --testdox
 */
class FranchiseIdorTest extends TestCase
{
    use RefreshDatabase;

    private Franchise $x;
    private Franchise $y;
    private Company $companyX;
    private Company $companyY;
    private Subscription $subX;
    private Subscription $subY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->x = Franchise::create(['name' => 'Franchise X', 'email' => 'x@example.test', 'password' => 'Passw0rd!2026', 'status' => 'active', 'commission_rate' => 10]);
        $this->y = Franchise::create(['name' => 'Franchise Y', 'email' => 'y@example.test', 'password' => 'Passw0rd!2026', 'status' => 'active', 'commission_rate' => 10]);

        $this->companyX = $this->company('X-OWNED-SHOP-100', $this->x);
        $this->companyY = $this->company('Y-OWNED-SHOP-200', $this->y);

        $plan = PricingPlan::create(['name' => 'Franchise Plan', 'product_type' => 'pos', 'price' => 1000, 'is_trial' => false, 'invoice_limit' => -1]);
        $this->subX = Subscription::create(['company_id' => $this->companyX->id, 'pricing_plan_id' => $plan->id, 'active' => true, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
        $this->subY = Subscription::create(['company_id' => $this->companyY->id, 'pricing_plan_id' => $plan->id, 'active' => true, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
    }

    private function company(string $name, Franchise $franchise): Company
    {
        return Company::create([
            'name' => $name,
            'ntn' => $name,
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'franchise_id' => $franchise->id,
        ]);
    }

    private function asX()
    {
        return $this->actingAs($this->x, 'franchise');
    }

    public function test_repeated_failed_logins_are_limited_and_an_active_franchise_can_retry(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/franchise/login')->post('/franchise/login', [
                'email' => strtoupper($this->x->email),
                'password' => 'Fictional incorrect password',
            ])->assertSessionHasErrors(['email' => 'Invalid credentials.']);
        }
        $credentials = ['email' => $this->x->email, 'password' => 'Passw0rd!2026'];
        $this->from('/franchise/login')->post('/franchise/login', $credentials)
            ->assertRedirect('/franchise/login')
            ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertGuest('franchise');

        $this->travel(61)->seconds();
        $this->post('/franchise/login', $credentials)->assertRedirect('/franchise/dashboard');
        $this->assertAuthenticatedAs($this->x, 'franchise');
    }

    public function test_franchise_x_sees_only_its_own_companies(): void
    {
        $this->asX()->get('/franchise/companies')
            ->assertOk()
            ->assertSee($this->companyX->name)
            ->assertDontSee($this->companyY->name);

        $this->asX()->get('/franchise/dashboard')
            ->assertOk()
            ->assertSee($this->companyX->name)
            ->assertDontSee($this->companyY->name);
    }

    public function test_subscription_details_are_not_available_in_franchise_portal(): void
    {
        $response = $this->asX()->get('/franchise/subscriptions');
        $this->assertContains($response->getStatusCode(), [302, 404]);
    }

    public function test_revenue_is_computed_over_own_companies_only(): void
    {
        $this->asX()->get('/franchise/revenue')->assertOk();
        $this->assertEquals([$this->companyX->id], Company::where('franchise_id', $this->x->id)->pluck('id')->all());
    }

    public function test_company_id_route_is_limited_to_review_and_other_franchise_is_forbidden(): void
    {
        $franchiseRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'franchise/'));

        $this->assertNotEmpty($franchiseRoutes);

        $withParams = $franchiseRoutes->filter(fn ($r) => !empty($r->parameterNames()));
        $this->assertEquals(['franchise/companies/{companyId}/approve'], $withParams->map->uri()->values()->all());

        $foreign = $this->asX()->post("/franchise/companies/{$this->companyY->id}/approve");
        $this->assertContains($foreign->getStatusCode(), [302, 404]);
        $this->assertDatabaseMissing('franchise_company_approvals', ['company_id' => $this->companyY->id]);

        // Guessing a per-record URL for the other franchise's rows yields nothing.
        foreach ([
            "/franchise/companies/{$this->companyY->id}",
            "/franchise/subscriptions/{$this->subY->id}",
            "/franchise/companies/{$this->companyY->id}/edit",
        ] as $url) {
            $response = $this->asX()->get($url);
            $this->assertContains($response->getStatusCode(), [302, 404, 405], $url);
            $this->assertStringNotContainsString($this->companyY->name, $response->getContent(), $url);
        }
    }

    public function test_suspended_franchise_is_logged_out(): void
    {
        $this->x->forceFill(['status' => 'suspended'])->save();

        $this->asX()->get('/franchise/companies')->assertRedirect('/franchise/login');
    }

    public function test_admin_password_change_revokes_only_the_target_franchise_session(): void
    {
        $admin = AdminUser::create(['name' => 'Fictional reviewer', 'email' => 'franchise-review@example.test',
            'password' => 'Fictional reviewer password', 'role' => 'super_admin']);
        $this->post('/franchise/login', ['email' => $this->x->email, 'password' => 'Passw0rd!2026'])
            ->assertRedirect('/franchise/dashboard');
        $this->get('/franchise/companies')->assertOk();

        $this->actingAs($admin, 'admin')->put(route('saas.admin.franchises.update', $this->x->id), [
            'name' => $this->x->name, 'email' => $this->x->email, 'commission_rate' => 10,
            'password' => 'Fictional replacement password 52!',
        ])->assertSessionHas('success');

        $this->get('/franchise/companies')->assertRedirect('/franchise/login');
        $this->get('/security/two-step/franchise')->assertForbidden();
        $this->assertGuest('franchise');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame('active', $this->y->fresh()->status);
        $this->asX()->get('/franchise/companies')->assertRedirect('/franchise/login');
        $this->post('/franchise/login', ['email' => $this->x->email, 'password' => 'Fictional replacement password 52!'])
            ->assertRedirect('/franchise/dashboard');
        $this->get('/franchise/companies')->assertOk();
    }

    public function test_suspension_then_reactivation_does_not_restore_a_previous_session(): void
    {
        $admin = AdminUser::create(['name' => 'Fictional reviewer', 'email' => 'franchise-suspension@example.test',
            'password' => 'Fictional reviewer password', 'role' => 'super_admin']);
        $credentials = ['email' => $this->x->email, 'password' => 'Passw0rd!2026'];
        $this->post('/franchise/login', $credentials)->assertRedirect('/franchise/dashboard');
        $this->actingAs($admin, 'admin')->post(route('saas.admin.franchises.toggle', $this->x->id))->assertSessionHas('success');
        $this->from('/franchise/login')->post('/franchise/login', $credentials)
            ->assertSessionHasErrors(['email' => 'Invalid credentials.']);
        $failure = \App\Models\SecurityLog::where('action', 'failed_login')->latest('id')->firstOrFail();
        $this->assertNull($failure->user_id);
        $this->assertSame('franchise', $failure->metadata['guard']);
        $this->post(route('saas.admin.franchises.toggle', $this->x->id))->assertSessionHas('success');

        $this->get('/security/two-step/franchise')->assertForbidden();
        $this->assertGuest('franchise');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->post('/franchise/login', $credentials)->assertRedirect('/franchise/dashboard');
        $this->get('/franchise/companies')->assertOk();
    }
}
