<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS CUSTOMER RECEIPT ORDER-MATCH LAYOUT LOCK — Task 450 (10 Aug 2026).
 *
 * PRA receipts (80mm/58mm) have rendered-view layout locks for the Order
 * Matching token/code box (PosReceiptOrderMatchLayoutTest); the FBR POS
 * receipt (fbr-pos/receipt.blade.php) prints the same identifiers straight
 * off fbr_pos_transactions columns, but only allocation/persistence was
 * tested (FbrPosOrderMatchingTest). This file locks the RENDERED output so
 * a template edit can't silently drop or duplicate the token/code box.
 *
 * Invariants under lock (single template, $is58 switches paper width):
 *   • style 'token' + txn.token_no → bordered "TOKEN N" box, exactly once,
 *     on BOTH 80mm (thermal) and 58mm (thermal58) papers; no code prints.
 *   • style 'token' + txn carrying an order_code too → token wins (elseif),
 *     code never prints.
 *   • style 'token' but token_no NULL → no box at all.
 *   • style 'code' + txn.order_code → bordered code box, exactly once, on
 *     both papers, UPPERCASED even when stored lowercase; no token label.
 *   • style 'code' but order_code NULL → no box.
 *   • style 'off' → nothing extra prints even when the txn carries BOTH a
 *     token and a code.
 *
 * Pattern follows PosReceiptOrderMatchLayoutTest (rendered-view, sqlite
 * :memory:): company/transaction/items are unsaved shims wired via
 * setRelation — exactly the variable set FbrPosController::receipt()
 * passes (compact('transaction','company')). The blade gates on
 * Schema::hasColumn('fbr_pos_transactions', ...) so a minimal table with
 * the two Order Matching columns is created (no rows needed — the values
 * are read from the in-memory transaction shim).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosReceiptOrderMatchLayoutTest.php --testdox
 */
class FbrPosReceiptOrderMatchLayoutTest extends TestCase
{
    private const TEMPLATE   = 'fbr-pos.receipt';
    private const COMPANY_ID = 701;
    private const TXN_ID     = 9101;
    private const ORDER_CODE = 'XY9Q7';

    /** Both paper widths — every assertion runs against each. */
    private const PAPERS = ['thermal', 'thermal58'];

    // Bordered-box style fingerprints (om box markup; padding switches on $is58).
    private const BOX_80 = 'border:2px solid #000; padding:2px 14px;';
    private const BOX_58 = 'border:2px solid #000; padding:2px 10px;';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal fbr_pos_transactions — the blade om block gates on
        // hasColumn('fbr_pos_transactions','token_no'/'order_code').
        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->unsignedSmallInteger('token_no')->nullable();
            $t->string('order_code', 10)->nullable();
        });
    }

    // ── Fixture builders (mirrors FbrPosController::receipt() variable set) ──

    private function makeCompany(string $style, string $paper): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'FBR Receipt Layout Co';
        $company->order_match_style = $style;
        $company->print_paper_size = $paper;
        $company->invoice_display_prefs = ['pos_style' => ['bold' => false]];
        return $company;
    }

    private function makeTransaction(Company $company, ?int $tokenNo = null, ?string $orderCode = null): FbrPosTransaction
    {
        $txn = new FbrPosTransaction([
            'invoice_number' => 'FBR-000777',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'fbr_invoice_number' => null,
            'payment_method' => 'cash',
            'subtotal' => 500,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
        ]);
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->token_no = $tokenNo;
        $txn->order_code = $orderCode;
        $txn->created_at = now();

        $item = new FbrPosTransactionItem([
            'item_name' => 'Zinger Burger',
            'uom' => 'U',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
        ]);
        $item->id = 1;

        $txn->setRelation('items', collect([$item]));
        $txn->setRelation('company', $company);
        $txn->setRelation('creator', null);
        return $txn;
    }

    private function renderBody(Company $company, FbrPosTransaction $txn): string
    {
        $html = view(self::TEMPLATE, ['transaction' => $txn, 'company' => $company])->render();
        // Markup AFTER </head> — CSS/title never carry om identifiers anyway.
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered receipt has a </head>');
        return substr($html, $pos);
    }

    private function boxFingerprint(string $paper): string
    {
        return $paper === 'thermal58' ? self::BOX_58 : self::BOX_80;
    }

    // ── 1. style = 'token' — bordered TOKEN box on both papers ───────────

    public function test_token_style_prints_token_box_once_on_both_papers(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('token', $paper);
            $txn = $this->makeTransaction($company, tokenNo: 42);

            $body = $this->renderBody($company, $txn);

            $label = __('pos.order_match_token_label') . ' 42';
            $this->assertStringContainsString($label, $body, "TOKEN 42 prints ({$paper})");
            $this->assertSame(1, substr_count($body, $label), "token prints exactly once ({$paper})");
            // The token must ride INSIDE the bordered box (same span), not as plain text
            $box = $this->boxFingerprint($paper);
            $this->assertStringContainsString($box, $body, "bordered om box present ({$paper})");
            $this->assertSame(1, substr_count($body, $box), "om box prints exactly once ({$paper})");
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($box, '/') . '[^>]*>\s*' . preg_quote($label, '/') . '/',
                $body,
                "TOKEN 42 rides inside the bordered box ({$paper})"
            );
            // Task 649: the queue-number caption prints under the token box, exactly once.
            $caption = __('pos.order_match_token_caption');
            $this->assertStringContainsString($caption, $body, "queue-number caption prints ({$paper})");
            $this->assertSame(1, substr_count($body, $caption), "caption prints exactly once ({$paper})");
        }
    }

    // ── Task 649: token caption locale rendering ──────────────────────────

    public function test_token_caption_renders_in_every_pos_locale(): void
    {
        $original = app()->getLocale();
        try {
            foreach (['en', 'rur', 'ur'] as $locale) {
                app()->setLocale($locale);
                $expected = trans('pos.order_match_token_caption', [], $locale);
                $this->assertNotSame('pos.order_match_token_caption', $expected, "caption key exists in {$locale}");

                foreach (self::PAPERS as $paper) {
                    $company = $this->makeCompany('token', $paper);
                    $txn = $this->makeTransaction($company, tokenNo: 42);

                    $body = $this->renderBody($company, $txn);
                    $this->assertStringContainsString($expected, $body, "caption renders in {$locale} ({$paper})");
                }
            }
        } finally {
            app()->setLocale($original);
        }
    }

    public function test_token_style_wins_over_stray_order_code(): void
    {
        // elseif exclusivity: a txn carrying BOTH identifiers in token mode
        // prints only the token — the code must never double-print.
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('token', $paper);
            $txn = $this->makeTransaction($company, tokenNo: 7, orderCode: self::ORDER_CODE);

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString(__('pos.order_match_token_label') . ' 7', $body, "token prints ({$paper})");
            $this->assertStringNotContainsString(self::ORDER_CODE, $body, "no order code in token mode ({$paper})");
            $this->assertSame(1, substr_count($body, $this->boxFingerprint($paper)), "exactly one om box ({$paper})");
        }
    }

    public function test_token_style_without_token_no_prints_no_box(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('token', $paper);
            $txn = $this->makeTransaction($company);

            $body = $this->renderBody($company, $txn);

            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no empty token box ({$paper})");
            $this->assertStringNotContainsString($this->boxFingerprint($paper), $body, "no bordered om box ({$paper})");
            $this->assertStringNotContainsString(__('pos.order_match_token_caption'), $body, "no token caption without a token ({$paper})");
        }
    }

    // ── 2. style = 'code' — bordered short-code box on both papers ───────

    public function test_code_style_prints_code_box_once_on_both_papers(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('code', $paper);
            $txn = $this->makeTransaction($company, orderCode: self::ORDER_CODE);

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString(self::ORDER_CODE, $body, "order code prints ({$paper})");
            $this->assertSame(1, substr_count($body, self::ORDER_CODE), "code prints exactly once ({$paper})");
            $box = $this->boxFingerprint($paper);
            $this->assertStringContainsString($box, $body, "code rides the bordered box ({$paper})");
            $this->assertSame(1, substr_count($body, $box), "om box prints exactly once ({$paper})");
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($box, '/') . '[^>]*>\s*' . preg_quote(self::ORDER_CODE, '/') . '/',
                $body,
                "code rides inside the bordered box ({$paper})"
            );
            // Code mode never prints the token label — nor the token caption (Task 649)
            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no TOKEN label in code mode ({$paper})");
            $this->assertStringNotContainsString(__('pos.order_match_token_caption'), $body, "no token caption in code mode ({$paper})");
        }
    }

    public function test_code_style_uppercases_stored_lowercase_code(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('code', $paper);
            $txn = $this->makeTransaction($company, orderCode: strtolower(self::ORDER_CODE));

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString(self::ORDER_CODE, $body, "code prints UPPERCASED ({$paper})");
            $this->assertStringNotContainsString(strtolower(self::ORDER_CODE), $body, "lowercase code never prints ({$paper})");
        }
    }

    public function test_code_style_without_order_code_prints_no_box(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('code', $paper);
            $txn = $this->makeTransaction($company);

            $body = $this->renderBody($company, $txn);

            $this->assertStringNotContainsString($this->boxFingerprint($paper), $body, "no bordered om box ({$paper})");
        }
    }

    // ── 3. style = 'off' — nothing extra prints ──────────────────────────

    public function test_off_style_prints_no_order_match_identifiers(): void
    {
        // Txn carries BOTH identifiers — 'off' must still print nothing.
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany('off', $paper);
            $txn = $this->makeTransaction($company, tokenNo: 42, orderCode: self::ORDER_CODE);

            $body = $this->renderBody($company, $txn);

            $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, "no token box when off ({$paper})");
            $this->assertStringNotContainsString(self::ORDER_CODE, $body, "no order code when off ({$paper})");
            $this->assertStringNotContainsString($this->boxFingerprint($paper), $body, "no bordered om box when off ({$paper})");
            $this->assertStringNotContainsString(__('pos.order_match_token_caption'), $body, "no token caption when off ({$paper})");
        }
    }
}
