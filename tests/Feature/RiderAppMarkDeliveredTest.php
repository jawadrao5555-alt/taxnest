<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosRider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\PosRiderTrackingController;
use Illuminate\Http\Request;

/**
 * Task #1160 — rider app "Delivered" endpoint
 * (POST /api/rider-app/v1/deliveries/{txnId}/delivered).
 *
 * Covers:
 *  1. Happy path: own assigned/dispatched unsettled bill → delivered +
 *     delivered_at stamped once; response is the refreshed /me payload
 *     (bill gone from deliveries, open_deliveries decremented).
 *  2. Scope: another rider's bill → 404, untouched.
 *  3. Terminal-state lock: already delivered/returned bill → 404 (can't
 *     re-flip), delivered_at NOT overwritten.
 *  4. Settlement lock: settled bill (rider_settlement_id set) → 404.
 *  5. Auth: bad token → 401.
 *  6. 404 response still carries the refreshed deliveries payload (app resync).
 *  7. Rider-path invariant: invoice_mode / pra_status / totals untouched.
 *
 * Pattern: SQLite :memory:, minimal Schema::create, controller invoked
 * directly — same approach as RiderAutoDutyOffTest.
 */
class RiderAppMarkDeliveredTest extends TestCase
{
    private const FROZEN_NOW = '2026-08-20 14:00:00';
    private const TOKEN = '1|abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKL';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, config('app.timezone')));

        Schema::dropAllTables();
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->string('status')->default('active');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Unlimited');
            $table->string('product_type')->default('pos');
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('rider_tracking_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->default('Rider');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('on_duty')->default(false);
            $table->timestamp('duty_started_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
            $table->string('app_token', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        // Rich enough for mePayload + the delivered write path + invariants.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            // A 'submitted' bill is only legitimate WITH its fiscal number
            // (Task 1475) — the fixture below models a real fiscalised sale.
            $table->string('pra_invoice_number')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => 1, 'is_internal_account' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pricing_plans')->insert([
            'name' => 'Unlimited', 'product_type' => 'pos',
            'riders_enabled' => 1, 'rider_tracking_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        PosRider::create([
            'company_id' => 1,
            'name'       => 'App Rider',
            'is_active'  => true,
            'on_duty'    => true,
            'app_token'  => hash('sha256', self::TOKEN),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeBill(array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'      => 1,
            'rider_id'        => 1,
            'invoice_number'  => 'ZFC-' . uniqid(),
            'customer_name'   => 'Test Customer',
            'total_amount'    => 1500,
            'payment_method'  => 'cash',
            'delivery_status' => 'dispatched',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => '250813ABCDE1234',
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attrs));
    }

    private function markDelivered(int $txnId, string $token = self::TOKEN)
    {
        $request = Request::create("/api/rider-app/v1/deliveries/{$txnId}/delivered", 'POST',
            [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return app(PosRiderTrackingController::class)->appMarkDelivered($request, $txnId);
    }

    // ── 1: happy path ────────────────────────────────────────────────────────

    public function test_marks_own_open_bill_delivered_and_returns_refreshed_payload(): void
    {
        $target = $this->makeBill();
        $other  = $this->makeBill(['delivery_status' => 'assigned']);

        $resp = $this->markDelivered($target);
        $this->assertEquals(200, $resp->status());
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['ok']);
        // Refreshed payload: delivered bill dropped out, the other remains.
        $this->assertEquals(1, $data['open_deliveries']);
        $this->assertEquals([$other], array_column($data['deliveries'], 'id'));

        $row = DB::table('pos_transactions')->find($target);
        $this->assertEquals('delivered', $row->delivery_status);
        $this->assertEquals(now()->format('Y-m-d H:i:s'),
            Carbon::parse($row->delivered_at)->format('Y-m-d H:i:s'));
    }

    public function test_assigned_bill_is_also_deliverable(): void
    {
        $id = $this->makeBill(['delivery_status' => 'assigned']);

        $this->assertEquals(200, $this->markDelivered($id)->status());
        $this->assertEquals('delivered',
            DB::table('pos_transactions')->find($id)->delivery_status);
    }

    // ── 2: scope ─────────────────────────────────────────────────────────────

    public function test_other_riders_bill_is_untouchable(): void
    {
        PosRider::create(['company_id' => 1, 'name' => 'Other', 'is_active' => true]);
        $foreign = $this->makeBill(['rider_id' => 2]);

        $resp = $this->markDelivered($foreign);

        $this->assertEquals(404, $resp->status());
        $this->assertEquals('dispatched',
            DB::table('pos_transactions')->find($foreign)->delivery_status);
    }

    // ── 3: terminal-state lock ───────────────────────────────────────────────

    public function test_delivered_bill_cannot_be_reflipped_and_stamp_survives(): void
    {
        $stamp = now()->subHours(2);
        $done = $this->makeBill([
            'delivery_status' => 'delivered',
            'delivered_at'    => $stamp,
        ]);
        $returned = $this->makeBill(['delivery_status' => 'returned']);

        $this->assertEquals(404, $this->markDelivered($done)->status());
        $this->assertEquals(404, $this->markDelivered($returned)->status());

        $row = DB::table('pos_transactions')->find($done);
        $this->assertEquals($stamp->format('Y-m-d H:i:s'),
            Carbon::parse($row->delivered_at)->format('Y-m-d H:i:s'),
            'delivered_at must never be overwritten');
        $this->assertEquals('returned',
            DB::table('pos_transactions')->find($returned)->delivery_status);
    }

    // ── 4: settlement lock ───────────────────────────────────────────────────

    public function test_settled_bill_is_locked(): void
    {
        $settled = $this->makeBill(['rider_settlement_id' => 77]);

        $this->assertEquals(404, $this->markDelivered($settled)->status());
        $this->assertEquals('dispatched',
            DB::table('pos_transactions')->find($settled)->delivery_status);
    }

    // ── 5: auth ──────────────────────────────────────────────────────────────

    public function test_bad_token_is_401(): void
    {
        $id = $this->makeBill();

        try {
            $resp = $this->markDelivered($id, '1|wrong-token-wrong-token-wrong-token-wrong-token');
            $this->assertEquals(401, $resp->status());
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertEquals(401, $e->getResponse()->status());
        }
        $this->assertEquals('dispatched',
            DB::table('pos_transactions')->find($id)->delivery_status);
    }

    // ── 6: 404 carries the refreshed payload ─────────────────────────────────

    public function test_not_found_response_still_carries_deliveries_for_resync(): void
    {
        $open = $this->makeBill();
        $gone = $this->makeBill(['delivery_status' => 'delivered']);

        $resp = $this->markDelivered($gone);
        $this->assertEquals(404, $resp->status());
        $data = json_decode($resp->getContent(), true);

        $this->assertFalse($data['ok']);
        $this->assertEquals('not_found', $data['error']);
        $this->assertEquals([$open], array_column($data['deliveries'], 'id'));
        $this->assertEquals(1, $data['open_deliveries']);
    }

    // ── 7: rider-path invariants ─────────────────────────────────────────────

    public function test_invoice_mode_pra_status_and_totals_untouched(): void
    {
        $id = $this->makeBill();

        $this->markDelivered($id);

        $row = DB::table('pos_transactions')->find($id);
        $this->assertEquals('pra', $row->invoice_mode);
        $this->assertEquals('submitted', $row->pra_status);
        $this->assertEquals('250813ABCDE1234', $row->pra_invoice_number,
            'a delivery must never disturb a bill fiscalised with PRA');
        $this->assertEquals(1500.0, (float) $row->total_amount);
    }
}
