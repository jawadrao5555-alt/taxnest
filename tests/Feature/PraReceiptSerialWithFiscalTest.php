<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA RECEIPT — SERIAL PRINTS ALONGSIDE FISCAL # — Task 787 (15 Aug 2026).
 *
 * Task 763 fixed the real customer complaint (ZFC Pizza Point video, 15 Aug
 * 2026): submitted PRA fiscal receipts used to print ONLY the long PRA
 * Fiscal # and hide the shop's own serial. That fix lives only in the two
 * Blade templates — nothing locked it. A future template edit (the 80mm /
 * 58mm number blocks are duplicated and get touched often) could silently
 * reintroduce the "fiscal only" pattern. This file locks the invariants on
 * BOTH templates.
 *
 * Invariants under lock (both templates):
 *   • SUBMITTED + serial style: PRA Fiscal # row AND POS Invoice # serial
 *     row BOTH print.  No bill hides the shop's own serial.
 *   • SUBMITTED + token style: big bill token AND PRA Fiscal # row AND the
 *     small "Ref" serial row all coexist.  Token never replaces the serial.
 *   • LOCAL stream (invoice_mode='local'): PROVISIONAL BILL top-badge carries
 *     the serial; no PRA Fiscal row ever appears.
 *   • PENDING (fiscal not yet returned): serial in top badge; no PRA Fiscal
 *     row (nothing to show yet).
 *   • Reporting-OFF final (pra_status=null + invoice_mode='pra'): SALE
 *     RECEIPT top-badge carries the serial; no PRA Fiscal row.
 *
 * Pattern mirrors FbrPosReceiptSerialWithFiscalTest / PosReceiptOrderMatchLayoutTest
 * (rendered-view, sqlite :memory:, unsaved shims via setRelation — exactly the
 * variable set PosController::receipt() passes to the blade).
 *
 * The blade gates the bill_token block on Schema::hasColumn('pos_transactions',
 * 'bill_token') and the om-early-lookup on Schema::hasColumn('restaurant_orders',
 * 'token_no'), so minimal tables with those columns are created.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PraReceiptSerialWithFiscalTest.php --testdox
 */
class PraReceiptSerialWithFiscalTest extends TestCase
{
    private const SERIAL     = 'POS-2026-00999';
    private const FISCAL     = '202608150000123456789012345';
    private const TOKEN      = 77;
    private const TXN_ID     = 8801;
    private const COMPANY_ID = 601;

    /** Both live PRA receipt templates — every assertion runs against each. */
    private const TEMPLATES = [
        'pos.receipts.receipt_80mm',
        'pos.receipts.receipt_58mm',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // pos_transactions — blade gates the bill_token block on
        // hasColumn('pos_transactions', 'bill_token').
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->unsignedInteger('bill_token')->nullable();
        });

        // restaurant_orders — blade om-early-lookup and waiter-name block both
        // gate on hasColumn checks against this table.
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number')->nullable();
            $t->unsignedInteger('token_no')->nullable();
            $t->string('source', 20)->nullable();
        });
    }

    // ── Fixture builders (mirror PosController::receipt() variable set) ──

    private function makeCompany(
        string $praNumStyle   = 'serial',
        string $localNumStyle = 'serial'
    ): Company {
        $company = new Company();
        $company->id                  = self::COMPANY_ID;
        $company->name                = 'PRA Serial Lock Co';
        $company->pra_number_style    = $praNumStyle;
        $company->local_number_style  = $localNumStyle;
        $company->order_match_style   = 'off';
        // Suppress QR and footer blocks — avoid publicBillToken / publicUrl DB
        // lookups that are orthogonal to this test.
        $company->invoice_display_prefs = [
            'pos_style' => ['show_menu_qr' => false, 'bold' => false],
        ];
        return $company;
    }

    private function makeTransaction(
        Company $company,
        ?string $praStatus,
        ?string $praInvoiceNumber,
        string  $invoiceMode = 'pra',
        ?int    $billToken   = null
    ): PosTransaction {
        $txn = new PosTransaction([
            'invoice_number'     => self::SERIAL,
            'invoice_mode'       => $invoiceMode,
            'pra_status'         => $praStatus,
            'pra_invoice_number' => $praInvoiceNumber,
            'payment_method'     => 'cash',
            'subtotal'           => 500,
            'tax_rate'           => 16,
            'tax_amount'         => 80,
            'discount_amount'    => 0,
            'total_amount'       => 580,
        ]);
        $txn->id         = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->bill_token = $billToken;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type'         => 'product',
            'item_name'         => 'Test Item',
            'quantity'          => 1,
            'unit_price'        => 500,
            'subtotal'          => 500,
            'is_tax_exempt'     => false,
            'is_third_schedule' => false,
        ]);
        $item->id = 1;

        $txn->setRelation('items',    collect([$item]));
        $txn->setRelation('payments', collect());
        $txn->setRelation('company',  $company);
        $txn->setRelation('terminal', null);
        $txn->setRelation('creator',  null);
        $txn->setRelation('rider',    null);
        return $txn;
    }

    /**
     * Rendered body after </head> — the <title> tag carries the invoice
     * number and would make "serial prints" assertions pass vacuously.
     */
    private function renderBody(string $template, Company $company, PosTransaction $txn): string
    {
        $html = view($template, ['transaction' => $txn, 'company' => $company])->render();
        $pos  = strpos($html, '</head>');
        $this->assertNotFalse($pos, "rendered receipt has a </head> ({$template})");
        return substr($html, $pos);
    }

    // ── 1. SUBMITTED + serial number style ───────────────────────────────

    /**
     * submitted fiscal bill + serial style → PRA Fiscal # row AND POS Invoice
     * # row both print.  The shop's own serial must never be hidden.
     */
    public function test_submitted_serial_style_prints_pra_fiscal_row_and_pos_invoice_row(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany(praNumStyle: 'serial');
            // bill_token is set but style='serial' → rcptBillToken must stay null.
            $txn = $this->makeTransaction($company, 'submitted', self::FISCAL, billToken: self::TOKEN);

            $body = $this->renderBody($template, $company, $txn);

            // PRA Fiscal # row must print.
            $this->assertStringContainsString(
                __('pos.receipt_pra_fiscal') . ':',
                $body,
                "PRA Fiscal label prints ({$template})"
            );
            $this->assertStringContainsString(
                self::FISCAL,
                $body,
                "fiscal number value prints ({$template})"
            );

            // Shop's own serial must also print as a full POS Invoice # row.
            $this->assertStringContainsString(
                __('pos.receipt_pos_invoice') . ':',
                $body,
                "POS Invoice label prints ({$template})"
            );
            $this->assertStringContainsString(
                self::SERIAL,
                $body,
                "serial value prints ({$template})"
            );

            // Serial style must NOT produce a "Ref" row — that is token-style only.
            $this->assertStringNotContainsString(
                __('pos.bill_ref_label') . ':',
                $body,
                "no Ref row in serial style ({$template})"
            );
        }
    }

    // ── 2. SUBMITTED + token number style ────────────────────────────────

    /**
     * submitted fiscal bill + token style → big token dominant, PLUS PRA
     * Fiscal # row, PLUS the small Ref serial row — all three coexist.
     * Token must never replace the shop's own serial.
     */
    public function test_submitted_token_style_prints_token_pra_fiscal_and_ref_serial(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany(praNumStyle: 'token');
            $txn = $this->makeTransaction($company, 'submitted', self::FISCAL, billToken: self::TOKEN);

            $body = $this->renderBody($template, $company, $txn);

            // Big daily token must dominate (large font span).
            $this->assertStringContainsString(
                (string) self::TOKEN,
                $body,
                "big bill token prints ({$template})"
            );

            // PRA Fiscal # row must still print.
            $this->assertStringContainsString(
                __('pos.receipt_pra_fiscal') . ':',
                $body,
                "PRA Fiscal label prints alongside token ({$template})"
            );
            $this->assertStringContainsString(
                self::FISCAL,
                $body,
                "fiscal number value prints alongside token ({$template})"
            );

            // Serial must appear as the small Ref row (token already dominant).
            $this->assertStringContainsString(
                __('pos.bill_ref_label') . ':',
                $body,
                "Ref row prints to carry serial under token style ({$template})"
            );
            $this->assertStringContainsString(
                self::SERIAL,
                $body,
                "serial value prints in the Ref row ({$template})"
            );

            // Token style collapses the serial to a Ref row — no full POS Invoice row.
            $this->assertStringNotContainsString(
                __('pos.receipt_pos_invoice') . ':',
                $body,
                "no full POS Invoice row when serial already shows as Ref ({$template})"
            );
        }
    }

    // ── 3. LOCAL stream — serial in top badge, no PRA Fiscal row ─────────

    /**
     * local-stream bill (invoice_mode='local') → PROVISIONAL BILL top badge
     * carries the serial; no PRA Fiscal row (these bills are never submitted).
     */
    public function test_local_stream_bill_prints_serial_in_top_badge_no_fiscal_row(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            $txn = $this->makeTransaction($company, null, null, invoiceMode: 'local');

            $body = $this->renderBody($template, $company, $txn);

            // PROVISIONAL BILL top badge must appear.
            $this->assertStringContainsString(
                __('pos.receipt_provisional_bill'),
                $body,
                "PROVISIONAL BILL badge prints ({$template})"
            );
            // Serial must be present (in the top badge).
            $this->assertStringContainsString(
                self::SERIAL,
                $body,
                "serial prints ({$template})"
            );

            // No PRA Fiscal row — local bills never get one.
            $this->assertStringNotContainsString(
                __('pos.receipt_pra_fiscal') . ':',
                $body,
                "no PRA Fiscal row on local bill ({$template})"
            );
            // Serial is in the TOP badge only — no duplicate inv-table serial row.
            $this->assertStringNotContainsString(
                __('pos.receipt_pos_invoice') . ':',
                $body,
                "no duplicate POS Invoice row for top-badge bill ({$template})"
            );
        }
    }

    // ── 4. PENDING — serial in top badge, no PRA Fiscal row yet ──────────

    /**
     * pending PRA bill (queued, fiscal # not yet returned) → serial in top
     * badge, no PRA Fiscal row (nothing to show yet).
     */
    public function test_pending_bill_prints_serial_in_top_badge_no_fiscal_row(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            $txn = $this->makeTransaction($company, 'pending', null);

            $body = $this->renderBody($template, $company, $txn);

            // Serial must print (in the top badge, since $rcptTopBadge = true).
            $this->assertStringContainsString(
                self::SERIAL,
                $body,
                "serial prints while pending ({$template})"
            );

            // No PRA Fiscal row — bill not yet submitted.
            $this->assertStringNotContainsString(
                __('pos.receipt_pra_fiscal') . ':',
                $body,
                "no PRA Fiscal row while pending ({$template})"
            );
        }
    }

    // ── 5. OFFLINE — .local-badge prints, serial in body, no PRA Fiscal row ─

    /**
     * Offline PRA bill (pra_status='offline') — the fiscal number has not been
     * assigned yet (no internet at sale time).  The template renders a
     * .local-badge block and the shop's own serial must still appear in the
     * rendered body so the receipt is not number-less.  No PRA Fiscal row
     * should be printed because there is no fiscal number to show.
     *
     * Invariants locked (both 80mm and 58mm):
     *   • .local-badge block is present
     *   • serial appears somewhere in the body (in the invoice box and/or badge)
     *   • NO PRA Fiscal # label row (nothing to show yet)
     *   • NO pra_invoice_number value in the output (offline = no fiscal yet)
     */
    public function test_offline_bill_prints_local_badge_with_serial_no_fiscal_row(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            // pra_status='offline', no pra_invoice_number — fiscal not yet assigned.
            $txn = $this->makeTransaction($company, 'offline', null);

            $body = $this->renderBody($template, $company, $txn);

            // .local-badge block must be present (the offline indicator).
            $this->assertStringContainsString(
                'local-badge',
                $body,
                ".local-badge block renders for offline bill ({$template})"
            );

            // Shop's own serial must appear INSIDE the .local-badge block —
            // not just somewhere in the body (e.g. the invoice-numbers box above).
            // Extract the badge's inner content by finding the block between the
            // opening div and its closing tag.
            $badgeStart = strpos($body, 'local-badge');
            $this->assertNotFalse(
                $badgeStart,
                ".local-badge found for serial-in-badge assertion ({$template})"
            );
            $badgeOpen  = strpos($body, '>', $badgeStart);
            $badgeClose = strpos($body, '</div>', $badgeOpen);
            $badgeContent = substr($body, $badgeOpen + 1, $badgeClose - $badgeOpen - 1);
            $this->assertStringContainsString(
                self::SERIAL,
                $badgeContent,
                "serial appears inside .local-badge for offline bill ({$template})"
            );

            // No PRA Fiscal # label row — there is no fiscal number to show.
            $this->assertStringNotContainsString(
                __('pos.receipt_pra_fiscal') . ':',
                $body,
                "no PRA Fiscal row on offline bill ({$template})"
            );

            // The FISCAL constant must not appear anywhere — offline bills
            // carry no pra_invoice_number, so this value should be absent.
            $this->assertStringNotContainsString(
                self::FISCAL,
                $body,
                "fiscal number value absent on offline bill ({$template})"
            );
        }
    }

    // ── 6. Reporting-OFF final — serial in top badge, no PRA Fiscal row ──

    /**
     * Reporting-OFF final (pra_status=NULL + invoice_mode='pra') → SALE RECEIPT
     * top badge carries the serial; no PRA Fiscal row.
     */
    public function test_reporting_off_final_prints_serial_in_sale_receipt_badge(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            $txn = $this->makeTransaction($company, null, null); // pra_status=NULL = reporting-OFF

            $body = $this->renderBody($template, $company, $txn);

            // SALE RECEIPT top badge must appear.
            $this->assertStringContainsString(
                __('pos.receipt_sale_receipt'),
                $body,
                "SALE RECEIPT badge prints ({$template})"
            );
            $this->assertStringContainsString(
                self::SERIAL,
                $body,
                "serial prints ({$template})"
            );

            // No PRA Fiscal row on reporting-OFF finals.
            $this->assertStringNotContainsString(
                __('pos.receipt_pra_fiscal') . ':',
                $body,
                "no PRA Fiscal row on reporting-OFF final ({$template})"
            );
        }
    }
}
