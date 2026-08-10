<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;

/**
 * KOT LAYOUT LOCK — Task 395 (9 Aug 2026) + Task 397 reconcile (10 Aug 2026,
 * Pizza Master follow-up via owner).
 *
 * The compact KOT rendering has real customer history behind every line:
 *   • TOKEN style: the big bordered token box REPLACES the long ORD- line
 *     (token is the call-out number); KOT # rides the SAME line.
 *   • CODE style (10 Aug 2026, Pizza Master: "chhota boxed code pyara nahi
 *     lagta"): the FULL bold ORD- order number prints, NO box. Matching still
 *     works — the receipt's short code is the number's last segment. The box
 *     remains for TOKEN style ONLY.
 *   • KOT # prints only from batch #2 onward (10 Aug 2026: "KOT #1" carries
 *     no info on the first ticket).
 *   • style 'off': the plain ORD- line prints (+ inline "— KOT #N" when ≥2).
 *   • Transaction-shim KOTs (order-less delivery bills; $order->exists ===
 *     false, kotBatchNo null): the bill/invoice number line MUST print —
 *     it is the only identity the ticket carries.
 *   • priority=true: URGENT is a small plain bold badge riding INLINE at the
 *     end of the order-by footer line (10 Aug 2026 photo: top placement
 *     wasted paper). Standalone line ONLY when kot_show_orderby is OFF.
 *     Never a bordered/reversed block (prints as a faint dotted box on
 *     thermal printers).
 *
 * These are RENDERED-VIEW tests: the blade is rendered directly with the
 * exact variable set both controller render sites pass
 * (RestaurantPosController::kitchenTicket / ::renderTransactionKot), so any
 * future template edit that re-introduces the boxed short code, a bordered
 * URGENT block, a top-of-ticket URGENT, "KOT #1" on first tickets, or hides
 * the shim's bill number fails here. The tests moved to the 10 Aug design —
 * never "fix" a failure here by reverting kitchen-ticket.blade.php.
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

    private function makeCompany(string $style, array $attrs = []): Company
    {
        $company = new Company();
        $company->name = 'KOT Layout Co';
        $company->order_match_style = $style;
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }
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

    private function stripCss(string $html): string
    {
        return preg_replace('/<style\b[^>]*>.*?<\/style>/s', '', $html) ?? $html;
    }

    // ── 1. style = 'token' — the ONLY style that boxes ──────────────────

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

    // ── 2. style = 'code' — FULL bold ORD number, NO box (10 Aug 2026) ──

    public function test_code_style_prints_full_order_number_without_box(): void
    {
        $company = $this->makeCompany('code');
        $order = $this->makeOrder(); // exists=true → DB-backed order

        $body = $this->body($this->render($company, $order, 3));

        // The FULL ORD- number prints (bold line) — owner-approved 10 Aug design
        $this->assertStringContainsString(self::ORDER_NUMBER, $body, 'full ORD- order number prints for code style');
        // NO bordered box in code mode — the box is TOKEN-only now
        $this->assertStringNotContainsString('border:2px solid #000; padding:2px 10px', $body, 'no boxed code — box is token-style only');
        // KOT # rides the same line (batch 3 ≥ 2 → shown)
        $this->assertStringContainsString(self::ORDER_NUMBER . ' <span class="text-sm bold">&mdash; KOT #3</span>', $body, 'KOT # inline with ORD- line');
    }

    public function test_code_style_with_null_batch_prints_plain_ord_line(): void
    {
        $company = $this->makeCompany('code');
        $order = $this->makeOrder();

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringContainsString(self::ORDER_NUMBER, $body);
        $this->assertStringNotContainsString('KOT #', $body);
        $this->assertStringNotContainsString('border:2px solid #000; padding:2px 10px', $body);
    }

    // ── 3. KOT # only from batch #2 onward (10 Aug 2026) ────────────────

    public function test_first_batch_never_prints_kot_number(): void
    {
        // "KOT #1" carries no info on the FIRST ticket — suppressed for every style.
        foreach (['token', 'code', 'off'] as $style) {
            $company = $this->makeCompany($style);
            $order = $this->makeOrder($style === 'token' ? ['token_no' => 42] : []);

            $body = $this->body($this->render($company, $order, 1));

            $this->assertStringNotContainsString('KOT #', $body, "no KOT #1 on first ticket (style {$style})");
        }
    }

    // ── 4. style = 'off' ─────────────────────────────────────────────────

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

    // ── 5. Transaction-shim KOT (order-less bill) ────────────────────────

    public function test_transaction_shim_always_prints_bill_number(): void
    {
        // renderTransactionKot: unsaved shim, exists === false, kotBatchNo null.
        // Code style now prints the plain number line for shims too — the BILL
        // number always prints, never a box.
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

    // ── 6. URGENT — inline on the order-by footer line (10 Aug 2026) ─────

    public function test_priority_rides_inline_on_orderby_footer_line(): void
    {
        // kot_show_orderby defaults ON → URGENT is a small span at the END of
        // the order-by line, never its own line, never at the top.
        $company = $this->makeCompany('off');
        $order = $this->makeOrder(['priority' => true]);
        $order->priority = true;

        $html = $this->render($company, $order, null);
        $body = $this->body($html);

        // Inline: badge span sits on the same <p> as the order-by text
        $this->assertMatchesRegularExpression(
            '/<p>[^<]*' . preg_quote(__('pos.kot_order_by'), '/') . '.*<span class="priority-badge">' . preg_quote(__('pos.kot_rush'), '/') . '<\/span><\/p>/s',
            $body,
            'URGENT rides inline at the end of the order-by footer line'
        );
        // No standalone URGENT line/block anywhere (old top placement dead)
        $this->assertStringNotContainsString('<p class="priority-badge', $body, 'no standalone URGENT <p> line');
        $this->assertSame(1, substr_count($this->stripCss($body), 'priority-badge'), 'URGENT prints exactly once');

        // The .priority-badge CSS rule must stay border-free and non-reversed
        $this->assertSame(1, preg_match('/\.priority-badge\s*\{([^}]*)\}/s', $html, $m), '.priority-badge rule present');
        $css = $m[1];
        $this->assertStringNotContainsString('border', $css, 'no border on URGENT badge');
        $this->assertStringNotContainsString('padding', $css, 'no padded block on URGENT badge');
        $this->assertStringNotContainsString('background: #000', $css, 'no reversed (white-on-black) URGENT block');
    }

    public function test_priority_standalone_line_only_when_orderby_footer_off(): void
    {
        // Fallback: company hid the order-by footer line → URGENT must still
        // print, on its own small centered line.
        $company = $this->makeCompany('off', ['kot_show_orderby' => false]);
        $order = $this->makeOrder(['priority' => true]);
        $order->priority = true;

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringNotContainsString(__('pos.kot_order_by'), $body, 'order-by line hidden');
        $this->assertStringContainsString('<div class="text-center"><span class="priority-badge">' . __('pos.kot_rush') . '</span></div>', $body, 'standalone URGENT fallback line prints');
        $this->assertSame(1, substr_count($this->stripCss($body), 'priority-badge'), 'URGENT prints exactly once');
    }

    public function test_no_priority_no_urgent_line(): void
    {
        $company = $this->makeCompany('off');
        $order = $this->makeOrder();

        $body = $this->body($this->render($company, $order, null));

        $this->assertStringNotContainsString('priority-badge', $this->stripCss($body));
        $this->assertStringNotContainsString(__('pos.kot_rush'), $this->stripCss($body));
    }
}
