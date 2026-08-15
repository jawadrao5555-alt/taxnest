<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
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

        foreach (self::TEMPLATES as $tpl) {
            $txn = $this->makeTxn($company);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringContainsString(__('pos.receipt_scan_bill'), $html, $tpl);
            $tok = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token;
            $this->assertNotEmpty($tok, $tpl . ': share token minted lazily');
        }
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

        foreach (self::TEMPLATES as $tpl) {
            $txn = $this->makeTxn($company);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringContainsString(__('pos.receipt_scan_bill'), $html, $tpl);
            $this->assertStringNotContainsString(__('pos.receipt_scan_menu'), $html, $tpl);
        }
        $this->assertNotEmpty(PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token);
    }

    public function test_show_menu_qr_off_suppresses_qr_and_mints_nothing(): void
    {
        $this->insertTxnRow();
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false]];

        foreach (self::TEMPLATES as $tpl) {
            $txn = $this->makeTxn($company);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringNotContainsString(__('pos.receipt_scan_bill'), $html, $tpl);
        }
        $this->assertNull(PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token);
    }

    public function test_fiscal_receipt_untouched_no_bill_url(): void
    {
        $this->insertTxnRow(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-1']);
        $company = $this->makeCompany();
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => true, 'bold' => false]];

        foreach (self::TEMPLATES as $tpl) {
            $txn = $this->makeTxn($company, ['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-1']);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringNotContainsString(__('pos.receipt_scan_bill'), $html, $tpl);
        }
        $this->assertNull(PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)->share_token);
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
}
