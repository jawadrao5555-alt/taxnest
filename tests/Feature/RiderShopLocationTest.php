<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\PosRiderTrackingController;
use Illuminate\Http\Request;

/**
 * Task #320 (ZFC, Aug 2026) — shop location pin for the rider tracking map.
 *
 * Covers:
 *  1. Valid save persists rounded shop_lat/shop_lng on the company.
 *  2. Coordinates outside Pakistan bounds are rejected (422 validation).
 *  3. Plan lock (riders/tracking not allowed) → 403, nothing written.
 *  4. trackingPage passes shopLat/shopLng floats to the view when set,
 *     and nulls when unset.
 *
 * Pattern: SQLite :memory:, minimal Schema::create, controller invoked
 * directly — same approach as RiderOfflineLocationSyncTest.
 */
class RiderShopLocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        // Static gate cache survives across tests in one process — company IDs
        // repeat on fresh sqlite, so stale allow/deny leaks between tests.
        \App\Services\PosFeatureService::flushGateCaches();

        // is_internal_account = true short-circuits PosFeatureService::planAllows.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->string('status')->default('active');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->decimal('shop_lat', 10, 7)->nullable();
            $table->decimal('shop_lng', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // planAllows probes pricing_plans columns even for internal accounts.
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Unlimited');
            $table->string('product_type')->default('pos');
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('rider_tracking_enabled')->default(true);
            $table->timestamps();
        });

        // Empty subscriptions table: non-internal companies resolve NO active
        // sub → planAllows fails closed (the plan-locked test's path).
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->default('Rider');
            $table->boolean('is_active')->default(true);
            $table->boolean('on_duty')->default(true);
            $table->timestamps();
        });
    }

    protected function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Shop Co',
            'is_internal_account' => true,
        ], $overrides));
    }

    protected function callSave(Company $company, array $payload)
    {
        app()->bind('currentCompanyId', fn () => $company->id);
        $request = Request::create('/pos/riders/tracking/shop-location', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');

        try {
            return app(PosRiderTrackingController::class)->saveShopLocation($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }
    }

    public function test_valid_save_persists_shop_location(): void
    {
        $company = $this->makeCompany();

        $res = $this->callSave($company, ['lat' => 29.5405679, 'lng' => 71.6335814]);

        $this->assertSame(200, $res->getStatusCode());
        $company->refresh();
        $this->assertEqualsWithDelta(29.5405679, (float) $company->shop_lat, 0.0000001);
        $this->assertEqualsWithDelta(71.6335814, (float) $company->shop_lng, 0.0000001);
    }

    public function test_outside_pakistan_bounds_rejected(): void
    {
        $company = $this->makeCompany();

        // New Delhi — inside lng range but lat/lng pair is validated per-axis;
        // use clearly out-of-range values for each axis.
        $res = $this->callSave($company, ['lat' => 19.0, 'lng' => 71.0]); // lat < 22.8
        $this->assertSame(422, $res->getStatusCode());

        $res = $this->callSave($company, ['lat' => 30.0, 'lng' => 80.0]); // lng > 77.6
        $this->assertSame(422, $res->getStatusCode());

        $company->refresh();
        $this->assertNull($company->shop_lat);
        $this->assertNull($company->shop_lng);
    }

    public function test_plan_locked_returns_403_and_writes_nothing(): void
    {
        // Non-internal company with NO subscription → planAllows fails.
        $company = $this->makeCompany(['is_internal_account' => false]);

        $res = $this->callSave($company, ['lat' => 29.54, 'lng' => 71.63]);

        $this->assertSame(403, $res->getStatusCode());
        $company->refresh();
        $this->assertNull($company->shop_lat);
    }

    public function test_tracking_page_passes_shop_coords(): void
    {
        $company = $this->makeCompany(['shop_lat' => 29.5405679, 'shop_lng' => 71.6335814]);
        app()->bind('currentCompanyId', fn () => $company->id);

        $view = app(PosRiderTrackingController::class)->trackingPage();
        $data = $view->getData();
        $this->assertFalse($data['locked']);
        $this->assertEqualsWithDelta(29.5405679, $data['shopLat'], 0.0000001);
        $this->assertEqualsWithDelta(71.6335814, $data['shopLng'], 0.0000001);

        // Unset → nulls (JS side treats null as "not set").
        $company2 = $this->makeCompany();
        app()->bind('currentCompanyId', fn () => $company2->id);
        $data2 = app(PosRiderTrackingController::class)->trackingPage()->getData();
        $this->assertNull($data2['shopLat']);
        $this->assertNull($data2['shopLng']);
    }
}
