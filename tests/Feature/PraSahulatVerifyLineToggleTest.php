<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA Sahulat verify-line toggle on thermal receipts (Task 1004).
 *
 * Root cause: receipt_80mm and receipt_58mm gated the "Scan with PRA Sahulat
 * App to verify" line on `$prefs['show_verify_line'] ?? true`, but `$prefs`
 * was never defined in those templates — the actual prefs variable is `$rp`
 * (resolved by `posReceiptPrefsFor()`). The `?? true` fallback therefore
 * always fired, making the line unconditionally visible regardless of the
 * company's toggle setting.
 *
 * Invariants locked:
 *   A. Toggle OFF  → verify line absent; PRA QR block still present.
 *   B. Toggle ON   → verify line prints alongside PRA QR block.
 *   C. Key absent (never customized) → default ON; line prints.
 *
 * Pattern mirrors PraReceiptSerialWithFiscalTest (rendered-view, sqlite
 * :memory:, unsaved shims via setRelation).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PraSahulatVerifyLineToggleTest.php --testdox
 */
class PraSahulatVerifyLineToggleTest extends TestCase
{
    private const SERIAL     = 'POS-2026-01004';
    private const FISCAL     = '202608160000001004000000001';
    private const TXN_ID     = 9901;
    private const COMPANY_ID = 701;

    /** Both live PRA thermal receipt templates. */
    private const TEMPLATES = [
        'pos.receipts.receipt_80mm',
        'pos.receipts.receipt_58mm',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // pos_transactions — blade gates bill_token block on hasColumn.
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->unsignedInteger('bill_token')->nullable();
        });

        // restaurant_orders — blade om-early-lookup and waiter-name block
        // both gate on hasColumn checks against this table.
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number')->nullable();
            $t->unsignedInteger('token_no')->nullable();
            $t->string('source', 20)->nullable();
        });
    }

    // ── Fixture builders ─────────────────────────────────────────────────────

    /**
     * Build an in-memory Company with `show_verify_line` set to the given
     * value.  Pass `null` to omit the key entirely (never-customized shop).
     */
    private function makeCompany(?bool $showVerifyLine): Company
    {
        $company = new Company();
        $company->id   = self::COMPANY_ID;
        $company->name = 'ZFC Pizza Point Test';
        $company->pra_number_style   = 'serial';
        $company->local_number_style = 'serial';
        $company->order_match_style  = 'off';

        $posPrefs = ['show_menu_qr' => false, 'bold' => false];
        if ($showVerifyLine === null) {
            // Key absent — simulate a shop that never touched the toggle.
            $company->invoice_display_prefs = [
                'pos'       => [],
                'pos_style' => $posPrefs,
            ];
        } else {
            $company->invoice_display_prefs = [
                'pos'       => ['show_verify_line' => $showVerifyLine],
                'pos_style' => $posPrefs,
            ];
        }

        return $company;
    }

    /**
     * Build a PRA-submitted transaction with a fiscal number so the QR block
     * would render (assuming the toggle allows the verify line).
     */
    private function makeSubmittedTransaction(Company $company): PosTransaction
    {
        $txn = new PosTransaction([
            'invoice_number'     => self::SERIAL,
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => self::FISCAL,
            'payment_method'     => 'cash',
            'subtotal'           => 500,
            'tax_rate'           => 16,
            'tax_amount'         => 80,
            'discount_amount'    => 0,
            'total_amount'       => 580,
        ]);
        $txn->id         = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->bill_token = null;
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
     * Render the body content (after </head>) so the <title> carrying the
     * invoice number cannot produce false positives.
     */
    private function renderBody(string $template, Company $company, PosTransaction $txn): string
    {
        $html = view($template, ['transaction' => $txn, 'company' => $company])->render();
        $pos  = strpos($html, '</head>');
        $this->assertNotFalse($pos, "rendered receipt has a </head> ({$template})");
        return substr($html, $pos);
    }

    // ── A. Toggle OFF — verify line absent, QR block still present ───────────

    /**
     * When show_verify_line = false the "Scan with PRA Sahulat App" phrase
     * must not appear in either thermal receipt, but the QR <div class="qr-code">
     * block must still be present (the toggle only hides the text, not the code).
     *
     * Note: 80mm uses alt="PRA Verification QR"; 58mm uses alt="PRA QR".
     * We anchor QR presence on the shared class="qr-code" wrapper div.
     */
    public function test_verify_line_hidden_when_toggle_is_off(): void
    {
        $company = $this->makeCompany(showVerifyLine: false);
        $txn     = $this->makeSubmittedTransaction($company);

        foreach (self::TEMPLATES as $template) {
            $body = $this->renderBody($template, $company, $txn);

            // The verify phrase must be absent.
            $this->assertStringNotContainsString(
                'Sahulat',
                $body,
                "toggle OFF: 'Sahulat' verify phrase must not appear in {$template}"
            );

            // The QR block must still be rendered (class is common to both templates).
            $this->assertStringContainsString(
                'class="qr-code"',
                $body,
                "toggle OFF: QR code div must still be present in {$template}"
            );
        }
    }

    // ── B. Toggle ON — verify line prints alongside QR ────────────────────────

    /**
     * When show_verify_line = true the verify phrase must appear in both
     * templates alongside the QR block.
     */
    public function test_verify_line_prints_when_toggle_is_on(): void
    {
        $company = $this->makeCompany(showVerifyLine: true);
        $txn     = $this->makeSubmittedTransaction($company);

        foreach (self::TEMPLATES as $template) {
            $body = $this->renderBody($template, $company, $txn);

            $this->assertStringContainsString(
                'Sahulat',
                $body,
                "toggle ON: 'Sahulat' verify phrase must appear in {$template}"
            );

            $this->assertStringContainsString(
                'class="qr-code"',
                $body,
                "toggle ON: QR code div must be present in {$template}"
            );
        }
    }

    // ── C. Key absent (never customized) — default ON ────────────────────────

    /**
     * A shop that has never set show_verify_line (key absent from DB) must see
     * the default-ON behaviour: verify line prints on both templates.
     */
    public function test_verify_line_prints_by_default_when_key_absent(): void
    {
        $company = $this->makeCompany(showVerifyLine: null); // key absent
        $txn     = $this->makeSubmittedTransaction($company);

        foreach (self::TEMPLATES as $template) {
            $body = $this->renderBody($template, $company, $txn);

            $this->assertStringContainsString(
                'Sahulat',
                $body,
                "key absent (default): 'Sahulat' verify phrase must appear in {$template}"
            );
        }
    }
}
