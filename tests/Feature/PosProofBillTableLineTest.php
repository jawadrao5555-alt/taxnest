<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;

/**
 * PROOF-BILL TABLE LINE LOCK — Task 1386 (21 Aug 2026).
 *
 * The customer-facing pre-bill used to print the label, a hard-coded "T-" and
 * then the shop's own table name, so a shop that names its tables
 * "Table No 01" handed the customer a slip reading "TABLE NO: T-Table No 01".
 * The name now prints exactly as the shop stored it; the localized label is
 * added ONLY when the name doesn't already read as a table, and the line never
 * wraps mid-name on an 80mm roll (font steps down with the length — same rule
 * the kitchen slip got in Task 1378, see PosKitchenTicketLayoutTest).
 *
 * RENDERED-VIEW tests: the blade is rendered with the exact variable set both
 * render sites pass (RestaurantPosController::proofBill / AgentController's
 * 'proof' print job), using unsaved model shims — no DB tables needed.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosProofBillTableLineTest.php --testdox
 */
class PosProofBillTableLineTest extends TestCase
{
    /** Font size captured by tableLine() from the rendered table line. */
    private int $tableLineFont = 0;

    // ── Render helpers ───────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $company->name = 'Proof Bill Co';
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }

        return $company;
    }

    /** Order shim carrying one item and the totals the pre-bill prints. */
    private function makeOrder(array $attrs = []): RestaurantOrder
    {
        $order = new RestaurantOrder(array_merge([
            'order_number' => 'ORD-260821-AB12C',
            'order_type' => 'takeaway',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 1000,
        ], $attrs));
        $order->exists = true;
        $order->created_at = now();

        $item = new RestaurantOrderItem([
            'item_name' => 'Chicken Tikka Pizza',
            'quantity' => 2,
            'unit_price' => 500,
        ]);
        $item->id = 1;

        $order->setRelation('items', collect([$item]));
        $order->setRelation('table', null);
        $order->setRelation('creator', null);

        return $order;
    }

    /** Dine-in order seated at a table (unsaved relation shim — no DB needed). */
    private function makeDineInOrder(string $tableNumber): RestaurantOrder
    {
        $order = $this->makeOrder(['order_type' => 'dine_in']);
        $order->setRelation('table', new RestaurantTable(['table_number' => $tableNumber]));

        return $order;
    }

    private function render(Company $company, RestaurantOrder $order): string
    {
        return view('pos.restaurant.proof-bill', compact('order', 'company'))->render();
    }

    /** Markup AFTER </head> — the <title> legitimately carries order_number. */
    private function body(string $html): string
    {
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered pre-bill has a </head>');

        return substr($html, $pos);
    }

    /** Text printed on the table line (fails when the line is absent). */
    private function tableLine(string $body): string
    {
        $this->assertSame(
            1,
            preg_match('/<div class="text-center bold proof-table-line" style="font-size:(\d+)px;[^"]*">(.*?)<\/div>/s', $body, $m),
            'the pre-bill prints a table line'
        );
        $this->tableLineFont = (int) $m[1];

        return trim($m[2]);
    }

    // ── 1. The bug: "T-" must never double a self-labelled name ──────────

    public function test_table_name_prints_exactly_as_the_shop_stored_it(): void
    {
        $order = $this->makeDineInOrder('Table No 01');

        $body = $this->body($this->render($this->makeCompany(), $order));

        $this->assertSame('Table No 01', $this->tableLine($body), 'name prints whole, verbatim');
        $this->assertStringNotContainsString('T-Table', $body, 'the old T- prefix must never double a "Table …" name');
        $this->assertStringNotContainsString(__('pos.proof_table_no') . ' Table No 01', $body, 'no second label in front of a self-labelled name');
    }

    public function test_t_shorthand_name_gets_no_extra_label(): void
    {
        // "T-4" / "T4" / "T 4" already read as a table — nothing bolted on.
        foreach (['T-4', 'T4', 'T 4'] as $name) {
            $order = $this->makeDineInOrder($name);

            $body = $this->body($this->render($this->makeCompany(), $order));

            $this->assertSame($name, $this->tableLine($body), "shorthand {$name} prints verbatim");
        }
    }

    // ── 2. Short names keep a clear, localized label ─────────────────────

    public function test_bare_table_number_still_reads_as_a_table(): void
    {
        $order = $this->makeDineInOrder('01');

        $body = $this->body($this->render($this->makeCompany(), $order));

        // Label is localized — never a hard-coded word, never a "T-".
        $this->assertSame(__('pos.proof_table_no') . ' 01', $this->tableLine($body));
        $this->assertStringNotContainsString('T-01', $body);
        // Short line keeps the original 20px customer-facing size.
        $this->assertSame(20, $this->tableLineFont, 'short table line keeps its 20px size');
    }

    // ── 3. The longest stored name still fits an 80mm roll ───────────────

    public function test_longest_possible_name_stays_on_one_line(): void
    {
        // table_number is capped at 20 chars in the DB; with the label that is
        // the widest line the pre-bill can ever print — the font must step down
        // (never wrap, never truncate).
        $longest = 'Family Hall Corner 7';           // 20 chars
        $this->assertSame(20, strlen($longest));

        $order = $this->makeDineInOrder($longest);

        $html = $this->render($this->makeCompany(), $order);
        $body = $this->body($html);

        $this->assertSame(__('pos.proof_table_no') . ' ' . $longest, $this->tableLine($body), 'long name prints in full');
        $this->assertLessThanOrEqual(13, $this->tableLineFont, 'long name must step the font down to fit 72mm');

        // The line itself must be nowrap — a name broken in two is the bug.
        $this->assertSame(1, preg_match('/\.proof-table-line\s*\{([^}]*)\}/s', $html, $m), '.proof-table-line rule present');
        $this->assertStringContainsString('white-space: nowrap', $m[1], 'table line must never wrap');
    }

    // ── 4. Nothing else on the pre-bill layout changes ───────────────────

    public function test_order_without_a_table_prints_the_order_type_as_before(): void
    {
        // Takeaway / delivery pre-bills carry no table: the same centered line
        // keeps printing the order type at its original 20px.
        $order = $this->makeOrder();   // table relation = null

        $body = $this->body($this->render($this->makeCompany(), $order));

        $this->assertStringNotContainsString('proof-table-line', $body, 'no empty table line on a takeaway pre-bill');
        $this->assertStringContainsString(
            '<div class="text-center bold" style="font-size:20px; margin-top:2px;">',
            $body,
            'order-type line keeps its original size'
        );
        $this->assertStringContainsString(__('pos.ot_takeaway'), $body);
    }

    public function test_rest_of_the_pre_bill_still_prints(): void
    {
        $order = $this->makeDineInOrder('Table No 01');

        $body = $this->body($this->render($this->makeCompany(), $order));

        $this->assertStringContainsString(__('pos.proof_bill_banner'), $body, 'PROOF BILL banner prints');
        $this->assertStringContainsString(__('pos.proof_bill_no') . ' ' . $order->order_number, $body, 'bill number prints');
        $this->assertStringContainsString('Chicken Tikka Pizza', $body, 'items print');
        $this->assertStringContainsString(__('pos.proof_not_paid'), $body, 'NOT PAID marker prints');
    }
}
