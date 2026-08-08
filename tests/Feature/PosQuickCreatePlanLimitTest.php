<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosProduct;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\PricingPlan;
use App\Http\Controllers\PosController;
use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\ProductController;
use App\Services\PlanLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Task 362 — shops at/over their plan's product cap (e.g. after a downgrade)
 * must not be able to keep adding products one-by-one through the quick-create
 * paths, and the products pages must surface usage vs cap.
 *
 * Covers all three quick-create routes (none of which carry plan.limit
 * middleware anymore):
 *   - PRA POS sale screen:  PosController::apiQuickCreate      (pos_products)
 *   - FBR POS sale screen:  FbrPosController::apiQuickCreateProduct (products)
 *   - DI invoice screen:    ProductController::quickCreate     (products)
 *
 * Also covers: fail-closed subscription-access gate on each path (expired
 * subscription = 403 even under cap), FBR dedupe/reprice of EXISTING products
 * still working at cap, and PlanLimitService::productLimitStatus (banner data).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosProductImportPlanLimitTest).
 */
class PosQuickCreatePlanLimitTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->string('category')->nullable();
            $table->string('sku')->nullable();
            $table->string('uom')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->string('hs_code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->string('tax_type')->nullable();
            $table->decimal('default_tax_rate', 8, 2)->default(0);
            $table->string('schedule_type')->nullable();
            $table->boolean('is_price_editable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $company = Company::create(['name' => 'Downgraded Shop']);
        $this->companyId = $company->id;

        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    private function makePlan(int $maxProducts, string $productType = 'pos'): void
    {
        $plan = PricingPlan::create([
            'name' => 'Capped', 'product_type' => $productType,
            'max_products' => $maxProducts, 'is_trial' => false,
        ]);
        Subscription::create([
            'company_id' => $this->companyId,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
        ]);
    }

    private function makeExpiredPlan(string $productType = 'pos'): void
    {
        $plan = PricingPlan::create([
            'name' => 'Expired', 'product_type' => $productType,
            'max_products' => 100, 'is_trial' => false,
        ]);
        Subscription::create([
            'company_id' => $this->companyId,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'end_date' => now()->subDay()->toDateString(),
        ]);
    }

    private function jsonRequest(array $payload): Request
    {
        $req = Request::create('/quick-create', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);
        return $req;
    }

    // ── PRA POS sale-screen quick-create ────────────────────────────────

    public function test_pos_quick_create_blocked_at_cap(): void
    {
        $this->makePlan(2);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'A', 'price' => 1]);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'B', 'price' => 2]);

        $res = (new PosController())->apiQuickCreate($this->jsonRequest(['name' => 'Over Cap', 'price' => 5]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('plan_limit', $res->getData(true)['reason'] ?? null);
        $this->assertSame(2, PosProduct::where('company_id', $this->companyId)->count());
    }

    public function test_pos_quick_create_blocked_when_over_cap_after_downgrade(): void
    {
        // Downgrade scenario: shop already OVER its cap keeps its rows but
        // cannot add even one more.
        $this->makePlan(1);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'A', 'price' => 1]);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'B', 'price' => 2]);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'C', 'price' => 3]);

        $res = (new PosController())->apiQuickCreate($this->jsonRequest(['name' => 'One More', 'price' => 5]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(3, PosProduct::where('company_id', $this->companyId)->count());
    }

    public function test_pos_quick_create_allowed_under_cap_and_unlimited(): void
    {
        $this->makePlan(2);
        $res = (new PosController())->apiQuickCreate($this->jsonRequest(['name' => 'First', 'price' => 5]));
        $this->assertTrue($res->getData(true)['ok']);
        $this->assertSame(1, PosProduct::where('company_id', $this->companyId)->count());
    }

    public function test_pos_quick_create_blocked_when_subscription_expired(): void
    {
        $this->makeExpiredPlan();
        $res = (new PosController())->apiQuickCreate($this->jsonRequest(['name' => 'Nope', 'price' => 5]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertNotSame('plan_limit', $res->getData(true)['reason'] ?? null, 'Expired access must block BEFORE the cap check');
        $this->assertSame(0, PosProduct::where('company_id', $this->companyId)->count());
    }

    // ── FBR POS sale-screen quick-create ────────────────────────────────

    public function test_fbr_quick_create_blocked_at_cap_but_existing_dedupe_still_works(): void
    {
        $this->makePlan(1, 'fbrpos');
        Product::create(['company_id' => $this->companyId, 'name' => 'Existing Item', 'default_price' => 0, 'barcode' => 'BC-1']);

        // NEW product at cap → blocked.
        $res = (new FbrPosController())->apiQuickCreateProduct($this->jsonRequest(['name' => 'Brand New', 'price' => 10]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('plan_limit', $res->getData(true)['reason'] ?? null);
        $this->assertSame(1, Product::where('company_id', $this->companyId)->count());

        // EXISTING product via dedupe (same name, unpriced → repriced) → must
        // still succeed at cap: this is why the route middleware was removed.
        $res2 = (new FbrPosController())->apiQuickCreateProduct($this->jsonRequest(['name' => 'Existing Item', 'price' => 55]));
        $data2 = $res2->getData(true);
        $this->assertTrue($data2['ok']);
        $this->assertSame(1, Product::where('company_id', $this->companyId)->count(), 'Dedupe must never create a twin row');
        $this->assertEquals(55.0, (float) Product::where('company_id', $this->companyId)->first()->default_price);
    }

    public function test_fbr_quick_create_blocked_when_subscription_expired(): void
    {
        $this->makeExpiredPlan('fbrpos');
        Product::create(['company_id' => $this->companyId, 'name' => 'Existing Item', 'default_price' => 5]);

        // Even a dedupe/reprice request is blocked for an expired shop (access
        // gate runs BEFORE the dedupe read — fail closed).
        $res = (new FbrPosController())->apiQuickCreateProduct($this->jsonRequest(['name' => 'Existing Item', 'price' => 99]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertEquals(5.0, (float) Product::where('company_id', $this->companyId)->first()->default_price, 'No write on expired access');

        $res2 = (new FbrPosController())->apiQuickCreateProduct($this->jsonRequest(['name' => 'New Item', 'price' => 9]));
        $this->assertSame(403, $res2->getStatusCode());
        $this->assertSame(1, Product::where('company_id', $this->companyId)->count());
    }

    // ── DI invoice-screen quick-create ──────────────────────────────────

    public function test_di_quick_create_blocked_at_cap(): void
    {
        $this->makePlan(1, 'di');
        Product::create(['company_id' => $this->companyId, 'name' => 'Have', 'default_price' => 1]);

        $res = (new ProductController())->quickCreate($this->jsonRequest(['name' => 'Over', 'hs_code' => '0101.0101']));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(1, Product::where('company_id', $this->companyId)->count());
    }

    public function test_di_quick_create_allowed_under_cap(): void
    {
        $this->makePlan(2, 'di');
        $res = (new ProductController())->quickCreate($this->jsonRequest(['name' => 'Fresh', 'hs_code' => '0101.0101']));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, Product::where('company_id', $this->companyId)->count());
    }

    public function test_di_quick_create_blocked_when_subscription_expired(): void
    {
        $this->makeExpiredPlan('di');
        $res = (new ProductController())->quickCreate($this->jsonRequest(['name' => 'Nope', 'hs_code' => '0101.0101']));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, Product::where('company_id', $this->companyId)->count());
    }

    // ── Banner data: productLimitStatus ─────────────────────────────────

    public function test_product_limit_status_reports_over_cap_and_unlimited(): void
    {
        // No plan → unlimited → null (no banner).
        $this->assertNull(PlanLimitService::productLimitStatus($this->companyId, 'pos'));

        $this->makePlan(2);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'A', 'price' => 1]);
        $status = PlanLimitService::productLimitStatus($this->companyId, 'pos');
        $this->assertSame(['limit' => 2, 'used' => 1, 'over' => false], $status);

        PosProduct::create(['company_id' => $this->companyId, 'name' => 'B', 'price' => 1]);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'C', 'price' => 1]);
        $status = PlanLimitService::productLimitStatus($this->companyId, 'pos');
        $this->assertSame(['limit' => 2, 'used' => 3, 'over' => true], $status);

        // Inactive rows don't count against the cap.
        PosProduct::where('name', 'C')->update(['is_active' => false]);
        $this->assertFalse(PlanLimitService::productLimitStatus($this->companyId, 'pos')['over']);
    }
}
