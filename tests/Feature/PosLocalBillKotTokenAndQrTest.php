<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Support\QrImage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 777 (ZFC, 16 Aug 2026) — Local bills: KOT token + URL QR.
 *
 * Invariants under lock:
 *   • Transaction-shim KOT: when the bill's STREAM number style is 'token'
 *     and bill_token is set, the big bordered token box prints with the
 *     serial as a small Ref line. Serial style / no token → old plain
 *     bill-number line. Order-based KOTs (no shimBillToken) unchanged.
 *   • Receipt (80mm + 58mm) non-fiscal QR: encodes url('/bill/{share_token}')
 *     instead of the plain-text payload; show_menu_qr OFF suppresses the QR
 *     entirely; PRA fiscal branch untouched (never mints a share token).
 *   • Public bill page /bill/{token}: opens with serial + total, no login;
 *     hides business name when the stream pref show_business_name is off;
 *     404 on malformed/unknown tokens; archived bills still open.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosLocalBillKotTokenAndQrTest.php --testdox
 */
class PosLocalBillKotTokenAndQrTest extends TestCase
{
    private const TXN_ID = 7101;
    private const COMPANY_ID = 601;
    private const TEMPLATES = ['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm'];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Blades gate on hasColumn('restaurant_orders','token_no') + the om lookup.
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->unsignedInteger('token_no')->nullable();
        });

        // Minimal pos_transactions — hasColumn('pos_transactions', 'bill_token'/
        // 'share_token') gates + publicBillToken()'s mint UPDATE hit this table.
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->unsignedInteger('bill_token')->nullable();
            $t->string('share_token')->nullable();
            $t->timestamp('share_token_created_at')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('default_language')->nullable();
            $t->string('local_number_style')->nullable();
            $t->string('pra_number_style')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // Split-payment breakdown — Task 802/803.
        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        QrImage::resetFake(); // never leave the fake active between tests
        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Token QR Co';
        $company->order_match_style = 'off';
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }
        return $company;
    }

    private function makeTxn(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => 'L-000130',
            'invoice_mode' => 'local',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'payment_method' => 'cash',
            'subtotal' => 500,
            'tax_rate' => 16,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
        ], $attrs));
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Chicken Pizza',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
            'is_tax_exempt' => false,
            'is_third_schedule' => false,
        ]);
        $item->id = 1;

        $txn->setRelation('items', collect([$item]));
        $txn->setRelation('payments', collect());
        $txn->setRelation('company', $company);
        $txn->setRelation('terminal', null);
        $txn->setRelation('creator', null);
        $txn->setRelation('rider', null);
        return $txn;
    }

    /** DB row so publicBillToken()'s mint UPDATE has something to hit. */
    private function insertTxnRow(array $attrs = []): void
    {
        PosTransaction::withoutGlobalScope('hide_archived')->insert(array_merge([
            'id' => self::TXN_ID,
            'company_id' => self::COMPANY_ID,
            'invoice_number' => 'L-000130',
            'invoice_mode' => 'local',
            'total_amount' => 500,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** Renders the kitchen ticket exactly like renderTransactionKot's shim. */
    private function renderShimKot(Company $company, ?int $shimBillToken): string
    {
        $order = new RestaurantOrder([
            'order_number' => 'L-000130',
            'order_type' => 'delivery',
            'customer_name' => null,
        ]);
        $order->exists = false;
        $order->created_at = now();
        $order->kot_print_count = 1;
        $order->priority = false;
        $order->kitchen_notes = null;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);

        $item = new RestaurantOrderItem([
            'item_type' => 'manual',
            'item_name' => 'Chicken Pizza',
            'quantity' => 1,
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
            'delta' => false,
            'kotBatchNo' => null,
            'newItemIds' => collect(),
            'shimBillToken' => $shimBillToken,
        ])->render();
    }

    // ── KOT shim token ────────────────────────────────────────────────────

    public function test_shim_kot_prints_bill_token_box_with_serial_ref(): void
    {
        $html = $this->renderShimKot($this->makeCompany(), 7);
        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 7', $html);
        $this->assertStringContainsString(__('pos.bill_ref_label') . ': L-000130', $html);
        $this->assertStringNotContainsString('KOT #', $html);
    }

    public function test_shim_kot_without_token_keeps_plain_bill_number_line(): void
    {
        $html = $this->renderShimKot($this->makeCompany(), null);
        $this->assertStringContainsString('L-000130', $html);
        $this->assertStringNotContainsString(__('pos.order_match_token_label'), $html);
    }

    public function test_order_kot_render_without_shim_variable_is_unchanged(): void
    {
        // kitchenTicket() never passes shimBillToken — blade must default null.
        $order = new RestaurantOrder(['order_number' => 'ORD-260816-AB12C', 'order_type' => 'takeaway']);
        $order->exists = true;
        $order->created_at = now();
        $order->kot_print_count = 1;
        $order->priority = false;
        $order->kitchen_notes = null;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);
        $item = new RestaurantOrderItem(['item_type' => 'manual', 'item_name' => 'X', 'quantity' => 1, 'unit_price' => 10, 'special_notes' => null]);
        $item->id = 1;
        $items = collect([$item]);

        $html = view('pos.restaurant.kitchen-ticket', [
            'order' => $order,
            'company' => $this->makeCompany(),
            'ticketItems' => $items,
            'grouped' => collect(['ALL' => $items]),
            'stationLabel' => null,
            'delta' => false,
            'kotBatchNo' => 1,
            'newItemIds' => collect(),
        ])->render();

        $this->assertStringContainsString('ORD-260816-AB12C', $html);
        $this->assertStringNotContainsString(__('pos.order_match_token_label'), $html);
    }

    /** renderTransactionKot only sets the token when the STREAM style is token. */
    public function test_stream_style_predicate_matches_receipts(): void
    {
        $company = $this->makeCompany(['local_number_style' => 'token', 'pra_number_style' => 'serial']);

        $local = $this->makeTxn($company, ['bill_token' => 3]);
        $this->assertTrue($local->isLocalBill());
        $style = $local->isLocalBill() ? $company->local_number_style : $company->pra_number_style;
        $this->assertSame('token', $style);

        $fiscal = $this->makeTxn($company, ['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA123', 'bill_token' => 3]);
        $this->assertFalse($fiscal->isLocalBill());
        $style = $fiscal->isLocalBill() ? $company->local_number_style : $company->pra_number_style;
        $this->assertSame('serial', $style);
    }

    // ── Receipt QR URL ────────────────────────────────────────────────────

    public function test_nonfiscal_receipt_qr_encodes_bill_url_on_both_templates(): void
    {
        $this->insertTxnRow();
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => true, 'bold' => false]];

        QrImage::fake();
        foreach (self::TEMPLATES as $tpl) {
            $txn = $this->makeTxn($company);
            view($tpl, ['transaction' => $txn, 'company' => $company])->render();
        }
        $tok = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token;
        $this->assertNotEmpty($tok, 'share_token must be lazily minted on first render');

        $expectedUrl = url('/bill/' . $tok);
        foreach (QrImage::recorded() as $i => $payload) {
            $this->assertSame($expectedUrl, $payload,
                'QR payload #' . $i . ' must be the /bill/{token} URL, not text or menu URL');
        }
        $this->assertCount(count(self::TEMPLATES), QrImage::recorded(),
            'One QrImage::dataUri() call expected per template');
    }

    /**
     * Bill URL takes PRIORITY over the menu QR (review 16 Aug 2026): even a
     * company with an enabled public profile must get the bill-page QR — the
     * bill page itself carries the onward menu link. publicUrlFor is
     * short-circuited when a share token exists, so no profile tables needed.
     */
    public function test_bill_url_beats_menu_qr_when_public_profile_enabled(): void
    {
        $this->insertTxnRow();
        $company = $this->makeCompany(['public_profile_slug' => 'token-qr-co']);
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => true, 'bold' => false]];

        QrImage::fake();
        foreach (self::TEMPLATES as $tpl) {
            $txn  = $this->makeTxn($company);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringNotContainsString(__('pos.receipt_scan_menu'), $html, $tpl);
        }
        $tok = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token;
        $this->assertNotEmpty($tok);

        $expectedUrl = url('/bill/' . $tok);
        foreach (QrImage::recorded() as $i => $payload) {
            $this->assertSame($expectedUrl, $payload,
                'Bill URL must beat menu QR — QR payload #' . $i . ' must be /bill/{token}');
        }
    }

    public function test_show_menu_qr_off_suppresses_qr_and_mints_nothing(): void
    {
        $this->insertTxnRow();
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false]];

        QrImage::fake();
        foreach (self::TEMPLATES as $tpl) {
            $txn  = $this->makeTxn($company);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringNotContainsString(__('pos.receipt_scan_bill'), $html, $tpl);
        }
        $this->assertNull(PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token,
            'show_menu_qr=false must not mint a share_token');
        $this->assertEmpty(QrImage::recorded(), 'QrImage::dataUri must not be called when QR is suppressed');
    }

    public function test_fiscal_receipt_untouched_no_bill_url(): void
    {
        $this->insertTxnRow(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-1']);
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => true, 'bold' => false]];

        QrImage::fake();
        foreach (self::TEMPLATES as $tpl) {
            $txn  = $this->makeTxn($company, ['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-1']);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringNotContainsString(__('pos.receipt_scan_bill'), $html, $tpl);
        }
        $this->assertNull(PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token,
            'Fiscal receipt must never mint a share_token');
        $billUrlCalls = array_filter(QrImage::recorded(), fn ($p) => str_contains($p, '/bill/'));
        $this->assertEmpty($billUrlCalls, 'Fiscal receipt must never pass a /bill/ URL to the QR encoder');
    }

    /**
     * PDF download path (DomPDF / MpdfRenderer): PosController passes pdfMode=true
     * to the same blades.  pdfMode only affects paper-sizing CSS — it must NOT
     * suppress the QR.  Token must still be lazily minted so the downloaded PDF
     * also carries a scannable /bill/{token} URL.
     *
     * Agent silent-print path: AgentController renders the same receipt_80mm /
     * receipt_58mm blades WITHOUT pdfMode (plain view render, no extra vars), so
     * its QR payload is identical to the normal-reprint path and is covered by
     * test_nonfiscal_receipt_qr_encodes_bill_url_on_both_templates above.
     */
    public function test_pdf_mode_receipt_qr_still_encodes_bill_url(): void
    {
        $this->insertTxnRow(); // share_token = NULL (pre-Task-777 old bill)
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => true, 'bold' => false]];

        QrImage::fake();
        foreach (self::TEMPLATES as $tpl) {
            $txn = $this->makeTxn($company);
            view($tpl, [
                'transaction' => $txn,
                'company'     => $company,
                'pdfMode'     => true,
                'pdfPaper'    => 'thermal',
            ])->render();
        }
        $tok = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token;
        $this->assertNotEmpty($tok, 'share_token must be minted even when rendering for PDF');

        $expectedUrl = url('/bill/' . $tok);
        foreach (QrImage::recorded() as $i => $payload) {
            $this->assertSame($expectedUrl, $payload,
                "pdfMode=true: QR payload #$i must still encode the /bill/{token} URL");
        }
        $this->assertCount(count(self::TEMPLATES), QrImage::recorded(),
            'One QrImage::dataUri() call expected per template even in PDF mode');
    }

    /**
     * Reprint of a pre-Task-777 bill (share_token was NULL in DB).
     * First render — whether triggered by the cashier's reprint button, the
     * transactions-list receipt popup, or the Desktop Agent silent-print —
     * must lazily mint the token so every subsequent reprint scans to the
     * same /bill/{token} URL.
     */
    public function test_old_bill_null_share_token_lazily_minted_on_reprint(): void
    {
        // Simulate a bill created before Task 777: no share_token in DB.
        $this->insertTxnRow(['share_token' => null]);
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => true, 'bold' => false]];

        // First reprint render.
        QrImage::fake();
        $txn = $this->makeTxn($company);
        view('pos.receipts.receipt_80mm', ['transaction' => $txn, 'company' => $company])->render();

        $mintedToken = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token;
        $this->assertNotEmpty($mintedToken, 'First reprint must write share_token to DB');

        $expectedUrl = url('/bill/' . $mintedToken);
        $this->assertSame([$expectedUrl], QrImage::recorded(),
            'First reprint: QR encoder must receive the exact /bill/{token} URL that was just minted');

        // Second reprint: same token reused, not re-minted, same URL encoded.
        QrImage::fake(); // reset recorder for the second render
        $txn2 = $this->makeTxn($company);
        view('pos.receipts.receipt_80mm', ['transaction' => $txn2, 'company' => $company])->render();

        $tokenAfterSecond = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token;
        $this->assertSame($mintedToken, $tokenAfterSecond, 'Token must be identical across reprints (idempotent)');
        $this->assertSame([$expectedUrl], QrImage::recorded(),
            'Subsequent reprints must encode the SAME /bill/{token} URL');
    }

    // ── Public bill page ──────────────────────────────────────────────────

    private function seedPublicBill(array $companyAttrs = [], array $txnAttrs = []): string
    {
        Company::query()->insert(array_merge([
            'id' => self::COMPANY_ID,
            'name' => 'Token QR Co',
            'default_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ], $companyAttrs));

        $token = bin2hex(random_bytes(32));
        $this->insertTxnRow(array_merge(['share_token' => $token], $txnAttrs));
        \DB::table('pos_transaction_items')->insert([
            'transaction_id' => self::TXN_ID,
            'item_name' => 'Chicken Pizza',
            'quantity' => 1,
            'subtotal' => 500,
            'tax_amount' => 0,
        ]);
        return $token;
    }

    public function test_public_bill_page_shows_basics_without_login(): void
    {
        $token = $this->seedPublicBill();
        $res = $this->get('/bill/' . $token);
        $res->assertOk();
        $res->assertSee('L-000130');
        $res->assertSee('Chicken Pizza');
        $res->assertSee('500');
        $res->assertSee('Token QR Co');
        $res->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_public_bill_page_hides_business_name_when_pref_off(): void
    {
        $token = $this->seedPublicBill([
            'invoice_display_prefs' => json_encode(['pos_local' => ['show_business_name' => false]]),
        ]);
        $res = $this->get('/bill/' . $token);
        $res->assertOk();
        $res->assertSee('L-000130');
        $res->assertDontSee('Token QR Co');
    }

    public function test_public_bill_page_opens_archived_bills(): void
    {
        $token = $this->seedPublicBill([], ['is_archived' => true]);
        $this->get('/bill/' . $token)->assertOk();
    }

    public function test_public_bill_page_rejects_bad_tokens(): void
    {
        // The platform's NotFoundHttpException renderable turns guest HTML 404s
        // into a redirect to '/' (same as /menu/{slug}) — assert the abort(404)
        // fired (redirect, never a 200 with bill data). JSON requests get a
        // real 404 status.
        $this->seedPublicBill();
        $this->get('/bill/' . str_repeat('a', 64))->assertRedirect('/'); // unknown token
        $this->get('/bill/' . self::TXN_ID)->assertRedirect('/');        // id guess
        $this->get('/bill/short')->assertRedirect('/');                  // malformed
        $this->getJson('/bill/' . str_repeat('b', 64))->assertNotFound();
    }

    // ── Split-payment breakdown (Task 802/803) ────────────────────────────

    /**
     * Seed payment rows alongside the bill so the split-payment section renders.
     */
    private function seedPayments(array $rows): void
    {
        foreach ($rows as $row) {
            \DB::table('pos_payments')->insert(array_merge([
                'transaction_id' => self::TXN_ID,
                'created_at'     => now(),
                'updated_at'     => now(),
            ], $row));
        }
    }

    /**
     * A bill paid partly in cash and partly by card must show both payment
     * lines and the correct grand total. The breakdown heading must also appear.
     */
    public function test_split_payment_bill_shows_breakdown_with_correct_amounts(): void
    {
        $token = $this->seedPublicBill([], ['total_amount' => 1000]);
        $this->seedPayments([
            ['payment_method' => 'cash',       'amount' => 600],
            ['payment_method' => 'debit_card',  'amount' => 400],
        ]);

        $res = $this->get('/bill/' . $token);
        $res->assertOk();

        // Grand total must be present.
        $res->assertSee('1,000');

        // Payment breakdown heading.
        $res->assertSee(__('pos.payment_breakdown'));

        // Both payment method labels.
        $res->assertSee(__('pos.receipt_pay_cash'));
        $res->assertSee(__('pos.receipt_pay_card'));

        // Each payment amount.
        $res->assertSee('600');
        $res->assertSee('400');
    }

    /**
     * A bill paid with three split rows (cash + card + other) must show all
     * three buckets. The 'card' alias (legacy) must merge with 'debit_card'
     * into a single Card row.
     */
    public function test_split_payment_card_aliases_merge_into_one_row(): void
    {
        $token = $this->seedPublicBill([], ['total_amount' => 1500]);
        $this->seedPayments([
            ['payment_method' => 'cash',    'amount' => 500],
            ['payment_method' => 'card',    'amount' => 300],  // legacy alias
            ['payment_method' => 'debit_card', 'amount' => 200], // same bucket
        ]);

        $res = $this->get('/bill/' . $token);
        $res->assertOk();

        $html = $res->content();

        // Both card aliases collapse into one label — ensure it appears exactly once.
        $cardLabel = __('pos.receipt_pay_card');
        $this->assertSame(1, substr_count($html, $cardLabel),
            'card + debit_card aliases must collapse into a single Card row');

        // Combined card amount (300 + 200 = 500).
        $res->assertSee('500');
    }

    /**
     * Single-payment bills must NOT show the payment breakdown section — the
     * section is only useful when there are ≥2 rows.
     */
    public function test_single_payment_bill_hides_breakdown_section(): void
    {
        $token = $this->seedPublicBill([], ['total_amount' => 800]);
        $this->seedPayments([
            ['payment_method' => 'cash', 'amount' => 800],
        ]);

        $res = $this->get('/bill/' . $token);
        $res->assertOk();
        $res->assertDontSee(__('pos.payment_breakdown'));
    }

    /**
     * The public bill page must NEVER expose PRA invoice numbers or any other
     * internal fiscal identifier — not in the page title, body, or anywhere.
     */
    public function test_split_payment_bill_does_not_expose_pra_invoice_number(): void
    {
        $token = $this->seedPublicBill([], [
            'total_amount'       => 700,
            'invoice_mode'       => 'local',
            'pra_invoice_number' => 'PRA-SECRET-9999',
        ]);
        $this->seedPayments([
            ['payment_method' => 'cash',      'amount' => 400],
            ['payment_method' => 'debit_card', 'amount' => 300],
        ]);

        $res = $this->get('/bill/' . $token);
        $res->assertOk();
        $res->assertDontSee('PRA-SECRET-9999');
        $res->assertDontSee('pra_invoice_number');
    }

    /**
     * Two card-alias rows (legacy 'card' + 'debit_card') collapse into ONE
     * displayed Card row, but the breakdown section must still appear because
     * there were ≥2 raw payment rows. The combined amount must be correct.
     */
    public function test_all_card_alias_rows_collapse_but_section_still_shows(): void
    {
        $token = $this->seedPublicBill([], ['total_amount' => 900]);
        $this->seedPayments([
            ['payment_method' => 'card',       'amount' => 400],  // legacy alias
            ['payment_method' => 'debit_card',  'amount' => 500],  // same bucket
        ]);

        $res = $this->get('/bill/' . $token);
        $res->assertOk();

        // Section must appear even though both rows share the Card bucket.
        $res->assertSee(__('pos.payment_breakdown'));

        // One unified Card label.
        $cardLabel = __('pos.receipt_pay_card');
        $this->assertSame(1, substr_count($res->content(), $cardLabel),
            'Both card-alias rows must collapse into exactly one Card label');

        // Combined amount (400 + 500 = 900).
        $res->assertSee('900');

        // Cash label must NOT appear.
        $res->assertDontSee(__('pos.receipt_pay_cash'));
    }

    /**
     * Bills with no payment rows at all (older bills pre-split-payment feature)
     * still render correctly — grand total visible, no breakdown section.
     */
    public function test_bill_without_payment_rows_renders_without_breakdown(): void
    {
        $token = $this->seedPublicBill([], ['total_amount' => 500]);
        // No pos_payments rows inserted.

        $res = $this->get('/bill/' . $token);
        $res->assertOk();
        $res->assertSee('500');
        $res->assertDontSee(__('pos.payment_breakdown'));
    }
}
