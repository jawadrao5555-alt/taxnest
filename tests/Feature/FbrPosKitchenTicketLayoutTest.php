<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;

/**
 * FBR POS KITCHEN TICKET (KOT) ORDER-MATCH LAYOUT LOCK — Task 464 (11 Aug 2026).
 *
 * The FBR customer receipt has a rendered-view lock
 * (FbrPosReceiptOrderMatchLayoutTest) and the PRA KOT side is locked
 * (PosKitchenTicketLayoutTest), but the FBR KOT template
 * (fbr-pos/kitchen-ticket.blade.php — served by BOTH
 * FbrPosController::kotTicket for held sales and ::kotReprint for
 * completed transactions) only had route-returns-a-View coverage
 * (FbrPosOrderMatchingTest). A template edit could silently drop the
 * token/code box and break counter matching.
 *
 * Invariants under lock (single 80mm template, both controller paths pass
 * the same variable set — company/held/items/tokenNo/orderCode/...):
 *   • style 'token' + tokenNo → "TOKEN N" inside the .token-box span,
 *     exactly once; no code, no .code-box.
 *   • style 'token' + BOTH identifiers → token wins (elseif), the code
 *     never prints.
 *   • style 'token' but tokenNo NULL → no box at all.
 *   • style 'code' + orderCode → code inside the .code-box span, exactly
 *     once; no token label, no .token-box.
 *   • style 'code' but orderCode NULL → no box.
 *   • style 'off' → nothing extra even when BOTH identifiers are present.
 *   • held-sale path (held set) and reprint path (held NULL) render the
 *     identical om box — the template must not branch on $held for it.
 *
 * Pattern follows FbrPosReceiptOrderMatchLayoutTest: rendered-view on
 * sqlite :memory:, unsaved Company shim, direct view() render with exactly
 * the compact() variable set both controller actions pass. No tables are
 * needed — this blade reads no DB (identifiers arrive pre-resolved).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosKitchenTicketLayoutTest.php --testdox
 */
class FbrPosKitchenTicketLayoutTest extends TestCase
{
    private const TEMPLATE   = 'fbr-pos.kitchen-ticket';
    private const COMPANY_ID = 702;
    private const ORDER_CODE = 'QK7Z3';

    // Box style fingerprints — the om identifiers must ride these spans.
    private const TOKEN_BOX = 'class="token-box"';
    private const CODE_BOX  = 'class="code-box"';

    // ── Fixture builders (mirror kotTicket()/kotReprint() variable sets) ──

    private function makeCompany(string $style): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'FBR KOT Layout Co';
        $company->order_match_style = $style;
        return $company;
    }

    /**
     * Render the KOT with the exact variable set both controller paths
     * pass: compact('company','held','items','tokenNo','orderCode',
     * 'customerName','kitchenNotes','now','autoPrint').
     */
    private function renderBody(
        Company $company,
        ?int $tokenNo = null,
        ?string $orderCode = null,
        bool $heldPath = true
    ): string {
        $held = $heldPath ? new \stdClass() : null; // template only branches truthy/null
        $html = view(self::TEMPLATE, [
            'company'      => $company,
            'held'         => $held,
            'items'        => [
                ['item_name' => 'Zinger Burger', 'quantity' => 2.0, 'special_notes' => null],
            ],
            'tokenNo'      => $tokenNo,
            'orderCode'    => $orderCode,
            'customerName' => null,
            'kitchenNotes' => null,
            'now'          => now(),
            'autoPrint'    => false,
        ])->render();

        // Markup AFTER </head> — the CSS defines .token-box/.code-box class
        // rules; only class="..." usages in the body count as a printed box.
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered KOT has a </head>');
        return substr($html, $pos);
    }

    // ── 1. style = 'token' ────────────────────────────────────────────────

    public function test_token_style_prints_token_box_once(): void
    {
        $body = $this->renderBody($this->makeCompany('token'), tokenNo: 42);

        $label = __('pos.order_match_token_label', [], 'en') . ' 42';
        $this->assertStringContainsString($label, $body, 'TOKEN 42 prints');
        $this->assertSame(1, substr_count($body, $label), 'token prints exactly once');
        $this->assertSame(1, substr_count($body, self::TOKEN_BOX), 'token-box span exactly once');
        $this->assertStringNotContainsString(self::CODE_BOX, $body, 'no code box in token mode');
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::TOKEN_BOX, '/') . '[^>]*>\s*' . preg_quote($label, '/') . '/',
            $body,
            'TOKEN 42 rides inside the token-box span'
        );
    }

    public function test_token_style_wins_over_stray_order_code(): void
    {
        // elseif exclusivity — a held sale carrying BOTH identifiers in
        // token mode prints only the token.
        $body = $this->renderBody($this->makeCompany('token'), tokenNo: 7, orderCode: self::ORDER_CODE);

        $this->assertStringContainsString(__('pos.order_match_token_label', [], 'en') . ' 7', $body, 'token prints');
        $this->assertStringNotContainsString(self::ORDER_CODE, $body, 'code never prints in token mode');
        $this->assertStringNotContainsString(self::CODE_BOX, $body, 'no code-box span in token mode');
        $this->assertSame(1, substr_count($body, self::TOKEN_BOX), 'exactly one om box');
    }

    public function test_token_style_without_token_no_prints_no_box(): void
    {
        $body = $this->renderBody($this->makeCompany('token'));

        $this->assertStringNotContainsString(__('pos.order_match_token_label', [], 'en'), $body, 'no empty token label');
        $this->assertStringNotContainsString(self::TOKEN_BOX, $body, 'no token-box span');
        $this->assertStringNotContainsString(self::CODE_BOX, $body, 'no code-box span');
    }

    // ── 2. style = 'code' ────────────────────────────────────────────────

    public function test_code_style_prints_code_box_once(): void
    {
        $body = $this->renderBody($this->makeCompany('code'), orderCode: self::ORDER_CODE);

        $this->assertStringContainsString(self::ORDER_CODE, $body, 'order code prints');
        $this->assertSame(1, substr_count($body, self::ORDER_CODE), 'code prints exactly once');
        $this->assertSame(1, substr_count($body, self::CODE_BOX), 'code-box span exactly once');
        $this->assertStringNotContainsString(self::TOKEN_BOX, $body, 'no token box in code mode');
        $this->assertStringNotContainsString(__('pos.order_match_token_label', [], 'en'), $body, 'no TOKEN label in code mode');
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::CODE_BOX, '/') . '[^>]*>\s*' . preg_quote(self::ORDER_CODE, '/') . '/',
            $body,
            'code rides inside the code-box span'
        );
    }

    public function test_code_style_without_order_code_prints_no_box(): void
    {
        $body = $this->renderBody($this->makeCompany('code'));

        $this->assertStringNotContainsString(self::TOKEN_BOX, $body, 'no token-box span');
        $this->assertStringNotContainsString(self::CODE_BOX, $body, 'no code-box span');
    }

    // ── 3. style = 'off' ─────────────────────────────────────────────────

    public function test_off_style_prints_no_order_match_identifiers(): void
    {
        // Carrying BOTH identifiers — 'off' must still print nothing.
        $body = $this->renderBody($this->makeCompany('off'), tokenNo: 42, orderCode: self::ORDER_CODE);

        $this->assertStringNotContainsString(__('pos.order_match_token_label', [], 'en'), $body, 'no token label when off');
        $this->assertStringNotContainsString(self::ORDER_CODE, $body, 'no order code when off');
        $this->assertStringNotContainsString(self::TOKEN_BOX, $body, 'no token-box span when off');
        $this->assertStringNotContainsString(self::CODE_BOX, $body, 'no code-box span when off');
    }

    // ── 4. reprint path (held = null) renders the identical om box ───────

    public function test_reprint_path_renders_same_token_box_as_held_path(): void
    {
        $company = $this->makeCompany('token');

        $heldBody    = $this->renderBody($company, tokenNo: 42, heldPath: true);
        $reprintBody = $this->renderBody($company, tokenNo: 42, heldPath: false);

        $label = __('pos.order_match_token_label', [], 'en') . ' 42';
        $this->assertStringContainsString($label, $reprintBody, 'token prints on KOT reprint');
        $this->assertSame(1, substr_count($reprintBody, self::TOKEN_BOX), 'token-box on reprint exactly once');
        $this->assertSame($heldBody, $reprintBody, 'held & reprint KOT bodies identical');
    }

    public function test_reprint_path_renders_same_code_box_as_held_path(): void
    {
        $company = $this->makeCompany('code');

        $heldBody    = $this->renderBody($company, orderCode: self::ORDER_CODE, heldPath: true);
        $reprintBody = $this->renderBody($company, orderCode: self::ORDER_CODE, heldPath: false);

        $this->assertStringContainsString(self::ORDER_CODE, $reprintBody, 'code prints on KOT reprint');
        $this->assertSame(1, substr_count($reprintBody, self::CODE_BOX), 'code-box on reprint exactly once');
        $this->assertSame($heldBody, $reprintBody, 'held & reprint KOT bodies identical');
    }
}
