<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosRider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\PosRiderController;
use App\Http\Controllers\PosRiderTrackingController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_customer_places', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('place_type', 20)->default('other');
            $table->string('label', 80)->nullable();
            $table->text('address')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->string('created_from', 20)->default('rider');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->unsignedBigInteger('merged_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_delivery_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('customer_place_id')->nullable();
            $table->uuid('client_event_id')->nullable();
            $table->string('assignment_revision', 100)->nullable();
            $table->string('place_type', 20)->default('other');
            $table->string('place_label', 80)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->decimal('completed_lat', 10, 7)->nullable();
            $table->decimal('completed_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('proximity_verified')->default(false);
            $table->string('evidence_source', 20)->default('legacy');
            $table->timestamps();
            $table->unique(['company_id', 'transaction_id']);
            $table->unique(['rider_id', 'client_event_id']);
        });

        Schema::create('pos_rider_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();
        });

        // Rich enough for mePayload + the delivered write path + invariants.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('customer_place_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->uuid('rider_assignment_revision')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            // A 'submitted' bill is only legitimate WITH its fiscal number
            // (Task 1475) — the fixture below models a real fiscalised sale.
            $table->string('pra_invoice_number')->nullable();
            $table->decimal('customer_lat', 10, 7)->nullable();
            $table->decimal('customer_lng', 10, 7)->nullable();
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

    private function markDeliveredWithEvidence(int $txnId, array $overrides = [])
    {
        $capturedAt = now()->subMinute();
        $payload = array_merge([
            'client_event_id' => '79bd560c-26c2-4f65-bbb2-24f85cc6e217',
            'assignment_revision' => $this->assignmentRevisionFor($txnId),
            'place_type' => 'home',
            'place_label' => 'Front gate',
            'lat' => 31.52045,
            'lng' => 74.35872,
            'accuracy_m' => 12,
            'captured_at' => $capturedAt->getTimestampMs(),
        ], $overrides);

        $request = Request::create("/api/rider-app/v1/deliveries/{$txnId}/delivered", 'POST',
            $payload, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]);

        return app(PosRiderTrackingController::class)->appMarkDelivered($request, $txnId);
    }

    private function assignmentRevisionFor(int $txnId): string
    {
        $request = Request::create('/api/rider-app/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ]);
        $payload = json_decode(
            app(PosRiderTrackingController::class)->appMe($request)->getContent(),
            true
        );
        foreach ($payload['deliveries'] ?? [] as $delivery) {
            if ((int) $delivery['id'] === $txnId) {
                return (string) $delivery['assignment_revision'];
            }
        }
        $completedRevision = DB::table('pos_delivery_completions')
            ->where('transaction_id', $txnId)
            ->value('assignment_revision');
        if (filled($completedRevision)) {
            return (string) $completedRevision;
        }
        $this->fail("No assignment revision returned for transaction {$txnId}.");
    }

    private function recordFix(float $lat = 31.52045, float $lng = 74.35872, ?Carbon $at = null, int $accuracyM = 10): void
    {
        $at ??= now()->subMinute();
        DB::table('pos_rider_locations')->insert([
            'company_id' => 1,
            'rider_id' => 1,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => $accuracyM,
            'recorded_at' => $at,
            'created_at' => now(),
        ]);
        DB::table('pos_riders')->where('id', 1)->update([
            'last_lat' => $lat,
            'last_lng' => $lng,
            'last_located_at' => $at,
        ]);
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

    public function test_new_app_completion_requires_server_known_gps_and_records_private_evidence(): void
    {
        DB::table('pos_customers')->insert([
            'id' => 8, 'company_id' => 1, 'name' => 'Repeat Customer',
            'phone' => '03001234567', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assignedAt = now()->subMinutes(10);
        $id = $this->makeBill([
            'customer_id' => 8,
            'customer_phone' => '03001234567',
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => $assignedAt,
        ]);
        $this->recordFix();

        $resp = $this->markDeliveredWithEvidence($id);
        $this->assertSame(200, $resp->status(), $resp->getContent());
        $data = json_decode($resp->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['delivery_completed']);
        $this->assertTrue($data['proximity_verified']);

        $completion = DB::table('pos_delivery_completions')->where('transaction_id', $id)->first();
        $this->assertNotNull($completion);
        $this->assertSame('home', $completion->place_type);
        $this->assertSame('Front gate', $completion->place_label);
        $this->assertSame('gps', $completion->evidence_source);
        $this->assertSame(1, (int) $completion->proximity_verified);
        $this->assertLessThan(150, (int) $completion->distance_m);

        $place = DB::table('pos_customer_places')->find($completion->customer_place_id);
        $this->assertSame(1, (int) $place->company_id);
        $this->assertSame(8, (int) $place->customer_id);
        $this->assertSame('home', $place->place_type);
        $this->assertSame(1, (int) $place->is_verified);

        $row = DB::table('pos_transactions')->find($id);
        $this->assertSame('delivered', $row->delivery_status);
        $this->assertSame((int) $place->id, (int) $row->customer_place_id);
        $this->assertSame('pra', $row->invoice_mode);
        $this->assertSame('submitted', $row->pra_status);
        $this->assertSame('250813ABCDE1234', $row->pra_invoice_number);
        $this->assertSame(1500.0, (float) $row->total_amount);
        $this->assertNull($row->rider_settlement_id);
    }

    public function test_device_fractional_accuracy_is_accepted_and_normalized(): void
    {
        $id = $this->makeBill([
            'customer_phone' => '03001010101',
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => now()->subMinutes(10),
        ]);
        $this->recordFix();

        $response = $this->markDeliveredWithEvidence($id, ['accuracy_m' => 12.7]);

        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame(
            13,
            (int) DB::table('pos_delivery_completions')
                ->where('transaction_id', $id)
                ->value('accuracy_m')
        );
    }

    public function test_rich_completion_rejects_client_gps_older_than_ten_minutes(): void
    {
        $id = $this->makeBill([
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => now()->subMinutes(20),
        ]);
        $oldFix = now()->subMinutes(11);
        $this->recordFix(31.52045, 74.35872, $oldFix);

        $response = $this->markDeliveredWithEvidence($id, [
            'captured_at' => $oldFix->getTimestampMs(),
        ]);

        $this->assertSame(422, $response->status());
        $this->assertSame('stale_gps', json_decode($response->getContent(), true)['error']);
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($id)->delivery_status);
        $this->assertSame(0, DB::table('pos_delivery_completions')->count());
    }

    public function test_saved_bill_place_is_authoritative_for_completion_proximity(): void
    {
        DB::table('pos_customers')->insert([
            'id' => 20,
            'company_id' => 1,
            'name' => 'Pinned Place Buyer',
            'phone' => '03002020202',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $placeId = DB::table('pos_customer_places')->insertGetId([
            'company_id' => 1,
            'customer_id' => 20,
            'customer_phone' => '03002020202',
            'place_type' => 'business',
            'label' => 'Pinned office',
            'lat' => 31.60000,
            'lng' => 74.45000,
            'is_verified' => 1,
            'usage_count' => 2,
            'last_used_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = $this->makeBill([
            'customer_id' => 20,
            'customer_phone' => '03002020202',
            'customer_place_id' => $placeId,
            'rider_assigned_at' => now()->subMinutes(10),
        ]);

        $farAt = now()->subMinutes(2);
        $this->recordFix(31.52045, 74.35872, $farAt);
        $tooFar = $this->markDeliveredWithEvidence($id, [
            'lat' => 31.52045,
            'lng' => 74.35872,
            'captured_at' => $farAt->getTimestampMs(),
        ]);
        $this->assertSame(422, $tooFar->status());
        $this->assertSame('too_far', json_decode($tooFar->getContent(), true)['error']);

        $nearAt = now()->subSeconds(10);
        $this->recordFix(31.60002, 74.45002, $nearAt);
        $delivered = $this->markDeliveredWithEvidence($id, [
            'client_event_id' => 'c99eef56-1358-4725-85e8-3951931330ef',
            'lat' => 31.60002,
            'lng' => 74.45002,
            'captured_at' => $nearAt->getTimestampMs(),
        ]);
        $this->assertSame(200, $delivered->status(), $delivered->getContent());

        $completion = DB::table('pos_delivery_completions')
            ->where('transaction_id', $id)->first();
        $this->assertSame(1, (int) $completion->proximity_verified);
        $this->assertEqualsWithDelta(31.6, (float) $completion->destination_lat, .000001);
        $this->assertEqualsWithDelta(74.45, (float) $completion->destination_lng, .000001);
    }

    public function test_customer_id_places_win_over_newer_phone_only_history_in_navigation_and_completion(): void
    {
        DB::table('pos_customers')->insert([
            'id' => 21,
            'company_id' => 1,
            'name' => 'Migrated Place Buyer',
            'phone' => '03002121212',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_customer_places')->insert([
            [
                'company_id' => 1,
                'customer_id' => 21,
                'customer_phone' => '03002121212',
                'place_type' => 'home',
                'label' => 'Linked home',
                'lat' => 31.52040,
                'lng' => 74.35870,
                'is_verified' => 1,
                'usage_count' => 1,
                'last_used_at' => now()->subDays(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => 1,
                'customer_id' => null,
                'customer_phone' => '03002121212',
                'place_type' => 'business',
                'label' => 'Newer phone-only office',
                'lat' => 31.60000,
                'lng' => 74.45000,
                'is_verified' => 1,
                'usage_count' => 9,
                'last_used_at' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $id = $this->makeBill([
            'customer_id' => 21,
            'customer_phone' => '03002121212',
            'rider_assigned_at' => now()->subMinutes(10),
        ]);

        $request = Request::create('/api/rider-app/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ]);
        $me = json_decode(
            app(PosRiderTrackingController::class)->appMe($request)->getContent(),
            true
        );
        $delivery = collect($me['deliveries'])->firstWhere('id', $id);
        $this->assertSame('Linked home', $delivery['place_label']);
        $this->assertEqualsWithDelta(31.5204, (float) $delivery['destination_lat'], .000001);
        $this->assertCount(1, $delivery['saved_places']);

        $this->recordFix();
        $response = $this->markDeliveredWithEvidence($id);
        $this->assertSame(200, $response->status(), $response->getContent());
        $completion = DB::table('pos_delivery_completions')
            ->where('transaction_id', $id)->first();
        $this->assertEqualsWithDelta(
            (float) $delivery['destination_lat'],
            (float) $completion->destination_lat,
            .000001
        );
        $this->assertEqualsWithDelta(
            (float) $delivery['destination_lng'],
            (float) $completion->destination_lng,
            .000001
        );
    }

    public function test_delivery_board_assignment_issues_and_rotates_uuid_revision(): void
    {
        app()->instance('currentCompanyId', 1);
        $id = $this->makeBill([
            'rider_id' => null,
            'delivery_status' => null,
            'order_type' => 'delivery',
        ]);

        $request = Request::create(
            "/pos/deliveries/{$id}/assign",
            'POST',
            ['rider_id' => 1],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );
        $response = app(PosRiderController::class)->assign($request, $id);
        $this->assertSame(200, $response->status());
        $first = DB::table('pos_transactions')->find($id);
        $this->assertNotNull($first->rider_assigned_at);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $first->rider_assignment_revision
        );

        $secondRiderId = DB::table('pos_riders')->insertGetId([
            'company_id' => 1,
            'name' => 'Replacement Rider',
            'is_active' => 1,
            'on_duty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reassign = Request::create(
            "/pos/deliveries/{$id}/assign",
            'POST',
            ['rider_id' => $secondRiderId],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );
        $response = app(PosRiderController::class)->assign($reassign, $id);
        $this->assertSame(200, $response->status());
        $second = DB::table('pos_transactions')->find($id);
        $this->assertNotSame(
            (string) $first->rider_assignment_revision,
            (string) $second->rider_assignment_revision
        );
    }

    public function test_assignment_revision_is_opaque_and_rechecked_after_assignment_changes(): void
    {
        $id = $this->makeBill([
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => now()->subMinutes(10),
        ]);
        $revision = $this->assignmentRevisionFor($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $revision);
        $this->assertStringNotContainsString($id . '-1-', $revision);

        DB::table('pos_transactions')->where('id', $id)->update([
            'rider_assigned_at' => now()->subMinutes(5),
        ]);
        $this->recordFix();
        $response = $this->markDeliveredWithEvidence($id, [
            'assignment_revision' => $revision,
        ]);

        $this->assertSame(409, $response->status());
        $this->assertSame('assignment_changed', json_decode($response->getContent(), true)['error']);
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($id)->delivery_status);
        $this->assertSame(0, DB::table('pos_delivery_completions')->count());
    }

    public function test_same_completion_event_is_exactly_idempotent_after_bill_becomes_terminal(): void
    {
        $assignedAt = now()->subMinutes(10);
        $id = $this->makeBill([
            'customer_phone' => '03009990000',
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => $assignedAt,
        ]);
        $this->recordFix();

        $first = $this->markDeliveredWithEvidence($id);
        $this->assertSame(200, $first->status(), $first->getContent());
        $stamp = DB::table('pos_transactions')->find($id)->delivered_at;

        $second = $this->markDeliveredWithEvidence($id);
        $this->assertSame(200, $second->status(), $second->getContent());
        $this->assertTrue(json_decode($second->getContent(), true)['duplicate']);
        $this->assertSame(1, DB::table('pos_delivery_completions')->where('transaction_id', $id)->count());
        $this->assertSame($stamp, DB::table('pos_transactions')->find($id)->delivered_at);
    }

    public function test_new_app_completion_rejects_unknown_or_distant_gps_without_touching_bill(): void
    {
        $assignedAt = now()->subMinutes(10);
        $unknown = $this->makeBill([
            'customer_phone' => '03001111111',
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => $assignedAt,
        ]);

        $noFix = $this->markDeliveredWithEvidence($unknown);
        $this->assertSame(409, $noFix->status());
        $this->assertSame('gps_not_synced', json_decode($noFix->getContent(), true)['error']);
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($unknown)->delivery_status);

        $far = $this->makeBill([
            'customer_phone' => '03002222222',
            'customer_lat' => 31.60000,
            'customer_lng' => 74.45000,
            'rider_assigned_at' => $assignedAt,
        ]);
        $this->recordFix();
        $tooFar = $this->markDeliveredWithEvidence($far, [
            'client_event_id' => '5737fd0e-27fb-492e-be27-2187dd2d8f55',
        ]);
        $this->assertSame(422, $tooFar->status());
        $this->assertSame('too_far', json_decode($tooFar->getContent(), true)['error']);
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($far)->delivery_status);
        $this->assertSame(0, DB::table('pos_delivery_completions')->count());
    }

    public function test_no_destination_pin_accepts_fresh_current_place_and_saves_actual_other_place(): void
    {
        $assignedAt = now()->subMinutes(10);
        $id = $this->makeBill([
            'customer_phone' => '03003333333',
            'rider_assigned_at' => $assignedAt,
        ]);
        $this->recordFix();

        $resp = $this->markDeliveredWithEvidence($id, [
            'place_type' => 'other',
            'place_label' => 'Warehouse gate',
        ]);
        $this->assertSame(200, $resp->status(), $resp->getContent());
        $completion = DB::table('pos_delivery_completions')->where('transaction_id', $id)->first();
        $this->assertSame('current_place', $completion->evidence_source);
        $this->assertSame(0, (int) $completion->proximity_verified);

        $bill = DB::table('pos_transactions')->find($id);
        $this->assertEqualsWithDelta(31.52045, (float) $bill->customer_lat, .000001);
        $this->assertEqualsWithDelta(74.35872, (float) $bill->customer_lng, .000001);
        $this->assertSame('other', DB::table('pos_customer_places')->find($bill->customer_place_id)->place_type);
    }

    public function test_me_payload_adds_private_destination_saved_places_and_exact_google_navigation(): void
    {
        DB::table('pos_customers')->insert([
            'id' => 9, 'company_id' => 1, 'name' => 'Office Buyer',
            'phone' => '03004444444', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_customer_places')->insert([
            'company_id' => 1,
            'customer_id' => 9,
            'customer_phone' => '03004444444',
            'place_type' => 'business',
            'label' => 'Main office',
            'lat' => 31.521,
            'lng' => 74.359,
            'is_verified' => 1,
            'usage_count' => 4,
            'last_used_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = $this->makeBill([
            'customer_id' => 9,
            'customer_phone' => '03004444444',
            'rider_assigned_at' => now()->subMinutes(10),
        ]);
        $this->recordFix();

        $request = Request::create('/api/rider-app/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ]);
        $resp = app(PosRiderTrackingController::class)->appMe($request);
        $this->assertSame(200, $resp->status());
        $delivery = json_decode($resp->getContent(), true)['deliveries'][0];

        $this->assertSame($id, $delivery['id']);
        $this->assertSame('saved', $delivery['destination_source']);
        $this->assertSame('business', $delivery['place_type']);
        $this->assertSame('Main office', $delivery['place_label']);
        $this->assertCount(1, $delivery['saved_places']);
        $this->assertSame(150, $delivery['arrival_radius_m']);
        $this->assertStringContainsString(
            'https://www.google.com/maps/dir/?api=1&destination=31.521,74.359',
            $delivery['maps_url']
        );
    }

    public function test_rich_completion_requires_assignment_revision_but_legacy_request_stays_compatible(): void
    {
        $id = $this->makeBill([
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => now()->subMinutes(10),
        ]);
        $this->recordFix();

        try {
            $this->markDeliveredWithEvidence($id, ['assignment_revision' => null]);
            $this->fail('Rich evidence without assignment_revision should not validate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignment_revision', $e->errors());
        }
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($id)->delivery_status);

        $legacy = $this->markDelivered($id);
        $this->assertSame(200, $legacy->status());
        $this->assertSame('legacy', DB::table('pos_delivery_completions')->where('transaction_id', $id)->value('evidence_source'));
    }

    public function test_one_event_uuid_cannot_be_replayed_for_a_different_transaction(): void
    {
        $assignedAt = now()->subMinutes(10);
        $firstId = $this->makeBill([
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => $assignedAt,
        ]);
        $secondId = $this->makeBill([
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => $assignedAt,
        ]);
        $this->recordFix();

        $this->assertSame(200, $this->markDeliveredWithEvidence($firstId, [
        ])->status());
        $conflict = $this->markDeliveredWithEvidence($secondId, [
        ]);

        $this->assertSame(409, $conflict->status());
        $this->assertSame('event_conflict', json_decode($conflict->getContent(), true)['error']);
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($secondId)->delivery_status);
        $this->assertSame(1, DB::table('pos_delivery_completions')->count());
    }

    public function test_matching_server_fix_with_poor_accuracy_is_rejected(): void
    {
        $assignedAt = now()->subMinutes(10);
        $id = $this->makeBill([
            'customer_lat' => 31.52040,
            'customer_lng' => 74.35870,
            'rider_assigned_at' => $assignedAt,
        ]);
        $this->recordFix(31.52045, 74.35872, null, 500);

        $response = $this->markDeliveredWithEvidence($id, [
        ]);

        $this->assertSame(422, $response->status());
        $this->assertSame('inaccurate_gps', json_decode($response->getContent(), true)['error']);
        $this->assertSame('dispatched', DB::table('pos_transactions')->find($id)->delivery_status);
        $this->assertSame(0, DB::table('pos_delivery_completions')->count());
    }

    public function test_me_prefers_the_place_explicitly_selected_on_the_bill(): void
    {
        DB::table('pos_customers')->insert([
            'id' => 10, 'company_id' => 1, 'name' => 'Two-place Customer',
            'phone' => '03007777777', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $selectedId = DB::table('pos_customer_places')->insertGetId([
            'company_id' => 1, 'customer_id' => 10, 'customer_phone' => '03007777777',
            'place_type' => 'home', 'label' => 'Selected home',
            'lat' => 31.51, 'lng' => 74.35, 'is_verified' => 1,
            'usage_count' => 1, 'last_used_at' => now()->subWeek(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_customer_places')->insert([
            'company_id' => 1, 'customer_id' => 10, 'customer_phone' => '03007777777',
            'place_type' => 'business', 'label' => 'More recent office',
            'lat' => 31.55, 'lng' => 74.39, 'is_verified' => 1,
            'usage_count' => 9, 'last_used_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $billId = $this->makeBill([
            'customer_id' => 10,
            'customer_phone' => '03007777777',
            'customer_place_id' => $selectedId,
            'rider_assigned_at' => now()->subMinutes(10),
        ]);

        $request = Request::create('/api/rider-app/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
        ]);
        $response = app(PosRiderTrackingController::class)->appMe($request);
        $delivery = json_decode($response->getContent(), true)['deliveries'][0];

        $this->assertSame($billId, $delivery['id']);
        $this->assertSame($selectedId, $delivery['saved_place_id']);
        $this->assertSame('home', $delivery['place_type']);
        $this->assertSame('Selected home', $delivery['place_label']);
        $this->assertSame(31.51, $delivery['destination_lat']);
        $this->assertSame(74.35, $delivery['destination_lng']);
    }
}
