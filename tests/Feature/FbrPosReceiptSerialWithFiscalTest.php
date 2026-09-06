<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS RECEIPT — SERIAL PRINTS ALONGSIDE FISCAL # — Task 766 (16 Aug 2026).
 *
 * Task 763 fixed the PRA receipts (80mm/58mm): a submitted fiscal bill used
 * to print ONLY the long PRA Fiscal # and hide the shop's own serial. Task
 * 766 asked for the same guarantee on the FBR POS receipt. Investigation
 * showed the FBR template (fbr-pos/receipt.blade.php) does NOT have the
 * single-number-row pattern anymore: the 6 Aug 2026 owner redesign removed
 * the boxed invoice-numbers table and moved the POS serial into the details
 * table UNCONDITIONALLY for every non-badge bill, while the FBR fiscal
 * number rides the bottom QR box. So a submitted FBR bill already prints
 * BOTH numbers — but nothing locked that behaviour. A future template edit
 * could silently reintroduce the "fiscal only" pattern the owner complained
 * about (ZFC Pizza Point video, 15 Aug 2026).
 *
 * Invariants under lock (single template, $is58 switches paper width):
 *   • SUBMITTED (fbr_status='submitted' + fbr_invoice_number): the details
 *     table prints the "POS Invoice #: <serial>" row AND the QR badge
 *     prints "FBR: <fiscal>" — on BOTH papers. No bill hides the serial.
 *   • SUBMITTED + Order-Match token style: the big TOKEN box prints AND the
 *     serial row AND the FBR line all coexist (token never replaces the
 *     serial the way the PRA number-style token does — FBR has no
 *     bill-number token style).
 *   • PENDING (fiscal not yet returned): serial prints in the details row
 *     AND as the "POS:" line inside the dashed FBR-PENDING badge.
 *   • LOCAL / PROVISIONAL (fbr_status 'local'/NULL): unchanged — big serial
 *     in the top badge, NO "FBR:" line, no duplicate details serial row.
 *
 * Pattern follows FbrPosReceiptOrderMatchLayoutTest (rendered-view, sqlite
 * :memory:): company/transaction/items are unsaved shims wired via
 * setRelation — exactly the variable set FbrPosController::receipt()
 * passes (compact('transaction','company')). The blade gates the om box on
 * Schema::hasColumn('fbr_pos_transactions', ...) so a minimal table with
 * those two columns is created.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosReceiptSerialWithFiscalTest.php --testdox
 */
class FbrPosReceiptSerialWithFiscalTest extends TestCase
{
    private const TEMPLATE   = 'fbr-pos.receipt';
    private const COMPANY_ID = 703;
    private const TXN_ID     = 9201;
    private const SERIAL     = 'FPOS-2026-00777';
    private const FISCAL     = '7000000009999127';

    protected function tearDown(): void
    {
        \App\Support\QrImage::resetFake();
        parent::tearDown();
    }

    /** Both paper widths — every assertion runs against each. */
    private const PAPERS = ['thermal', 'thermal58'];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        // QR is rendered LOCALLY now (optional FBR integration, Sep 2026 — no
        // external image host on receipts); record payloads instead of PNGs.
        \App\Support\QrImage::fake();

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

    // ── Fixture builders (mirror FbrPosController::receipt() variable set) ──

    private function makeCompany(string $paper, string $omStyle = 'off'): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'FBR Serial Lock Co';
        $company->order_match_style = $omStyle;
        $company->print_paper_size = $paper;
        $company->invoice_display_prefs = ['pos_style' => ['bold' => false]];
        return $company;
    }

    private function makeTransaction(
        Company $company,
        ?string $fbrStatus,
        ?string $fiscalNumber,
        string $invoiceMode = 'fbr',
        ?int $tokenNo = null
    ): FbrPosTransaction {
        $txn = new FbrPosTransaction([
            'invoice_number' => self::SERIAL,
            'invoice_mode' => $invoiceMode,
            'fbr_status' => $fbrStatus,
            'fbr_invoice_number' => $fiscalNumber,
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
        // Markup AFTER </head> — the <title> carries the serial and would
        // make "serial prints" assertions pass vacuously.
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered receipt has a </head>');
        return substr($html, $pos);
    }

    /** The exact details-table serial row markup (label glued to value cell). */
    private function serialRow(): string
    {
        return __('pos.receipt_pos_invoice') . ':</td><td class="info-value">' . self::SERIAL;
    }

    // ── 1. SUBMITTED — serial row + FBR fiscal line BOTH print ───────────

    public function test_submitted_bill_prints_serial_row_and_fbr_fiscal_line(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            $txn = $this->makeTransaction($company, 'submitted', self::FISCAL);

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString($this->serialRow(), $body, "POS Invoice # serial row prints ({$paper})");
            $this->assertStringContainsString('FBR: ' . self::FISCAL, $body, "FBR fiscal line prints ({$paper})");
            // Exactly once each — no duplicate large numbers (Task 763 convention).
            $this->assertSame(1, substr_count($body, self::SERIAL), "serial prints exactly once ({$paper})");
            $this->assertSame(1, substr_count($body, 'FBR: ' . self::FISCAL), "fiscal line prints exactly once ({$paper})");
            // Fiscalized QR carries ONLY the bare fiscal number (X-WAY, 6 Aug 2026).
            $this->assertContains(self::FISCAL, \App\Support\QrImage::recorded(), "QR encodes the bare fiscal number ({$paper})");
            $this->assertStringNotContainsString('qrserver', $body, "no external QR host — receipt must print offline ({$paper})");
        }
    }

    public function test_submitted_bill_with_om_token_keeps_serial_and_fiscal(): void
    {
        // FBR's only "token" is the Order-Matching queue number — it must
        // print ALONGSIDE the serial row and fiscal line, never replace them.
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper, omStyle: 'token');
            $txn = $this->makeTransaction($company, 'submitted', self::FISCAL, tokenNo: 42);

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString(__('pos.order_match_token_label') . ' 42', $body, "TOKEN 42 prints ({$paper})");
            $this->assertStringContainsString($this->serialRow(), $body, "serial row still prints under token style ({$paper})");
            $this->assertStringContainsString('FBR: ' . self::FISCAL, $body, "fiscal line still prints under token style ({$paper})");
        }
    }

    // ── 2. PENDING — serial never hidden while awaiting the fiscal # ─────

    public function test_pending_bill_prints_serial_in_details_and_pending_badge(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            $txn = $this->makeTransaction($company, 'pending', null);

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString($this->serialRow(), $body, "details serial row prints while pending ({$paper})");
            $this->assertStringContainsString('POS: ' . self::SERIAL, $body, "dashed FBR-PENDING badge carries the serial ({$paper})");
            $this->assertStringContainsString(__('pos.rcpt_fbr_pending'), $body, "FBR PENDING badge prints ({$paper})");
            $this->assertStringNotContainsString('FBR: ', $body, "no fiscal line without a fiscal number ({$paper})");
        }
    }

    // ── 3. OFFLINE — .local-badge prints with serial, no FBR fiscal line ───

    /**
     * Offline FBR bill (fbr_status='offline') — internet was unavailable at
     * sale time so no FBR fiscal number has been assigned yet.  The template
     * must render a .local-badge block and the shop's own serial must appear
     * INSIDE that block so the printed slip is never number-less.
     *
     * Invariants locked (both paper widths — single FBR template, $is58 switch):
     *   • .local-badge block is present
     *   • serial appears inside the .local-badge content
     *   • NO "FBR:" fiscal line (no fiscal number to show)
     */
    public function test_offline_bill_prints_local_badge_with_serial_no_fiscal_line(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            // fbr_status='offline', fbr_invoice_number=null — fiscal not yet assigned.
            $txn = $this->makeTransaction($company, 'offline', null);

            $body = $this->renderBody($company, $txn);

            // .local-badge block must be present (the offline indicator).
            $this->assertStringContainsString(
                'local-badge',
                $body,
                ".local-badge block renders for offline FBR bill ({$paper})"
            );

            // Serial must appear INSIDE the .local-badge block — not just
            // somewhere in the body (e.g. the details table above).
            $badgeStart = strpos($body, 'local-badge');
            $this->assertNotFalse($badgeStart, ".local-badge found ({$paper})");
            $badgeOpen  = strpos($body, '>', $badgeStart);
            $badgeClose = strpos($body, '</div>', $badgeOpen);
            $badgeContent = substr($body, $badgeOpen + 1, $badgeClose - $badgeOpen - 1);
            $this->assertStringContainsString(
                self::SERIAL,
                $badgeContent,
                "serial appears inside .local-badge for offline FBR bill ({$paper})"
            );

            // The FBR-specific offline sync copy must appear (not the PRA wording).
            $this->assertStringContainsString(
                __('pos.receipt_offline_sync_fbr'),
                $body,
                "FBR offline sync copy prints ({$paper})"
            );
            $this->assertStringNotContainsString(
                __('pos.receipt_offline_sync_auto'),
                $body,
                "PRA offline sync copy must NOT appear on FBR receipt ({$paper})"
            );

            // No FBR fiscal line — there is no fiscal number to show yet.
            $this->assertStringNotContainsString(
                'FBR: ',
                $body,
                "no FBR fiscal line on offline bill ({$paper})"
            );
        }
    }

    // ── 3a. FAILED — dashed retry badge, NOT the offline .local-badge ────────

    /**
     * fbr_status='failed' means FBR rejected the submission (e.g. duplicate
     * invoice, validation error).  The cashier needs a clear "retry" signal,
     * NOT the "sync pending" offline indicator that implies the problem will
     * resolve itself once connectivity returns.
     *
     * Invariants locked (both paper widths):
     *   • .local-badge is ABSENT — the offline sync indicator must NOT fire.
     *   • The dashed FBR-PENDING / retry badge IS present.
     *   • The shop's own POS serial appears so the cashier can identify the bill.
     *   • No spurious "FBR: <fiscal>" line (there is no fiscal number).
     */
    public function test_failed_bill_shows_retry_badge_not_local_badge(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            // fbr_status='failed', fbr_invoice_number=null — FBR rejected the bill.
            $txn = $this->makeTransaction($company, 'failed', null);

            $body = $this->renderBody($company, $txn);

            // Must NOT show the offline sync indicator.
            $this->assertStringNotContainsString(
                'local-badge',
                $body,
                ".local-badge must NOT appear for a failed FBR submission ({$paper})"
            );

            // Must show the dashed FBR-PENDING retry badge.
            $this->assertStringContainsString(
                __('pos.rcpt_fbr_pending'),
                $body,
                "dashed FBR-PENDING retry badge must render for a failed bill ({$paper})"
            );

            // The retry note must also appear inside that badge.
            $this->assertStringContainsString(
                __('pos.rcpt_will_retry'),
                $body,
                "retry copy must print on a failed bill ({$paper})"
            );

            // POS serial must be visible so the cashier can identify the bill.
            $this->assertStringContainsString(
                'POS: ' . self::SERIAL,
                $body,
                "POS serial appears inside the retry badge ({$paper})"
            );

            // No spurious FBR fiscal line — there is no fiscal number.
            $this->assertStringNotContainsString(
                'FBR: ',
                $body,
                "no FBR fiscal line for a failed bill ({$paper})"
            );
        }
    }

    // ── 3b. OFFLINE → SUBMITTED (after Desktop Agent sync) ─────────────────

    /**
     * After the Desktop Agent syncs a previously-offline bill, fbr_status
     * transitions from 'offline' to 'submitted' and fbr_invoice_number is set.
     * On re-print the cashier must see the full FBR fiscal QR badge — NOT the
     * dashed .local-badge that appears when the internet was unavailable.
     *
     * Invariants locked (both paper widths):
     *   • .local-badge is ABSENT — no duplicate / stale offline indicator.
     *   • .fbr-badge is PRESENT — the fiscal QR block renders.
     *   • "FBR: <fiscal_number>" line is present inside the badge.
     *   • The shop's own serial still prints in the details table (not hidden).
     */
    public function test_after_sync_offline_to_submitted_shows_fiscal_badge_not_local_badge(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            // Simulate the post-sync state: was 'offline', now 'submitted' with fiscal #.
            $txn = $this->makeTransaction($company, 'submitted', self::FISCAL);

            $body = $this->renderBody($company, $txn);

            // The offline indicator must be gone after sync.
            $this->assertStringNotContainsString(
                'local-badge',
                $body,
                ".local-badge must NOT render after offline→submitted sync ({$paper})"
            );

            // The fiscal QR badge must be present.
            $this->assertStringContainsString(
                'fbr-badge',
                $body,
                ".fbr-badge must render after offline→submitted sync ({$paper})"
            );

            // The FBR fiscal number must appear in the badge.
            $this->assertStringContainsString(
                'FBR: ' . self::FISCAL,
                $body,
                "FBR fiscal line must render after sync ({$paper})"
            );

            // The shop's own serial must also print (details table row).
            $this->assertStringContainsString(
                $this->serialRow(),
                $body,
                "POS serial row must still print after offline→submitted sync ({$paper})"
            );
        }
    }

    // ── 3c. CONFIG_ERROR — permanent settings badge, NOT the retry badge ────────

    /**
     * fbr_status='config_error' means POSID or Token is misconfigured. The
     * auto-retry loop intentionally skips these bills. The receipt must NOT say
     * "will retry automatically" — that would mislead the cashier into waiting
     * for an automatic fix that will never come.
     *
     * Invariants locked (both paper widths):
     *   • .local-badge is ABSENT — not an offline-sync issue.
     *   • "Will retry automatically" copy is ABSENT — no false promise.
     *   • The config-error heading (config_error_autoretry_off) IS present.
     *   • The shop's own POS serial appears so the cashier can identify the bill.
     *   • No spurious "FBR: <fiscal>" line (there is no fiscal number).
     */
    public function test_config_error_bill_shows_settings_badge_not_retry_copy(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            $txn = $this->makeTransaction($company, 'config_error', null);

            $body = $this->renderBody($company, $txn);

            // Must NOT show the offline sync indicator.
            $this->assertStringNotContainsString(
                'local-badge',
                $body,
                ".local-badge must NOT appear for a config_error bill ({$paper})"
            );

            // Must NOT promise auto-retry — the auto-retry loop skips config_error.
            $this->assertStringNotContainsString(
                __('pos.rcpt_will_retry'),
                $body,
                "'Will retry automatically' must NOT appear for config_error — auto-retry is off ({$paper})"
            );

            // The settings-error heading must appear.
            $this->assertStringContainsString(
                __('pos.config_error_autoretry_off'),
                $body,
                "settings-error heading must render for config_error bill ({$paper})"
            );

            // POS serial must be visible.
            $this->assertStringContainsString(
                'POS: ' . self::SERIAL,
                $body,
                "POS serial appears inside the config_error badge ({$paper})"
            );

            // No spurious FBR fiscal line.
            $this->assertStringNotContainsString(
                'FBR: ',
                $body,
                "no FBR fiscal line for a config_error bill ({$paper})"
            );
        }
    }

    // ── 3d. UNKNOWN STATUS — visible warning badge, never "pending / will retry" ──

    /**
     * If a future fbr_status value (e.g. 'queued', 'cancelled') reaches the
     * receipt template without a matching explicit branch, the catch-all must
     * render a clearly abnormal badge. It must NEVER silently present as
     * "pending / will retry" which would actively mislead the cashier.
     *
     * Invariants locked (both paper widths):
     *   • "Will retry automatically" copy is ABSENT.
     *   • The FBR-PENDING heading is ABSENT (unknown ≠ pending).
     *   • The raw status value IS visible so the problem is immediately obvious.
     *   • .local-badge is ABSENT.
     */
    public function test_unknown_fbr_status_shows_visible_warning_not_pending_badge(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            // Use a plausible future status that has no explicit branch.
            $txn = $this->makeTransaction($company, 'queued', null);

            $body = $this->renderBody($company, $txn);

            // Must NOT silently show the "pending / will retry" copy.
            $this->assertStringNotContainsString(
                __('pos.rcpt_will_retry'),
                $body,
                "unknown status must NOT show 'will retry' copy ({$paper})"
            );

            // Must NOT show the standard FBR-PENDING heading — unknown ≠ pending.
            $this->assertStringNotContainsString(
                __('pos.rcpt_fbr_pending'),
                $body,
                "unknown status must NOT show the FBR-PENDING heading ({$paper})"
            );

            // Must NOT show the offline indicator.
            $this->assertStringNotContainsString(
                'local-badge',
                $body,
                ".local-badge must NOT appear for an unknown status ({$paper})"
            );

            // The raw status value must be visible in the badge.
            $this->assertStringContainsString(
                'queued',
                $body,
                "raw unknown status value must appear in the warning badge ({$paper})"
            );
        }
    }

    // ── 4. LOCAL / PROVISIONAL — unchanged (big serial badge, no FBR line) ──

    public function test_local_provisional_bill_keeps_top_badge_serial_only(): void
    {
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            $txn = $this->makeTransaction($company, 'local', null, invoiceMode: 'local');

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString('tb-serial">' . self::SERIAL, $body, "top badge prints the serial ({$paper})");
            $this->assertStringContainsString(__('pos.receipt_provisional_bill'), $body, "PROVISIONAL BILL badge prints ({$paper})");
            $this->assertStringNotContainsString($this->serialRow(), $body, "no duplicate details serial row for badge bills ({$paper})");
            $this->assertStringNotContainsString('FBR: ', $body, "no fiscal line on provisional bills ({$paper})");
        }
    }

    public function test_reporting_off_final_keeps_sale_receipt_badge_serial_only(): void
    {
        // fbr_status NULL + invoice_mode 'fbr' = reporting-OFF final → SALE RECEIPT badge.
        foreach (self::PAPERS as $paper) {
            $company = $this->makeCompany($paper);
            $txn = $this->makeTransaction($company, null, null);

            $body = $this->renderBody($company, $txn);

            $this->assertStringContainsString('tb-serial">' . self::SERIAL, $body, "top badge prints the serial ({$paper})");
            $this->assertStringContainsString(__('pos.receipt_sale_receipt'), $body, "SALE RECEIPT badge prints ({$paper})");
            $this->assertStringNotContainsString($this->serialRow(), $body, "no duplicate details serial row ({$paper})");
            $this->assertStringNotContainsString('FBR: ', $body, "no fiscal line without submission ({$paper})");
        }
    }
}
