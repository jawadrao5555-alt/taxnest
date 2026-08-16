<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 993 (owner voice note, 16 Aug 2026) — Takeaway KOT prints the SAME
 * number as the bill for punched-and-paid orders.
 *
 * A takeaway/delivery paid on the sale screen goes hold → pay → auto-print:
 * at KOT render time the finalized PosTransaction is already linked via
 * pos_transaction_id. With order_match_style 'off' the KOT used to fall back
 * to the raw ORD- line while the receipt showed the bill token / L-serial —
 * counter staff couldn't pair slips.
 *
 * Invariants under lock:
 *   • Paid order + no order-match identifier + token-style stream: the KOT
 *     header prints the BILL token big (bordered box, same design as the
 *     Task 777 shim KOT) with the serial as a small Ref line. The ORD- line
 *     must NOT print (single identifier — owner's standing rule).
 *   • Paid order + serial-style stream: the bill's invoice number replaces
 *     the ORD- line.
 *   • Pre-payment KOTs (no pos_transaction_id): ORD- behavior unchanged.
 *   • Order-match TOKEN companies: the order token box keeps priority —
 *     never two competing numbers, no bill Ref line.
 *   • Order-match CODE companies: full ORD- line unchanged (the receipt's
 *     code is its last segment — that pairing already works).
 *   • Stream resolution mirrors receipts (isLocalBill/isExemptStream +
 *     local_number_style / pra_number_style per stream).
 *   • Delta/reprint renders (kotBatchNo ≥ 2) carry the same bill number with
 *     KOT #N riding the same line.
 *
 * Never "fix" a failure here by reverting kitchen-ticket.blade.php to the
 * old ORD- fallback for paid orders.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosPaidOrderKotBillNumberTest.php --testdox
 */
class PosPaidOrderKotBillNumberTest extends TestCase
{
    private const COMPANY_ID = 701;
    private const TXN_ID = 8201;
    private const ORDER_NUMBER = 'ORD-260816-499F9';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal pos_transactions — the blade's guarded lookup (find +
        // hasColumn('pos_transactions','bill_token') + isLocalBill columns).
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->unsignedInteger('bill_token')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
        });
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeCompany(string $omStyle = 'off', array $attrs = []): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Paid KOT Co';
        $company->order_match_style = $omStyle;
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }
        return $company;
    }

    /** DB-backed order shim — exists=true, exactly like kitchenTicket()'s load. */
    private function makeOrder(array $attrs = []): RestaurantOrder
    {
        $order = new RestaurantOrder(array_merge([
            'order_number' => self::ORDER_NUMBER,
            'order_type' => 'takeaway',
            'customer_name' => null,
        ], $attrs));
        $order->exists = true;
        $order->company_id = self::COMPANY_ID;
        $order->created_at = now();
        $order->kot_print_count = 1;
        $order->priority = false;
        $order->kitchen_notes = null;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);
        return $order;
    }

    private function insertTxn(array $attrs = []): void
    {
        PosTransaction::withoutGlobalScope('hide_archived')->insert(array_merge([
            'id' => self::TXN_ID,
            'company_id' => self::COMPANY_ID,
            'invoice_number' => 'L-000141',
            'invoice_mode' => 'local',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'bill_token' => null,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function render(Company $company, RestaurantOrder $order, ?int $kotBatchNo = null, bool $delta = false): string
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
        $items = collect([$item]);

        return view('pos.restaurant.kitchen-ticket', [
            'order' => $order,
            'company' => $company,
            'ticketItems' => $items,
            'grouped' => collect(['ALL' => $items]),
            'stationLabel' => null,
            'delta' => $delta,
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

    // ── 1. Paid takeaway, style off, token-style local stream ────────────

    public function test_paid_takeaway_token_stream_prints_bill_token_with_serial_ref(): void
    {
        $this->insertTxn(['bill_token' => 4]);
        $company = $this->makeCompany('off', ['local_number_style' => 'token']);
        $order = $this->makeOrder();
        $order->pos_transaction_id = self::TXN_ID;

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 4', $body, 'bill token box prints big');
        $this->assertStringContainsString(__('pos.bill_ref_label') . ': L-000141', $body, 'serial rides as small Ref');
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, 'ORD- line replaced — single identifier');
    }

    // ── 2. Paid takeaway, style off, serial-style stream ─────────────────

    public function test_paid_takeaway_serial_stream_prints_invoice_number(): void
    {
        $this->insertTxn(['bill_token' => 4]); // token exists but style is serial
        $company = $this->makeCompany('off', ['local_number_style' => 'serial']);
        $order = $this->makeOrder();
        $order->pos_transaction_id = self::TXN_ID;

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString('L-000141', $body, 'bill invoice number replaces ORD- line');
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body);
        $this->assertStringNotContainsString('border:2px solid #000; padding:2px 10px', $body, 'no token box in serial style');
    }

    // ── 3. Stream branch mirrors receipts ─────────────────────────────────

    public function test_pra_stream_bill_uses_pra_number_style_not_local(): void
    {
        // Fiscal bill: local style says token, PRA style says serial —
        // the PRA-stream bill must follow pra_number_style (serial).
        $this->insertTxn([
            'invoice_number' => 'INV-000155',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-XYZ',
            'bill_token' => 9,
        ]);
        $company = $this->makeCompany('off', ['local_number_style' => 'token', 'pra_number_style' => 'serial']);
        $order = $this->makeOrder();
        $order->pos_transaction_id = self::TXN_ID;

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString('INV-000155', $body, 'PRA-stream serial style prints the invoice number');
        $this->assertStringNotContainsString(__('pos.order_match_token_label') . ' 9', $body, 'no bill token box — pra_number_style is serial');
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body);
    }

    // ── 4. Pre-payment KOTs unchanged ─────────────────────────────────────

    public function test_unpaid_order_keeps_ord_line(): void
    {
        $company = $this->makeCompany('off', ['local_number_style' => 'token']);
        $order = $this->makeOrder(); // pos_transaction_id null — hold / waiter send

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString(self::ORDER_NUMBER, $body, 'pre-payment KOT keeps the ORD- line');
        $this->assertStringNotContainsString(__('pos.bill_ref_label'), $body);
    }

    // ── 5. Order-match token companies keep their box (no competing #) ───

    public function test_order_match_token_keeps_priority_over_bill_number(): void
    {
        $this->insertTxn(['bill_token' => 4]);
        $company = $this->makeCompany('token', ['local_number_style' => 'token']);
        $order = $this->makeOrder(['token_no' => 42]);
        $order->pos_transaction_id = self::TXN_ID;

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 42', $body, 'order-match token box wins');
        $this->assertStringNotContainsString('L-000141', $body, 'no competing bill number');
        $this->assertStringNotContainsString(__('pos.bill_ref_label'), $body, 'no bill Ref line beside the order token');
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body);
    }

    // ── 6. Order-match code companies unchanged ──────────────────────────

    public function test_order_match_code_keeps_full_ord_line_even_when_paid(): void
    {
        $this->insertTxn(['bill_token' => 4]);
        $company = $this->makeCompany('code', ['local_number_style' => 'token']);
        $order = $this->makeOrder();
        $order->pos_transaction_id = self::TXN_ID;

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString(self::ORDER_NUMBER, $body, 'code style keeps the full ORD- line');
        $this->assertStringNotContainsString('L-000141', $body, 'no competing bill number in code style');
        $this->assertStringNotContainsString('border:2px solid #000; padding:2px 10px', $body, 'no box in code style');
    }

    // ── 7. Delta/reprint renders carry the same number + KOT #N ──────────

    public function test_delta_reprint_of_paid_order_shows_same_token_with_kot_number(): void
    {
        $this->insertTxn(['bill_token' => 4]);
        $company = $this->makeCompany('off', ['local_number_style' => 'token']);
        $order = $this->makeOrder();
        $order->pos_transaction_id = self::TXN_ID;

        $body = $this->body($this->render($company, $order, kotBatchNo: 3, delta: true));

        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 4', $body, 'same bill token on the delta slip');
        $this->assertStringContainsString('KOT #3', $body, 'KOT # rides the token line');
        $this->assertStringContainsString(__('pos.bill_ref_label') . ': L-000141', $body);
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body);
    }

    // ── 8. Lookup failure = old behavior (drift guard) ────────────────────

    public function test_missing_transaction_row_falls_back_to_ord_line(): void
    {
        // pos_transaction_id points at a purged/foreign row → guarded lookup
        // finds nothing → old ORD- behavior, never a blank header.
        $company = $this->makeCompany('off', ['local_number_style' => 'token']);
        $order = $this->makeOrder();
        $order->pos_transaction_id = 999999;

        $body = $this->body($this->render($company, $order));

        $this->assertStringContainsString(self::ORDER_NUMBER, $body, 'missing bill row → ORD- fallback');
    }
}
