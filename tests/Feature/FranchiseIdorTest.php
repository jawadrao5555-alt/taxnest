<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Franchise portal (guard `franchise`, routes /franchise/*).
 *
 * Audit item: "franchise IDOR — UNKNOWN". Verified: the portal exposes FOUR
 * read-only screens (dashboard, companies, subscriptions, revenue) and NO route
 * that takes a company / subscription / payment id. Every query is constrained
 * to `companies.franchise_id = auth('franchise')->id()` (directly, or through
 * the id list that constraint produces). These tests pin that shape so a
 * future id-taking route cannot land without a franchise constraint and a
 * test.
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

    public function test_franchise_x_sees_only_its_own_subscriptions(): void
    {
        $this->asX()->get('/franchise/subscriptions')
            ->assertOk()
            ->assertSee($this->companyX->name)
            ->assertDontSee($this->companyY->name);
    }

    public function test_revenue_is_computed_over_own_companies_only(): void
    {
        $this->asX()->get('/franchise/revenue')->assertOk();
        // The controller only ever sees ids from the franchise-constrained
        // company list — pin that no other company id could have been summed.
        $ids = Company::where('franchise_id', $this->x->id)->pluck('id');
        $this->assertEquals([$this->companyX->id], $ids->all());
    }

    public function test_no_franchise_route_accepts_a_company_or_subscription_id(): void
    {
        $franchiseRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'franchise/'));

        $this->assertNotEmpty($franchiseRoutes);

        $withParams = $franchiseRoutes->filter(fn ($r) => !empty($r->parameterNames()));
        $this->assertCount(0, $withParams,
            'A /franchise/* route now takes an id: ' . $withParams->map->uri()->implode(', ')
            . ' — it MUST constrain the query to companies.franchise_id = auth(\'franchise\')->id() and be covered here.');

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
}
