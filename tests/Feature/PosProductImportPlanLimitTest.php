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
use App\Services\PlanLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

/**
 * Task 361 — Excel product import must not blow past the plan's product cap.
 *
 * The plan.limit middleware only gates REQUEST entry: a shop 1 product under
 * its cap could upload a 5,000-row file and land far over the limit. The
 * import loop now stops CREATING at the remaining plan allowance (updates to
 * existing products still apply) and the flash message reports how many rows
 * were skipped because of the plan limit.
 *
 * Covers both PRA (PosController::importProducts / pos_products) and FBR
 * (FbrPosController::importProducts / products) imports.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class PosProductImportPlanLimitTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Columns SubscriptionAccessService::hasAccess touches.
            $table->boolean('is_internal_account')->default(false);
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
            // Columns SubscriptionAccessService::hasAccess reads.
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        // Override table hasAccess may consult — empty is fine.
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
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->string('description')->nullable();
            $table->string('category')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->integer('stock_quantity')->nullable();
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
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->timestamps();
        });

        $company = Company::create(['name' => 'Plan Cap Shop']);
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

    /** Build an .xlsx upload and run the given controller's importProducts(). */
    private function importRows(array $header, array $rows, string $controller = 'pos'): Request
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($header, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $r++);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'plc') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $upload = new UploadedFile($tmp, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $req = Request::create('/products/import', 'POST');
        $req->files->set('csv_file', $upload);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        if ($controller === 'pos') {
            (new PosController())->importProducts($req);
        } else {
            (new FbrPosController())->importProducts($req);
        }
        return $req;
    }

    public function test_remaining_allowance_helper(): void
    {
        // No subscription → unlimited.
        $this->assertNull(PlanLimitService::remainingProductAllowance($this->companyId, 'pos'));

        $this->makePlan(3);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'Existing', 'price' => 10]);
        $this->assertSame(2, PlanLimitService::remainingProductAllowance($this->companyId, 'pos'));
    }

    public function test_pos_import_stops_creating_at_plan_cap_but_updates_apply(): void
    {
        $this->makePlan(3);
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'Old Item', 'price' => 10, 'sku' => 'OLD-1']);

        $header = ['Name', 'Price', 'SKU'];
        $this->importRows($header, [
            ['Old Item', 99, 'OLD-1'],   // update — always applies
            ['New One', 11, 'N-1'],      // fills slot 2
            ['New Two', 12, 'N-2'],      // fills slot 3 (cap reached)
            ['New Three', 13, 'N-3'],    // over cap → skipped
            ['New Four', 14, 'N-4'],     // over cap → skipped
        ]);

        $this->assertSame(3, PosProduct::where('company_id', $this->companyId)->count());
        $this->assertEquals(99.0, (float) PosProduct::where('sku', 'OLD-1')->first()->price, 'Update must still apply at cap');
        $this->assertNotNull(PosProduct::where('sku', 'N-1')->first());
        $this->assertNotNull(PosProduct::where('sku', 'N-2')->first());
        $this->assertNull(PosProduct::where('sku', 'N-3')->first());
        $this->assertNull(PosProduct::where('sku', 'N-4')->first());

        // Flash message reports the plan-limit skips.
        $flash = (string) session('success');
        $this->assertStringContainsString(__('pos.import_plan_limit_skipped', ['count' => 2]), $flash);
    }

    public function test_pos_import_unlimited_plan_creates_everything(): void
    {
        $this->makePlan(-1); // -1 = unlimited convention

        $header = ['Name', 'Price', 'SKU'];
        $this->importRows($header, [
            ['A', 1, 'A-1'], ['B', 2, 'B-1'], ['C', 3, 'C-1'],
        ]);

        $this->assertSame(3, PosProduct::where('company_id', $this->companyId)->count());
        $this->assertStringNotContainsString('plan', strtolower((string) session('success')));
    }

    public function test_back_to_back_imports_never_exceed_cap(): void
    {
        // Regression for the concurrent-import double-spend: quota admission is
        // atomic (company-row lock + transaction), so serialized imports — which
        // is what concurrent requests become under the lock — must recount and
        // never exceed the cap combined.
        $this->makePlan(3);

        $this->importRows(['Name', 'Price', 'SKU'], [
            ['P1', 1, 'P-1'], ['P2', 2, 'P-2'], ['P3', 3, 'P-3'], ['P4', 4, 'P-4'],
        ]);
        $this->assertSame(3, PosProduct::where('company_id', $this->companyId)->count());

        // Second import (fresh request) tries to fill the cap again — the
        // recomputed allowance must be 0, so nothing new is created.
        $this->importRows(['Name', 'Price', 'SKU'], [
            ['Q1', 1, 'Q-1'], ['Q2', 2, 'Q-2'], ['Q3', 3, 'Q-3'],
        ]);
        $this->assertSame(3, PosProduct::where('company_id', $this->companyId)->count(), 'Combined imports must never exceed the plan cap');
        $this->assertNull(PosProduct::where('sku', 'Q-1')->first());
        $this->assertStringContainsString(__('pos.import_plan_limit_skipped', ['count' => 3]), (string) session('error'));
    }

    public function test_pos_import_blocked_when_subscription_expired(): void
    {
        // Expired plan: hasAccess() must deny and the import must write NOTHING
        // (the route has no plan.limit middleware — the controller gate is the
        // only enforcement, Task 361 review).
        $plan = PricingPlan::create(['name' => 'Old', 'product_type' => 'pos', 'max_products' => 100]);
        Subscription::create([
            'company_id' => $this->companyId,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->importRows(['Name', 'Price', 'SKU'], [
            ['Should Not Exist', 10, 'X-1'],
        ]);

        $this->assertSame(0, PosProduct::where('company_id', $this->companyId)->count(), 'Expired subscription must block all import writes');
        $this->assertNull(session('success'));
        $this->assertNotNull(session('error'));
    }

    public function test_fbr_import_stops_creating_at_plan_cap(): void
    {
        $this->makePlan(2, 'fbrpos');
        Product::create(['company_id' => $this->companyId, 'name' => 'Have One', 'default_price' => 10, 'sku' => 'H-1']);

        $header = ['Name', 'Price', 'SKU'];
        $this->importRows($header, [
            ['Have One', 15, 'H-1'],   // update — applies
            ['Fresh A', 20, 'F-1'],    // fills last slot
            ['Fresh B', 30, 'F-2'],    // over cap → skipped
        ], 'fbr');

        $this->assertSame(2, Product::where('company_id', $this->companyId)->count());
        $this->assertEquals(15.0, (float) Product::where('sku', 'H-1')->first()->default_price);
        $this->assertNotNull(Product::where('sku', 'F-1')->first());
        $this->assertNull(Product::where('sku', 'F-2')->first());
        $this->assertStringContainsString(__('pos.import_plan_limit_skipped', ['count' => 1]), (string) session('success'));
    }

    public function test_fbr_import_at_cap_updates_apply_and_creates_skip(): void
    {
        // Catalog ALREADY at cap: update-only rows must still apply (no route
        // middleware to 403 the request), new rows must be skipped + reported.
        $this->makePlan(2, 'fbrpos');
        Product::create(['company_id' => $this->companyId, 'name' => 'Cap A', 'default_price' => 10, 'sku' => 'CAP-A']);
        Product::create(['company_id' => $this->companyId, 'name' => 'Cap B', 'default_price' => 20, 'sku' => 'CAP-B']);

        $this->importRows(['Name', 'Price', 'SKU'], [
            ['Cap A', 111, 'CAP-A'],    // update — must persist even at cap
            ['Overflow', 30, 'OVR-1'],  // create — must be skipped
        ], 'fbr');

        $this->assertSame(2, Product::where('company_id', $this->companyId)->count());
        $this->assertEquals(111.0, (float) Product::where('sku', 'CAP-A')->first()->default_price, 'At-cap update must still apply');
        $this->assertNull(Product::where('sku', 'OVR-1')->first());
        $this->assertStringContainsString(__('pos.import_plan_limit_skipped', ['count' => 1]), (string) session('success'));
    }

    public function test_fbr_import_blocked_when_subscription_expired(): void
    {
        $plan = PricingPlan::create(['name' => 'Old FBR', 'product_type' => 'fbrpos', 'max_products' => 100]);
        Subscription::create([
            'company_id' => $this->companyId,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->importRows(['Name', 'Price', 'SKU'], [
            ['Should Not Exist', 10, 'X-9'],
        ], 'fbr');

        $this->assertSame(0, Product::where('company_id', $this->companyId)->count(), 'Expired subscription must block all FBR import writes');
        $this->assertNull(session('success'));
        $this->assertNotNull(session('error'));
    }
}
