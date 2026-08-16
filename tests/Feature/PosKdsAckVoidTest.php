<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantKdsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * KDS server-side void acknowledgement — Task 855.
 *
 * "Got it" on one KDS screen must clear the cancelled-dish badge for ALL screens
 * on the same shop. The endpoint sets void_items = NULL so the next poll returns
 * an empty array everywhere.
 *
 * Race safety: the client sends the exact void_items list it observed
 * (expected_void). The server only nulls the column when the stored value still
 * matches — if a newer cancellation replaced the list before the cook pressed
 * "Got it", the UPDATE hits 0 rows and a 409 is returned so the KDS refreshes
 * and shows the newer list instead.
 *
 * Locked here:
 *   1. Happy path: ack clears void_items and returns success.
 *   2. Stale ack (expected_void doesn't match stored) → 409, void_items untouched.
 *   3. Already-null void_items → idempotent success (another screen already ack'd).
 *   4. Cross-company isolation: cannot ack an order belonging to another company.
 *
 * Pattern mirrors PosRestaurantOrderCancelTest: sqlite :memory: + minimal schema,
 * controller invoked directly with the currentCompanyId container binding.
 */
class PosKdsAckVoidTest extends TestCase
{
    protected int $companyId;
    protected int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('pos_role')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->string('status')->default('held');
            $table->string('kitchen_status')->nullable();
            $table->text('void_items')->nullable();
            $table->timestamps();
        });

        $this->companyId = \DB::table('companies')->insertGetId(['name' => 'Test Shop', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherCompanyId = \DB::table('companies')->insertGetId(['name' => 'Other Shop', 'created_at' => now(), 'updated_at' => now()]);

        // Bind directly — the controller only reads currentCompanyId; no auth
        // middleware runs in a direct controller call, so Auth::login is not needed
        // (and would require creating the security_logs table).
        app()->instance('currentCompanyId', $this->companyId);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeOrder(int $companyId, ?string $voidItems): int
    {
        return \DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $companyId,
            'order_number' => 'ORD-' . rand(1000, 9999),
            'status'       => 'held',
            'void_items'   => $voidItems,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function callAckVoid(int $orderId, mixed $expectedVoid): \Illuminate\Http\JsonResponse
    {
        $request = Request::create("/pos/restaurant/orders/{$orderId}/ack-void", 'POST', [
            'expected_void' => $expectedVoid,
        ]);
        $request->headers->set('Accept', 'application/json');

        return (new RestaurantKdsController())->ackVoid($request, $orderId);
    }

    private function storedVoidItems(int $orderId): ?string
    {
        return \DB::table('restaurant_orders')->where('id', $orderId)->value('void_items');
    }

    // ── 1. Happy path ─────────────────────────────────────────────────────────

    /** Ack with the matching expected_void clears void_items and returns success. */
    public function test_ack_clears_void_items_when_expected_matches(): void
    {
        $voidList = [['item_name' => 'Burger', 'qty' => 1, 'notes' => null]];
        $id = $this->makeOrder($this->companyId, json_encode($voidList));

        $response = $this->callAckVoid($id, $voidList);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertNull($this->storedVoidItems($id), 'void_items must be NULL after ack');
    }

    /** Ack without sending expected_void still clears (permissive path). */
    public function test_ack_without_expected_void_clears(): void
    {
        $id = $this->makeOrder($this->companyId, json_encode([['item_name' => 'Pizza', 'qty' => 2, 'notes' => null]]));

        $response = $this->callAckVoid($id, null);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertNull($this->storedVoidItems($id));
    }

    // ── 2. Stale ack race ─────────────────────────────────────────────────────

    /**
     * If a newer cancellation replaced void_items before "Got it" was pressed,
     * the server returns 409 and void_items is NOT cleared — the cook must ack
     * the new list.
     */
    public function test_stale_ack_returns_409_and_leaves_void_items_intact(): void
    {
        $oldList = [['item_name' => 'Burger', 'qty' => 1, 'notes' => null]];
        $newList = [['item_name' => 'Burger', 'qty' => 1, 'notes' => null],
                    ['item_name' => 'Fries',  'qty' => 2, 'notes' => null]];

        // Server already has the newer list stored.
        $id = $this->makeOrder($this->companyId, json_encode($newList));

        // Client sends the old list it observed.
        $response = $this->callAckVoid($id, $oldList);

        $this->assertEquals(409, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict'] ?? false);

        // New list must still be in the DB — not cleared.
        $this->assertNotNull($this->storedVoidItems($id), 'void_items must NOT be cleared on stale ack');
    }

    // ── 3. Idempotent already-null ────────────────────────────────────────────

    /** When void_items is already NULL (another screen ack'd), return success. */
    public function test_ack_on_already_null_void_items_is_idempotent_success(): void
    {
        $id = $this->makeOrder($this->companyId, null);

        $response = $this->callAckVoid($id, [['item_name' => 'Tea', 'qty' => 1, 'notes' => null]]);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertNull($this->storedVoidItems($id));
    }

    // ── 4. Cross-company isolation ────────────────────────────────────────────

    /** Cannot ack an order that belongs to a different company — returns 404. */
    public function test_cross_company_ack_returns_404(): void
    {
        $otherId = $this->makeOrder($this->otherCompanyId, json_encode([['item_name' => 'Chai', 'qty' => 1, 'notes' => null]]));

        $response = $this->callAckVoid($otherId, [['item_name' => 'Chai', 'qty' => 1, 'notes' => null]]);

        $this->assertEquals(404, $response->getStatusCode());
        // Other company's void_items must remain untouched.
        $this->assertNotNull($this->storedVoidItems($otherId));
    }
}
