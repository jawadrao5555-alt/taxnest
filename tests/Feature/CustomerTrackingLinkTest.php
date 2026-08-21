<?php

namespace Tests\Feature;

use App\Http\Controllers\PosRiderController;
use App\Http\Controllers\PosRiderTrackingController;
use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosRider;
use App\Models\PosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1105 (Aug 2026) — customer live tracking link & delivery ETA.
 *
 * Covers:
 *  1. Customer pin save persists rounded coords on the bill AND remembers
 *     them on the matching pos_customers row (by phone).
 *  2. Plan lock → 403, nothing written (Unlimited-only feature).
 *  3. Track link mints a 48-char token once (reused on the next call) and
 *     refuses on a delivered bill.
 *  4. Public poll: live bill → rider coords + ETA; delivered → done, NO rider
 *     coords; bad token → 410; plan lapse → 410 (downgrade kills live links).
 *  5. Board ETA poll returns km/min only for located bills with an on-duty,
 *     fresh-fix rider.
 *
 * Pattern: SQLite :memory:, minimal Schema::create, controllers invoked
 * directly — same approach as RiderShopLocationTest.
 */
class CustomerTrackingLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        \App\Services\PosFeatureService::flushGateCaches();

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

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Unlimited');
            $table->string('product_type')->default('pos');
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('rider_tracking_enabled')->default(true);
            $table->timestamps();
        });

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
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
            $table->timestamps();
        });

        // NOTE: no business_date column on purpose — the creating() hook then
        // skips the PosBusinessDay stamp (out of scope here).
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('status')->default('completed');
            $table->string('order_type')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('customer_lat', 10, 7)->nullable();
            $table->decimal('customer_lng', 10, 7)->nullable();
            $table->string('track_token', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->default('Customer');
            $table->string('phone')->nullable();
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    protected function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Karachi Biryani House',
            'is_internal_account' => true,
        ], $overrides));
    }

    protected function makeBill(Company $company, array $overrides = []): PosTransaction
    {
        return PosTransaction::create(array_merge([
            'company_id' => $company->id,
            'invoice_number' => 'INV-1',
            'customer_phone' => '03001234567',
            'status' => 'completed',
            'order_type' => 'delivery',
            'delivery_status' => 'assigned',
        ], $overrides));
    }

    protected function bind(Company $company): void
    {
        app()->bind('currentCompanyId', fn () => $company->id);
    }

    protected function callSaveLocation(Company $company, PosTransaction $bill, array $payload)
    {
        $this->bind($company);
        $request = Request::create('/pos/deliveries/' . $bill->id . '/customer-location', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        try {
            return app(PosRiderController::class)->saveCustomerLocation($request, $bill->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }
    }

    protected function callTrackLink(Company $company, PosTransaction $bill)
    {
        $this->bind($company);
        $request = Request::create('/pos/deliveries/' . $bill->id . '/track-link', 'POST');
        $request->headers->set('Accept', 'application/json');
        return app(PosRiderController::class)->trackLink($request, $bill->id);
    }

    public function test_save_location_persists_and_remembers_by_phone(): void
    {
        $company = $this->makeCompany();
        $bill = $this->makeBill($company);
        $customer = PosCustomer::create([
            'company_id' => $company->id, 'name' => 'Ahmed', 'phone' => '03001234567',
        ]);

        $res = $this->callSaveLocation($company, $bill, ['lat' => 31.5204001, 'lng' => 74.3587002]);

        $this->assertSame(200, $res->getStatusCode());
        $bill->refresh();
        $this->assertEqualsWithDelta(31.5204001, (float) $bill->customer_lat, 0.0000001);
        $this->assertEqualsWithDelta(74.3587002, (float) $bill->customer_lng, 0.0000001);
        $customer->refresh();
        $this->assertEqualsWithDelta(31.5204001, (float) $customer->geo_lat, 0.0000001);
        $this->assertEqualsWithDelta(74.3587002, (float) $customer->geo_lng, 0.0000001);
    }

    public function test_out_of_pakistan_bounds_rejected(): void
    {
        $company = $this->makeCompany();
        $bill = $this->makeBill($company);

        $res = $this->callSaveLocation($company, $bill, ['lat' => 19.0, 'lng' => 71.0]);
        $this->assertSame(422, $res->getStatusCode());
        $bill->refresh();
        $this->assertNull($bill->customer_lat);
    }

    public function test_plan_locked_returns_403_and_writes_nothing(): void
    {
        // Non-internal company with NO subscription → planAllows fails closed.
        $company = $this->makeCompany(['is_internal_account' => false]);
        $bill = $this->makeBill($company);

        $res = $this->callSaveLocation($company, $bill, ['lat' => 31.52, 'lng' => 74.35]);
        $this->assertSame(403, $res->getStatusCode());
        $bill->refresh();
        $this->assertNull($bill->customer_lat);

        $res2 = $this->callTrackLink($company, $bill);
        $this->assertSame(403, $res2->getStatusCode());
        $bill->refresh();
        $this->assertNull($bill->track_token);
    }

    public function test_track_link_mints_long_token_once_and_refuses_when_closed(): void
    {
        $company = $this->makeCompany();
        $bill = $this->makeBill($company);

        $res = $this->callTrackLink($company, $bill);
        $this->assertSame(200, $res->getStatusCode());
        $data = $res->getData(true);
        $bill->refresh();
        $this->assertSame(48, strlen((string) $bill->track_token));
        $this->assertStringEndsWith('/track/' . $bill->track_token, $data['url']);
        $this->assertStringContainsString($data['url'], $data['wa_text']);
        $this->assertSame('03001234567', $data['phone']);

        // Second call reuses the same token — links already sent stay valid.
        $res2 = $this->callTrackLink($company, $bill);
        $this->assertSame($bill->track_token, PosTransaction::find($bill->id)->track_token);
        $this->assertSame($data['url'], $res2->getData(true)['url']);

        // Delivered bill refuses to mint a fresh link.
        $closed = $this->makeBill($company, ['delivery_status' => 'delivered', 'invoice_number' => 'INV-2']);
        $res3 = $this->callTrackLink($company, $closed);
        $this->assertSame(422, $res3->getStatusCode());
    }

    public function test_public_poll_live_bill_returns_rider_and_eta(): void
    {
        $company = $this->makeCompany();
        $rider = PosRider::create([
            'company_id' => $company->id, 'name' => 'Bilal', 'on_duty' => true,
            'last_lat' => 31.5300000, 'last_lng' => 74.3500000,
            'last_located_at' => now()->subMinutes(2),
        ]);
        $bill = $this->makeBill($company, [
            'rider_id' => $rider->id, 'delivery_status' => 'dispatched',
            'customer_lat' => 31.5204000, 'customer_lng' => 74.3587000,
            'track_token' => str_repeat('a', 48),
        ]);

        $res = app(PosRiderTrackingController::class)->publicTrackData($bill->track_token);
        $this->assertSame(200, $res->getStatusCode());
        $d = $res->getData(true);
        $this->assertTrue($d['ok']);
        $this->assertSame('dispatched', $d['status']);
        $this->assertFalse($d['done']);
        $this->assertSame('Karachi Biryani House', $d['shop']);
        $this->assertEqualsWithDelta(31.53, $d['rider']['lat'], 0.0001);
        $this->assertEqualsWithDelta(74.35, $d['rider']['lng'], 0.0001);
        $this->assertNotNull($d['km']);
        $this->assertGreaterThanOrEqual(2, $d['eta_min']);
        // Payload stays minimal — no invoice/amount/phone leakage.
        $this->assertArrayNotHasKey('invoice_number', $d);
        $this->assertArrayNotHasKey('phone', $d);
    }

    public function test_public_page_marks_a_delayed_rider_fix_with_last_seen_context(): void
    {
        $company = $this->makeCompany();
        $rider = PosRider::create([
            'company_id' => $company->id, 'name' => 'Bilal', 'on_duty' => true,
            'last_lat' => 31.5300000, 'last_lng' => 74.3500000,
            'last_located_at' => now()->subMinutes(20),
        ]);
        $bill = $this->makeBill($company, [
            'rider_id' => $rider->id, 'delivery_status' => 'dispatched',
            'track_token' => str_repeat('e', 48),
        ]);

        $data = app(PosRiderTrackingController::class)
            ->publicTrackData($bill->track_token)->getData(true);

        // Delayed public fixes remain visible until the existing six-hour
        // cutoff, but their age gives the map enough context not to imply live
        // movement.
        $this->assertNotNull($data['rider']);
        $this->assertGreaterThanOrEqual(20 * 60, $data['rider']['seconds_ago']);
        $this->assertLessThan(22 * 60, $data['rider']['seconds_ago']);

        $page = app(PosRiderTrackingController::class)->publicTrackPage($bill->track_token);
        $html = $page->render();
        $this->assertStringContainsString('id="last-seen-note"', $html);
        $this->assertStringContainsString('PUBLIC_STALE_AFTER_SECONDS = 5 * 60', $html);
        $this->assertStringContainsString('riderMarker.setOpacity', $html);
    }

    public function test_public_page_offers_satellite_layer_and_google_maps_links(): void
    {
        // Task #1401: same street-level lane view the shop map already has —
        // Streets/Satellite switcher (remembered), deeper zoom, and the free
        // Google Maps deep link on the rider + destination markers.
        $company = $this->makeCompany();
        $rider = PosRider::create([
            'company_id' => $company->id, 'name' => 'Bilal', 'on_duty' => true,
            'last_lat' => 31.53, 'last_lng' => 74.35, 'last_located_at' => now(),
        ]);
        $bill = $this->makeBill($company, [
            'rider_id' => $rider->id, 'delivery_status' => 'dispatched',
            'customer_lat' => 31.5204, 'customer_lng' => 74.3587,
            'track_token' => str_repeat('f', 48),
        ]);

        $html = app(PosRiderTrackingController::class)
            ->publicTrackPage($bill->track_token)->render();

        // Free imagery + English labels overlay, no API key / paid tiles.
        $this->assertStringContainsString('World_Imagery', $html);
        $this->assertStringContainsString('voyager_only_labels', $html);
        $this->assertStringNotContainsString('maps.googleapis.com', $html);
        // Satellite tiles are only built into a layer group — they load when
        // that layer is picked, not on page open.
        $this->assertStringContainsString('L.control.layers(layerOptions', $html);
        $this->assertStringContainsString("localStorage.getItem('rt_pub_basemap')", $html);
        $this->assertStringContainsString("localStorage.setItem('rt_pub_basemap'", $html);
        // Deeper zoom than the old fixed 19.
        $this->assertStringContainsString('maxZoom: 21', $html);
        // Google Maps deep link wired to BOTH markers.
        $this->assertStringContainsString('google.com/maps/search/?api=1&query=', $html);
        $this->assertStringContainsString('setPopup(riderMarker, MARKER_LABELS.rider', $html);
        $this->assertStringContainsString('setPopup(homeMarker, MARKER_LABELS.dest', $html);
        // Public payload stays exactly as minimal as before.
        $this->assertStringNotContainsString('INV-1', $html);
    }

    public function test_public_poll_delivered_bill_is_done_without_rider(): void
    {
        $company = $this->makeCompany();
        $rider = PosRider::create([
            'company_id' => $company->id, 'name' => 'Bilal', 'on_duty' => true,
            'last_lat' => 31.53, 'last_lng' => 74.35, 'last_located_at' => now(),
        ]);
        $bill = $this->makeBill($company, [
            'rider_id' => $rider->id, 'delivery_status' => 'delivered',
            'customer_lat' => 31.5204, 'customer_lng' => 74.3587,
            'track_token' => str_repeat('b', 48),
        ]);

        $res = app(PosRiderTrackingController::class)->publicTrackData($bill->track_token);
        $this->assertSame(200, $res->getStatusCode());
        $d = $res->getData(true);
        $this->assertTrue($d['done']);
        $this->assertSame('delivered', $d['status']);
        $this->assertNull($d['rider']);
        $this->assertNull($d['eta_min']);
    }

    public function test_public_poll_bad_token_and_plan_lapse_return_410(): void
    {
        // Unknown token.
        $res = app(PosRiderTrackingController::class)->publicTrackData(str_repeat('x', 48));
        $this->assertSame(410, $res->getStatusCode());

        // Existing token but the company's plan lapsed (no subscription).
        $company = $this->makeCompany(['is_internal_account' => false]);
        $bill = $this->makeBill($company, ['track_token' => str_repeat('c', 48)]);
        $res2 = app(PosRiderTrackingController::class)->publicTrackData($bill->track_token);
        $this->assertSame(410, $res2->getStatusCode());
    }

    public function test_public_page_states(): void
    {
        $company = $this->makeCompany();
        $bill = $this->makeBill($company, [
            'delivery_status' => 'delivered', 'track_token' => str_repeat('d', 48),
        ]);

        $page = app(PosRiderTrackingController::class)->publicTrackPage($bill->track_token);
        $this->assertSame('done', $page->getData()['state']);

        $gone = app(PosRiderTrackingController::class)->publicTrackPage(str_repeat('z', 48));
        $this->assertSame(410, $gone->getStatusCode());
    }

    public function test_board_eta_poll_needs_on_duty_fresh_rider(): void
    {
        $company = $this->makeCompany();
        $this->bind($company);
        $freshRider = PosRider::create([
            'company_id' => $company->id, 'name' => 'Fresh', 'on_duty' => true,
            'last_lat' => 31.53, 'last_lng' => 74.35, 'last_located_at' => now()->subMinutes(5),
        ]);
        $staleRider = PosRider::create([
            'company_id' => $company->id, 'name' => 'Stale', 'on_duty' => true,
            'last_lat' => 31.53, 'last_lng' => 74.35, 'last_located_at' => now()->subHours(12),
        ]);
        $onBill = $this->makeBill($company, [
            'rider_id' => $freshRider->id, 'delivery_status' => 'dispatched',
            'customer_lat' => 31.5204, 'customer_lng' => 74.3587, 'invoice_number' => 'INV-A',
        ]);
        $staleBill = $this->makeBill($company, [
            'rider_id' => $staleRider->id, 'delivery_status' => 'dispatched',
            'customer_lat' => 31.5204, 'customer_lng' => 74.3587, 'invoice_number' => 'INV-B',
        ]);
        $unlocated = $this->makeBill($company, [
            'rider_id' => $freshRider->id, 'delivery_status' => 'assigned', 'invoice_number' => 'INV-C',
        ]);

        $request = Request::create('/pos/deliveries/eta/data', 'GET');
        $request->headers->set('Accept', 'application/json');
        $res = app(PosRiderController::class)->etaData($request);
        $this->assertSame(200, $res->getStatusCode());
        $etas = $res->getData(true)['etas'];
        $this->assertArrayHasKey((string) $onBill->id, $etas);
        $this->assertArrayNotHasKey((string) $staleBill->id, $etas);
        $this->assertArrayNotHasKey((string) $unlocated->id, $etas);
        $this->assertIsNumeric($etas[(string) $onBill->id]['km']);
        $this->assertGreaterThanOrEqual(2, $etas[(string) $onBill->id]['min']);
    }
}
