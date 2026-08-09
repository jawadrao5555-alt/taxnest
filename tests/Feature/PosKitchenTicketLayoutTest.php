<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;

/**
 * KOT LAYOUT LOCK — Task 395 (9 Aug 2026, Pizza Master / E-ICEBLUE compaction).
 *
 * The compact KOT rendering has real customer history behind every line:
 *   • SINGLE SERIAL: when the token/code box prints, it REPLACES the long
 *     ORD- order-number line (code IS its last segment — printing both read
 *     as a confusing "double serial"); KOT #N rides the SAME line.
 *   • style 'off': the plain ORD- line prints (+ inline "— KOT #N" when set).
 *   • Transaction-shim KOTs (order-less delivery bills; $order->exists ===
 *     false, kotBatchNo null): the bill/invoice number line MUST print —
 *     it is the only identity the ticket carries.
 *   • priority=true: URGENT is ONE plain bold line — no bordered block
 *     (the old 3-line reversed block printed as a faint dotted box and
 *     wasted paper).
 *
 * These are RENDERED-VIEW tests: the blade is rendered directly with the
 * exact variable set both controller render sites pass
 * (RestaurantPosController::kitchenTicket / ::renderTransactionKot), so any
 * future template edit that re-introduces a double serial, a bordered URGENT
 * block, or hides the shim's bill number fails here.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: (no tables needed — unsaved
 * model shims, exists toggled explicitly, exactly like renderTransactionKot).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosKitchenTicketLayoutTest.php --testdox
 */
class PosKitchenTicketLayoutTest extends TestCase
{
    private const ORDER_NUMBER = 'ORD-260809-AB12C';
    private const SHORT_CODE   = 'AB12C'; // OrderTokenService::shortCode(ORDER_NUMBER)

    // ── Render helpers ───────────────────────────────────────────────────

    private function makeCompany(string $style): Company
    {
        $company = new Company();
        $company->name = 'KOT Layout Co';
        $company->order_match_style = $style;
        return $company;
    }

    /**
     * Order shim — same construction as renderTransactionKot. $exists mirrors
     * the two real render sites: true = DB-backed restaurant order
     * (kitchenTicket), false = transaction shim (renderTransactionKot).
     */
    private function makeOrder(array $attrs = [], bool $exists = true): RestaurantOrder
    {
        $order = new RestaurantOrder(array_merge([
            'order_number' => self::ORDER_NUMBER,
            'order_type' => 'takeaway',
            'customer_name' => null,
        ], $attrs));
        $order->exists = $exists;
        $order->created_at = now();
        $order->kot_print_count = 1;
        $order->priority = $attrs['priority'] ?? false;
        $order->kitchen_notes = null;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);
        return $order;
    }

    private function makeItems(): \Illuminate\Support\Collection
    {
        $item = new RestaurantOrderItem([
            'item_type' => 'manual',
            'item_id' => null,
            'item_name' => 'Chicken Tikka Pizza',
            'quantity' => 2,
            'unit_price' => 500,
            'special_notes' => null,
        ]);
        $item->id = 1;
        return collect([$item]);
    }

    private function render(Company $company, RestaurantOrder $order, ?int $kotBatchNo): string
    {
        $items = $this->makeItems();
        return view('pos.restaurant.kitchen-ticket', [
            'order' => $order,
            'company' => $company,
            'ticketItems' => $items,
            'grouped' => collect(['ALL' => $items]),
            'stationLabel' => null,
            'delta' => false,
            'kotBatchNo' => $kotBatchNo,
            'newItemIds' => collect(),
        ])->render();
    }

    /** Markup AFTER </head> — the <title> legitimately carries order_number. */
    private function body(string $html): string
    {
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered ticket has a </head>');
        return substr($html, $pos);
    }

    // ── 1. style = 'token' ───────────────────────────────────────────────

    public function test_token_style_prints_token_box_and_drops_ord_line(): void
    {
        $company = $this->makeCompany('token');
        $order = $this->makeOrder(['token_no' => 42]);

        $html = $this->render($company, $order, 7);
        $body = $this->body($html);

        // Token box prints
        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 42', $body, 'token box prints TOKEN 42');
        // Single serial: the long ORD- number must NOT print in the body
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, 'ORD- line replaced by token box');
        // KOT # rides the SAME line as the token box (no own line)
        $this->assertStringContainsString('42</span> <span class="text-sm bold">KOT #7</span></p>', $body, 'KOT # inline with token box');
    }

    public function test_token_style_with_null_batch_prints_box_without_kot_number(): void
    {
        $company = $this->makeCompany('token');
        $order = $this->makeOrder(['token_no' => 42]);

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 42', $body);
        $this->assertStringNotContainsString('KOT #', $body, 'no KOT # when batch is null');
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body);
    }

    // ── 2. style = 'code' ────────────────────────────────────────────────

    public function test_code_style_prints_short_code_box_and_drops_ord_line(): void
    {
        $company = $this->makeCompany('code');
        $order = $this->makeOrder(); // exists=true → code derives from order_number

        $body = $this->body($this->render($company, $order, 3));

        // Code box = last ORD segment, boxed (border style on the span)
        $this->assertMatchesRegularExpression(
            '/<span style="[^"]*border:2px solid #000[^"]*">' . self::SHORT_CODE . '<\/span>/',
            $body,
            'short-code box prints'
        );
        // The full ORD- number must be gone (code IS its last segment)
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, 'ORD- line replaced by code box');
        $this->assertStringContainsString('KOT #3', $body, 'KOT # inline when batch set');
    }

    public function test_code_style_with_null_batch_omits_kot_number(): void
    {
        $company = $this->makeCompany('code');
        $order = $this->makeOrder();

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringContainsString(self::SHORT_CODE . '</span>', $body);
        $this->assertStringNotContainsString('KOT #', $body);
    }

    // ── 3. style = 'off' ─────────────────────────────────────────────────

    public function test_off_style_prints_ord_line_with_inline_kot_number(): void
    {
        $company = $this->makeCompany('off');
        $order = $this->makeOrder();

        $body = $this->body($this->render($company, $order, 5));

        $this->assertStringContainsString(self::ORDER_NUMBER, $body, 'ORD- order number prints');
        // Inline on the SAME <p>: "ORD-… — KOT #5"
        $this->assertStringContainsString(self::ORDER_NUMBER . ' <span class="text-sm bold">&mdash; KOT #5</span>', $body, 'KOT # inline with ORD- line');
        // No token/code box in off mode
        $this->assertStringNotContainsString('border:2px solid #000; padding:2px 10px', $body, 'no token/code box');
    }

    public function test_off_style_without_batch_prints_plain_ord_line(): void
    {
        $company = $this->makeCompany('off');
        $order = $this->makeOrder();

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringContainsString(self::ORDER_NUMBER, $body);
        $this->assertStringNotContainsString('KOT #', $body);
    }

    // ── 4. Transaction-shim KOT (order-less bill) ────────────────────────

    public function test_transaction_shim_always_prints_bill_number(): void
    {
        // renderTransactionKot: unsaved shim, exists === false, kotBatchNo null.
        // Even with 'code' style ON, omCode requires $order->exists — the shim
        // falls through to the plain line, so the BILL number always prints.
        $company = $this->makeCompany('code');
        $order = $this->makeOrder(['order_number' => 'INV-000123'], exists: false);

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringContainsString('INV-000123', $body, 'shim KOT prints the bill/invoice number');
        $this->assertStringNotContainsString('border:2px solid #000; padding:2px 10px', $body, 'shim never prints a code box');
    }

    public function test_transaction_shim_prints_bill_number_in_token_mode_without_token(): void
    {
        // Token style but no token_no on the shim (txn bills carry none) —
        // must still print the bill number line.
        $company = $this->makeCompany('token');
        $order = $this->makeOrder(['order_number' => 'INV-000456', 'token_no' => null], exists: false);

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringContainsString('INV-000456', $body);
    }

    // ── 5. URGENT = single plain line, no bordered block ─────────────────

    public function test_priority_renders_single_line_urgent_without_border_block(): void
    {
        $company = $this->makeCompany('off');
        $order = $this->makeOrder(['priority' => true]);
        $order->priority = true;

        $html = $this->render($company, $order, null);
        $body = $this->body($html);

        // The badge prints as ONE plain <p> line
        $this->assertStringContainsString('<p class="priority-badge mt-1">' . __('pos.kot_rush') . '</p>', $body, 'URGENT is a single plain line');

        // The .priority-badge CSS rule must stay border-free and non-reversed
        $this->assertSame(1, preg_match('/\.priority-badge\s*\{([^}]*)\}/s', $html, $m), '.priority-badge rule present');
        $css = $m[1];
        $this->assertStringNotContainsString('border', $css, 'no border on URGENT badge');
        $this->assertStringNotContainsString('padding', $css, 'no padded block on URGENT badge');
        $this->assertStringNotContainsString('background: #000', $css, 'no reversed (white-on-black) URGENT block');
    }

    public function test_no_priority_no_urgent_line(): void
    {
        $company = $this->makeCompany('off');
        $order = $this->makeOrder();

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringNotContainsString('priority-badge mt-1">', $body === '' ? $body : $this->stripCss($body));
        $this->assertStringNotContainsString(__('pos.kot_rush'), $this->stripCss($body));
    }

    private function stripCss(string $html): string
    {
        return preg_replace('/<style\b[^>]*>.*?<\/style>/s', '', $html) ?? $html;
    }
}
