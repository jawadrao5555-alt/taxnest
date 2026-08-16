<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantPosController;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 636 — Cashier holdOrder: identity-autofill note discard.
 *
 * The browser/password-manager autofills the cashier's login identity
 * (email, email-prefix, phone, name) into the kitchen_notes header field
 * AND per-item special_notes on the sale screen. These are not real kitchen
 * instructions — the same identity-discard guard that was applied to the
 * waiter punch path (Task 632) must also apply to the cashier holdOrder
 * path (RestaurantPosController::holdOrder).
 *
 * Invariants locked here:
 *   1. kitchen_notes that EXACTLY matches the cashier's email is stored NULL.
 *   2. kitchen_notes that EXACTLY matches the cashier's phone is stored NULL.
 *   3. per-item special_notes that EXACTLY matches the email-prefix is NULL.
 *   4. A real kitchen instruction ("extra spicy") survives unchanged.
 *   5. A note that merely CONTAINS the identity (not exact match) survives.
 *   6. A real note on a held-order item with identity kitchen_notes: the item
 *      note persists while the header note is discarded.
 */
class PosHoldOrderIdentityNoteStripTest extends TestCase
{
    protected int $companyId;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->json('feature_flags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number', 30)->nullable()->unique();
            $table->string('token_no')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('source')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->unsignedSmallInteger('kot_print_count')->default(0);
            // Task 1001: hold_uuid idempotency key — must match live schema.
            $table->string('hold_uuid', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // holdOrder calls ProductRecipe::where(...)->with('ingredient')->get()
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('unit', 20)->nullable();
            $table->decimal('cost_per_unit', 15, 2)->default(0);
            $table->decimal('current_stock', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('quantity_needed', 10, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('item_type', 20)->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 10, 2)->default(0);
            $table->decimal('item_discount_amount', 10, 2)->default(0);
            $table->timestamp('kot_printed_at')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Test Karahi',
            'is_internal_account' => true, // skip subscription/plan lookup
            'feature_flags' => json_encode([]),  // all flags false → typeFlowGate=false
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        // Cashier with identifiable email + phone
        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name'       => 'Ali Cashier',
            'username'   => 'alicashier',
            'email'      => 'alicashier@taxnest.com',
            'phone'      => '03001234567',
            'role'       => 'user',
            'pos_role'   => 'pos_cashier',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cashier = User::orderByDesc('id')->first();
        Auth::guard('pos')->setUser($this->cashier);

        // Clear PosFeatureService per-request caches between tests
        PosFeatureService::flushGateCaches();
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    /** Build a minimal holdOrder Request with given kitchen_notes + item notes. */
    protected function holdRequest(
        ?string $kitchenNotes,
        array $itemNotes = []
    ): Request {
        $items = [];
        foreach ($itemNotes as $note) {
            $items[] = [
                'item_type'   => 'manual',
                'item_name'   => 'Karahi',
                'unit_price'  => 500,
                'quantity'    => 1,
                'special_notes' => $note,
            ];
        }
        if (empty($items)) {
            $items[] = [
                'item_type'  => 'manual',
                'item_name'  => 'Karahi',
                'unit_price' => 500,
                'quantity'   => 1,
            ];
        }

        $payload = [
            'items'          => $items,
            'order_type'     => 'takeaway',
            'kitchen_notes'  => $kitchenNotes,
        ];

        return Request::create('/pos/restaurant/hold', 'POST', $payload);
    }

    protected function hold(Request $request): \Illuminate\Http\JsonResponse
    {
        return (new RestaurantPosController())->holdOrder($request);
    }

    protected function lastOrder(): ?\stdClass
    {
        return DB::table('restaurant_orders')->orderByDesc('id')->first();
    }

    protected function lastItem(): ?\stdClass
    {
        return DB::table('restaurant_order_items')->orderByDesc('id')->first();
    }

    // ── 1. kitchen_notes = exact email → stored as NULL ──────────────────────

    public function test_kitchen_notes_exact_email_is_discarded(): void
    {
        $resp = $this->hold($this->holdRequest('alicashier@taxnest.com'));
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'holdOrder should succeed: ' . ($data['message'] ?? ''));
        $this->assertNull($this->lastOrder()->kitchen_notes, 'Email autofilled as kitchen_notes must be stored NULL');
    }

    // ── 2. kitchen_notes = exact phone → stored as NULL ──────────────────────

    public function test_kitchen_notes_exact_phone_is_discarded(): void
    {
        $resp = $this->hold($this->holdRequest('03001234567'));
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'holdOrder should succeed');
        $this->assertNull($this->lastOrder()->kitchen_notes);
    }

    // ── 3. per-item special_notes = email-prefix → stored as NULL ────────────

    public function test_item_note_exact_email_prefix_is_discarded(): void
    {
        $resp = $this->hold($this->holdRequest(null, ['alicashier']));
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'holdOrder should succeed');
        $this->assertNull($this->lastItem()->special_notes, 'Email-prefix autofilled into item note must be stored NULL');
    }

    // ── 4. real kitchen instruction survives unchanged ────────────────────────

    public function test_real_kitchen_note_is_preserved(): void
    {
        $resp = $this->hold($this->holdRequest('extra spicy', ['no onions']));
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'holdOrder should succeed');

        $order = $this->lastOrder();
        $item  = DB::table('restaurant_order_items')
            ->where('order_id', $order->id)->orderByDesc('id')->first();

        $this->assertSame('extra spicy', $order->kitchen_notes, 'Real kitchen instruction must survive');
        $this->assertSame('no onions', $item->special_notes, 'Real item note must survive');
    }

    // ── 5. note that CONTAINS identity (not exact match) survives ─────────────

    public function test_note_containing_identity_is_not_stripped(): void
    {
        $note = 'table ke liye 03001234567 call karo';
        $resp = $this->hold($this->holdRequest($note));
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'holdOrder should succeed');
        $this->assertSame($note, $this->lastOrder()->kitchen_notes, 'Note that merely contains identity must survive');
    }

    // ── 6. identity kitchen_notes discarded but real item note preserved ──────

    public function test_identity_kitchen_note_discarded_while_real_item_note_preserved(): void
    {
        $resp = $this->hold($this->holdRequest('alicashier@taxnest.com', ['boneless']));
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'holdOrder should succeed');

        $order = $this->lastOrder();
        $item  = DB::table('restaurant_order_items')
            ->where('order_id', $order->id)->orderByDesc('id')->first();

        $this->assertNull($order->kitchen_notes, 'Identity kitchen_notes must be NULL');
        $this->assertSame('boneless', $item->special_notes, 'Real item note must survive');
    }
}
