<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Services\KotPrintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1368 — "Delivery bill final ho to kitchen ki parchi sach mein jaye".
 *
 * Task 1356 taught the dine-in/counter finals that "did the kitchen see this?"
 * is answered by LINE-level restaurant_order_items.kot_printed_at, never by the
 * order-level kot_sent_at (hold stamps that on EVERY held order whether or not
 * a ticket was ever rendered). The delivery lane — the shop toggle
 * "delivery_kot_after_payment", where the ticket is deliberately held back until
 * payment confirms — was out of scope then and kept running on the old signal:
 * the F10 row said "ticket owed" whenever the TRANSACTION carried no stamp.
 *
 * Most delivery provisionals do have a restaurant order behind them (the sale
 * screen saves them through the internal hold → payOrder pass-through), so that
 * answer was wrong in both directions: a bill whose kitchen ticket had already
 * printed got a SECOND full slip at final, and the honest line stamps — the only
 * thing that knows a hold-stamped order was never actually printed — were never
 * consulted.
 *
 * Invariants under lock:
 *   • The signal comes from line stamps whenever the bill has an order; the
 *     order's own kot_sent_at is deliberately stamped in every fixture here, so
 *     any implementation that trusts it fails these tests.
 *   • An already-printed delivery order never earns a second slip.
 *   • What IS owed prints as a DELTA off that order (order_id in the payload),
 *     never a full reprint.
 *   • The shop toggle still gates the whole lane, and order-less bills keep the
 *     legacy transaction ticket — a real bill must never be left uncooked.
 *
 * NEVER "fix" a failure here by going back to kot_sent_at (order- or
 * transaction-level alone) — that is the bug this file exists for.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosDeliveryKotOnFinalTest.php --testdox
 */
class PosDeliveryKotOnFinalTest extends TestCase
{
    private const COMPANY_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('restaurant_mode')->default(true);
            $t->boolean('delivery_kot_after_payment')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number');
            $t->string('order_type')->default('delivery');
            $t->string('status')->default('completed');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->timestamp('kot_sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_name');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->timestamp('kot_printed_at')->nullable();
            $t->unsignedInteger('kot_batch_no')->nullable();
            $t->timestamps();
        });

        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'name' => 'Delivery KOT Co',
            'restaurant_mode' => true,
            'delivery_kot_after_payment' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', self::COMPANY_ID);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, bool>  $linesPrinted  one entry per line; true = the kitchen printed it
     */
    private function makeOrder(array $linesPrinted, int $txnId = 501): \App\Models\RestaurantOrder
    {
        $id = DB::table('restaurant_orders')->insertGetId([
            'company_id' => self::COMPANY_ID,
            'order_number' => 'ORD-' . uniqid(),
            'order_type' => 'delivery',
            'status' => 'completed',
            'pos_transaction_id' => $txnId,
            // Stamped on EVERY order on purpose: hold does this unconditionally,
            // which is exactly why it must not decide anything here.
            'kot_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($linesPrinted as $i => $printed) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $id,
                'item_name' => 'Item ' . ($i + 1),
                'quantity' => 1,
                'kot_printed_at' => $printed ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return \App\Models\RestaurantOrder::find($id);
    }

    /** A provisional bill row as the F10 list reads it (never persisted). */
    private function bill(array $attrs = []): PosTransaction
    {
        return (new PosTransaction())->setRawAttributes(array_merge([
            'id' => 501,
            'order_type' => 'delivery',
            'kot_sent_at' => null,
        ], $attrs));
    }

    private function company(array $attrs = []): Company
    {
        if ($attrs) {
            DB::table('companies')->where('id', self::COMPANY_ID)->update($attrs);
        }

        return Company::find(self::COMPANY_ID);
    }

    // ── 1. The two directions the task asks for ───────────────────────────

    public function test_hold_stamped_but_never_printed_delivery_order_still_owes_the_kitchen(): void
    {
        // The bug: kot_sent_at IS set (hold stamps it), but not one line ever
        // reached a printer, so the food was never started.
        $order = $this->makeOrder([false, false]);

        $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(), $order);

        $this->assertTrue($kot['pending'], 'A delivery bill whose lines never printed must send a ticket at final');
        $this->assertSame($order->id, $kot['order_id'], 'The ticket must be a DELTA off the linked order');
    }

    public function test_already_printed_delivery_order_never_gets_a_second_slip(): void
    {
        $order = $this->makeOrder([true, true]);

        $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(), $order);

        $this->assertFalse($kot['pending'], 'A delivery order the kitchen already cooked must not print again at final');
        $this->assertNull($kot['order_id']);
    }

    public function test_partly_printed_delivery_order_owes_only_the_unseen_lines(): void
    {
        // Edited/appended provisional: two lines are on the pass, one is new.
        $order = $this->makeOrder([true, true, false]);

        $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(), $order);

        $this->assertTrue($kot['pending']);
        $this->assertSame($order->id, $kot['order_id'], 'Delta only — a full reprint would re-fire dishes already made');
        $this->assertSame(1, KotPrintService::unseenLineCount($order));
    }

    // ── 2. The lane's own gates stay exactly as they were ─────────────────

    public function test_shop_toggle_off_keeps_the_lane_silent(): void
    {
        $order = $this->makeOrder([false]);

        $kot = KotPrintService::deliveryPromoteKot(
            $this->company(['delivery_kot_after_payment' => false]),
            $this->bill(),
            $order
        );

        $this->assertFalse($kot['pending'], 'delivery_kot_after_payment = OFF must behave exactly as before');
        $this->assertNull($kot['order_id']);
    }

    public function test_non_delivery_bills_are_not_part_of_this_lane(): void
    {
        $order = $this->makeOrder([false]);

        foreach (['dine_in', 'takeaway', null] as $type) {
            $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(['order_type' => $type]), $order);
            $this->assertFalse($kot['pending'], "order_type {$type} must be left to the Task 1356 path");
        }
    }

    public function test_transaction_stamp_still_means_the_slip_already_went(): void
    {
        // Cashier used F10 "Send KOT" on an order-less bill: the transaction
        // ticket rendered and stamped. That full slip covered the whole bill.
        $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(['kot_sent_at' => now()]), null);

        $this->assertFalse($kot['pending']);
    }

    // ── 3. Bills with no order to judge keep the legacy ticket ────────────

    public function test_order_less_delivery_bill_keeps_the_transaction_ticket(): void
    {
        // Manual-cart / deal delivery bills genuinely have no restaurant order.
        $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(), null);

        $this->assertTrue($kot['pending'], 'An order-less delivery bill must still get its ticket at final');
        $this->assertNull($kot['order_id'], 'No order => the ticket is rendered from the transaction');
    }

    public function test_an_order_with_no_lines_is_no_signal_at_all(): void
    {
        // Data anomaly: nothing to count either way. A missing slip is worse
        // than a duplicate one, so fall back to the transaction ticket.
        $order = $this->makeOrder([]);

        $kot = KotPrintService::deliveryPromoteKot($this->company(), $this->bill(), $order);

        $this->assertTrue($kot['pending']);
        $this->assertNull($kot['order_id']);
        $this->assertFalse(KotPrintService::deliveryPromoteKot($this->company(), null, $order)['pending']);
        $this->assertFalse(KotPrintService::deliveryPromoteKot(null, $this->bill(), $order)['pending']);
    }

    // ── 4. Wiring: the flag the sale screen acts on comes from this rule ──

    public function test_provisional_list_publishes_the_honest_flag_and_a_delta_target(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/PosController.php'));

        $this->assertStringNotContainsString(
            "empty(\$b->kot_sent_at)),",
            $src,
            'The F10 provisional row must not decide kot_pending from the transaction stamp alone'
        );
        $this->assertStringContainsString(
            "KotPrintService::deliveryPromoteKot(\$kotCompany, \$b, \$kotOrders[\$b->id] ?? null)",
            $src,
            'The provisional list must read the flag from the shared rule'
        );
        $this->assertStringContainsString("'kot_order_id'     => \$kot['order_id'],", $src);
    }

    public function test_sale_screen_prints_a_delta_off_the_linked_order(): void
    {
        $blade = file_get_contents(base_path('resources/views/pos/universal.blade.php'));

        // F10 "Send KOT" button.
        $this->assertStringContainsString(
            "if (bill.kot_order_id) { this.printKitchenTicket(bill.kot_order_id, null, true); return; }",
            $blade,
            'sendProvisionalKot must delta-print the linked order'
        );
        // Make Final (promote) release.
        $this->assertStringContainsString(
            "const promoKotOrderId = kotLaneOpen ? (bill.kot_order_id || null) : null;",
            $blade
        );
        $this->assertStringContainsString(
            "if (promoKotOrderId) this.printKitchenTicket(promoKotOrderId, null, true);",
            $blade,
            'The promote release must be a DELTA, never a full reprint'
        );
    }
}
