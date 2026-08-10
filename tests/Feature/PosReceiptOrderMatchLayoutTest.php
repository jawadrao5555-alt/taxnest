<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\RestaurantOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUSTOMER RECEIPT ORDER-MATCH LAYOUT LOCK — Task 396 (10 Aug 2026).
 *
 * Task 395 locked the KOT side of Order Matching (PosKitchenTicketLayoutTest);
 * this file locks the CUSTOMER receipt side: the matching token / short code
 * printed on the 80mm AND 58mm receipt templates so counter staff can pair a
 * ready order with the bill.
 *
 * Invariants under lock (both templates):
 *   • style 'token' + linked restaurant order with token_no → the bordered
 *     token box prints ("TOKEN N"), exactly once.
 *   • style 'code' (non-fiscal bill) → the bordered short-code box prints with
 *     OrderTokenService::shortCode(order_number), exactly once; no token label.
 *   • style 'code' + PRA FISCAL bill → NO short-code box; the FULL order
 *     number prints in the top invoice box instead ("Order #:" row).
 *   • style 'off' → no token box, no code box, no order-match identifiers.
 *   • restaurant-less retail bill (no order_type) → nothing prints even when
 *     the style is on.
 *
 * Pattern follows PosKitchenTicketLayoutTest (rendered-view, sqlite :memory:)
 * — but the receipt templates look the linked RestaurantOrder up from the DB
 * (Schema-guarded per the PROD drift convention), so a minimal
 * restaurant_orders table is created and a real row inserted; company /
 * transaction / items stay unsaved shims wired via setRelation, exactly the
 * variable set PosController::receipt() passes.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosReceiptOrderMatchLayoutTest.php --testdox
 */
class PosReceiptOrderMatchLayoutTest extends TestCase
{
    private const ORDER_NUMBER = 'ORD-260810-XY9Q7';
    private const SHORT_CODE   = 'XY9Q7'; // OrderTokenService::shortCode(ORDER_NUMBER)
    private const TXN_ID       = 9001;
    private const COMPANY_ID   = 501;

    /** Both live receipt templates — every assertion runs against each. */
    private const TEMPLATES = ['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm'];

    // Bordered-box style fingerprints (the om box markup in each template).
    private const BOX_80 = 'border:2px solid #000; padding:2px 14px;';
    private const BOX_58 = 'border:2px solid #000; padding:2px 10px;';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal restaurant_orders — the ONLY table the receipt om lookup hits.
        // token_no must exist: the blades gate on hasColumn('restaurant_orders','token_no').
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->unsignedInteger('token_no')->nullable();
        });
    }

    // ── Fixture builders (mirrors PosController::receipt() variable set) ──

    private function makeCompany(string $style): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Receipt Layout Co';
        $company->order_match_style = $style;
        // Suppress the footer QR block — it is orthogonal to order matching and
        // its public-profile lookup needs unrelated tables.
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false]];
        return $company;
    }

    private function makeTransaction(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => 'INV-000777',
            'order_type' => 'takeaway',
            'payment_method' => 'cash',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'subtotal' => 500,
            'tax_rate' => 16,
            'tax_amount' => 80,
            'discount_amount' => 0,
            'total_amount' => 580,
        ], $attrs));
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Zinger Burger',
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

    /** Insert the linked restaurant order the om lookup finds. */
    private function linkOrder(?int $tokenNo = null): void
    {
        RestaurantOrder::query()->insert([
            'company_id' => self::COMPANY_ID,
            'pos_transaction_id' => self::TXN_ID,
            'order_number' => self::ORDER_NUMBER,
            'token_no' => $tokenNo,
        ]);
    }

    private function render(string $template, Company $company, PosTransaction $transaction): string
    {
        return view($template, ['transaction' => $transaction, 'company' => $company])->render();
    }

    /** Markup AFTER </head> — CSS/title never carry om identifiers anyway. */
    private function body(string $html): string
    {
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered receipt has a </head>');
        return substr($html, $pos);
    }

    private function boxFingerprint(string $template): string
    {
        return str_contains($template, '58mm') ? self::BOX_58 : self::BOX_80;
    }

    // ── 1. style = 'token' — bordered TOKEN box on both papers ───────────

    public function test_token_style_prints_token_box_on_both_templates(): void
    {
        $this->linkOrder(tokenNo: 42);

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('token');
            $txn = $this->makeTransaction($company);

            $body = $this->body($this->render($template, $company, $txn));

            $label = __('pos.order_match_token_label') . ' 42';
            $this->assertStringContainsString($label, $body, "TOKEN 42 box prints ({$template})");
            $this->assertSame(1, substr_count($body, $label), "token prints exactly once ({$template})");
            // The token must ride INSIDE the bordered box (same span), not as plain text
            $box = $this->boxFingerprint($template);
            $this->assertStringContainsString($box, $body, "bordered om box present ({$template})");
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($box, '/') . '[^>]*>\s*' . preg_quote($label, '/') . '/',
                $body,
                "TOKEN 42 rides inside the bordered box ({$template})"
            );
            // Token mode never prints the short code
            $this->assertStringNotContainsString(self::SHORT_CODE, $body, "no short code in token mode ({$template})");
        }
    }

    public function test_token_style_without_token_no_prints_no_box(): void
    {
        // Linked order exists but carries no token (e.g. allocated pre-feature).
        $this->linkOrder(tokenNo: null);

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('token');
            $txn = $this->makeTransaction($company);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no empty token box ({$template})");
            $this->assertStringNotContainsString($this->boxFingerprint($template), $body, "no bordered om box ({$template})");
        }
    }

    // ── 2. style = 'code' — bordered short-code box (non-fiscal) ─────────

    public function test_code_style_prints_short_code_box_on_both_templates(): void
    {
        $this->linkOrder();

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('code');
            $txn = $this->makeTransaction($company);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringContainsString(self::SHORT_CODE, $body, "short code prints ({$template})");
            $this->assertStringContainsString($this->boxFingerprint($template), $body, "code rides the bordered box ({$template})");
            // Code mode never prints the token label
            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no TOKEN label in code mode ({$template})");
        }
    }

    public function test_code_style_fiscal_bill_drops_box_and_prints_full_order_number_on_top(): void
    {
        // PRA fiscal bills show the FULL order number in the top invoice box —
        // the short-code box must NOT double-print below it.
        $this->linkOrder();

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('code');
            $txn = $this->makeTransaction($company, [
                'pra_status' => 'submitted',
                'pra_invoice_number' => '1234567890123456789012345',
            ]);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringContainsString(self::ORDER_NUMBER, $body, "full order number prints in top box ({$template})");
            $this->assertStringContainsString('Order #:', $body, "Order # row prints ({$template})");
            $this->assertStringNotContainsString($this->boxFingerprint($template), $body, "no short-code box on fiscal bills ({$template})");
        }
    }

    public function test_fiscal_bill_off_style_never_prints_order_number_row(): void
    {
        // Fiscal bills render the classic invoice table — the "Order #" row must
        // stay code-style-only. Style 'off' + linked order = no row, no code.
        $this->linkOrder(tokenNo: 42);

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('off');
            $txn = $this->makeTransaction($company, [
                'pra_status' => 'submitted',
                'pra_invoice_number' => '1234567890123456789012345',
            ]);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringNotContainsString('Order #:', $body, "no Order # row when off ({$template})");
            $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, "no order number when off ({$template})");
            $this->assertStringNotContainsString(self::SHORT_CODE, $body, "no short code when off ({$template})");
            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no token box when off ({$template})");
        }
    }

    public function test_fiscal_retail_bill_code_style_prints_no_order_number_row(): void
    {
        // Retail fiscal bill (no order_type) — even with code style ON and a
        // stray linked order row, the Order # row must not print.
        $this->linkOrder();

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('code');
            $txn = $this->makeTransaction($company, [
                'order_type' => null,
                'pra_status' => 'submitted',
                'pra_invoice_number' => '1234567890123456789012345',
            ]);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringNotContainsString('Order #:', $body, "no Order # row on retail fiscal bill ({$template})");
            $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, "no order number on retail fiscal bill ({$template})");
        }
    }

    public function test_fiscal_bill_token_style_prints_token_box_but_no_order_number_row(): void
    {
        // Token-style fiscal bill: the token box prints (matching identifier),
        // but the full order-number row is code-style-only.
        $this->linkOrder(tokenNo: 42);

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('token');
            $txn = $this->makeTransaction($company, [
                'pra_status' => 'submitted',
                'pra_invoice_number' => '1234567890123456789012345',
            ]);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringContainsString(__('pos.order_match_token_label') . ' 42', $body, "token box prints on fiscal bill ({$template})");
            $this->assertStringNotContainsString('Order #:', $body, "no Order # row in token mode ({$template})");
            $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, "no full order number in token mode ({$template})");
        }
    }

    // ── 3. style = 'off' — nothing extra prints ──────────────────────────

    public function test_off_style_prints_no_order_match_identifiers(): void
    {
        // Order exists WITH a token — 'off' must still print nothing.
        $this->linkOrder(tokenNo: 42);

        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany('off');
            $txn = $this->makeTransaction($company);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no token box when off ({$template})");
            $this->assertStringNotContainsString(self::SHORT_CODE, $body, "no short code when off ({$template})");
            $this->assertStringNotContainsString($this->boxFingerprint($template), $body, "no bordered om box when off ({$template})");
        }
    }

    // ── 4. retail bill (no order_type) — style on, nothing prints ────────

    public function test_retail_bill_without_order_type_prints_nothing_even_when_style_on(): void
    {
        $this->linkOrder(tokenNo: 42);

        foreach (['token', 'code'] as $style) {
            foreach (self::TEMPLATES as $template) {
                $company = $this->makeCompany($style);
                $txn = $this->makeTransaction($company, ['order_type' => null]);

                $body = $this->body($this->render($template, $company, $txn));

                $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no token box on retail bill ({$style}, {$template})");
                $this->assertStringNotContainsString(self::SHORT_CODE, $body, "no short code on retail bill ({$style}, {$template})");
            }
        }
    }

    // ── 5. no linked order — style on, nothing prints ────────────────────

    public function test_no_linked_order_prints_nothing(): void
    {
        // No restaurant_orders row for this bill (plain counter sale that
        // happens to carry an order_type).
        foreach (['token', 'code'] as $style) {
            foreach (self::TEMPLATES as $template) {
                $company = $this->makeCompany($style);
                $txn = $this->makeTransaction($company);

                $body = $this->body($this->render($template, $company, $txn));

                $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no token box without linked order ({$style}, {$template})");
                $this->assertStringNotContainsString(self::SHORT_CODE, $body, "no short code without linked order ({$style}, {$template})");
                $this->assertStringNotContainsString($this->boxFingerprint($template), $body, "no bordered om box without linked order ({$style}, {$template})");
            }
        }
    }
}
