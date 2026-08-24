<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoicePdfService;
use Tests\TestCase;

/**
 * DI invoice PDFs must print FRACTIONAL quantities.
 *
 * Background: a distributor filing cigarette sales declares quantity in the
 * FBR unit "Thousand Unit" (1 = 1000 sticks).  A shop buying 2 packs is
 * 0.04 Thousand Unit, and a whole invoice is often well under 1.0.
 *
 * Three of the invoice templates used to render the quantity cell with
 * number_format($item->quantity, 0), which turned every one of those lines
 * into a bare "0" on the buyer's copy — including invoice.pdf-bw, which is
 * the template behind EVERY DI PDF path (view, download, bulk ZIP, emailed
 * attachment and the FBR Audit Pack).
 *
 * The line amount stayed correct, so nothing would have thrown; the printed
 * quantity would simply have been wrong on every cigarette invoice.
 *
 * These tests render the real Blade files with in-memory models (no DB, no
 * HTTP) and pin that a fractional quantity survives to the rendered output.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/InvoicePdfDecimalQuantityTest.php --testdox
 */
class InvoicePdfDecimalQuantityTest extends TestCase
{
    /** Every template that renders the DI invoice line table. */
    private const TEMPLATES = [
        'invoice.pdf-bw',           // default for all DI PDF paths
        'invoice.pdf-annex',
        'invoice.pdf-professional',
        'invoice.pdf-modern',
    ];

    /**
     * Real figures from an FBR-verified cigarette invoice, restated in
     * Thousand Unit: 2 packs = 0.04, 8 packs = 0.16, 20 packs = 0.40.
     *
     * The last line carries a WHOLE quantity of 7 with a price and amount
     * (7 x 5,123.45 = 35,864.15) that cannot themselves render as "7" or
     * "7.00", so the row-scoped assertion below can only be about the
     * quantity cell.
     */
    private const LINES = [
        ['Crafted By Marlboro', 0.04, 6985.50],
        ['Morven', 0.16, 9903.77],
        ['Morven Classic', 0.40, 7006.00],
        ['Carton Line', 7.00, 5123.45],
    ];

    /**
     * @param array<int, array{0: string, 1: float|string, 2: float}>|null $lines
     */
    private function invoice(?array $lines = null): Invoice
    {
        $company = new Company([
            'name' => 'Al Rehman Traders',
            'address' => 'Abbasia Mohalla Old Post Office Sabzi Mandi Road',
            'city' => 'Ahmedpur East',
            'ntn' => 'B282410-8',
            'registration_no' => 'B282410-8',
            'email' => 'seller@example.test',
            'mobile' => '03000000000',
            'phone' => '03000000000',
        ]);
        $company->id = 4242;

        $items = collect();
        foreach ($lines ?? self::LINES as $i => [$description, $quantity, $price]) {
            $item = new InvoiceItem([
                'hs_code' => '2402.2000',
                'description' => $description,
                'default_uom' => 'Thousand Unit',
                'quantity' => $quantity,
                'price' => $price,
                'tax' => round(floatval($quantity) * $price * 0.18, 2),
                'tax_rate' => 18,
            ]);
            $item->id = 8000 + $i;
            $items->push($item);
        }

        $invoice = new Invoice([
            'buyer_name' => 'Hassan Super Store',
            'buyer_address' => 'Ghalla Mandi Road Ahmedpur East',
            'buyer_registration_type' => 'Unregistered',
            'destination_province' => 'Punjab',
            'supplier_province' => 'Punjab',
            'document_type' => 'Sale Invoice',
            'invoice_number' => 'DI-DECIMAL-QTY',
            'status' => 'draft',
            'total_amount' => 0,
        ]);
        $invoice->id = 90210;
        $invoice->company_id = $company->id;
        $invoice->created_at = now();
        $invoice->updated_at = now();
        $invoice->setRelation('items', $items);
        $invoice->setRelation('company', $company);

        return $invoice;
    }

    private function render(string $template, ?array $lines = null): string
    {
        return view($template, InvoicePdfService::buildData($this->invoice($lines)))->render();
    }

    /** The single <tr> block that renders the given line description. */
    private function lineRow(string $html, string $description): string
    {
        preg_match_all('/<tr\b[^>]*>.*?<\/tr>/s', $html, $matches);

        foreach ($matches[0] as $row) {
            if (str_contains($row, $description)) {
                return $row;
            }
        }

        $this->fail("No table row rendered for the line \"{$description}\".");
    }

    public function test_every_invoice_template_prints_fractional_quantities(): void
    {
        foreach (self::TEMPLATES as $template) {
            $html = $this->render($template);

            foreach ([['Crafted By Marlboro', '0.04'], ['Morven', '0.16'], ['Morven Classic', '0.40']] as [$line, $expected]) {
                $this->assertStringContainsString(
                    ">{$expected}<",
                    $this->lineRow($html, $line),
                    "{$template} dropped the fractional quantity {$expected} on the \"{$line}\" line — "
                        . 'a Thousand Unit line would print as 0.'
                );
            }
        }
    }

    public function test_whole_quantities_stay_free_of_trailing_decimals(): void
    {
        foreach (self::TEMPLATES as $template) {
            $row = $this->lineRow($this->render($template), 'Carton Line');

            $this->assertStringContainsString(
                '>7<',
                $row,
                "{$template} should print a whole quantity of 7 as \"7\"."
            );
            $this->assertStringNotContainsString(
                '>7.00<',
                $row,
                "{$template} should not pad a whole quantity to \"7.00\"."
            );
        }
    }

    /**
     * On the owner's cPanel production host, PDO hands back non-cast numeric
     * columns as STRINGS while dev returns floats. The model cast is what
     * keeps the two environments in step, so pin it here: if the cast were
     * ever dropped, a live invoice would render differently from a dev one.
     */
    public function test_a_quantity_arriving_as_a_database_string_still_renders_as_a_fraction(): void
    {
        $lines = [
            ['String Qty Line', '0.04', 6985.50],
            ['Whole String Qty Line', '7.00', 5123.45],
        ];

        foreach (self::TEMPLATES as $template) {
            $html = $this->render($template, $lines);

            $this->assertStringContainsString(
                '>0.04<',
                $this->lineRow($html, 'String Qty Line'),
                "{$template} did not render a string quantity \"0.04\" as 0.04."
            );
            $this->assertStringContainsString(
                '>7<',
                $this->lineRow($html, 'Whole String Qty Line'),
                "{$template} did not render a string quantity \"7.00\" as 7."
            );
        }
    }

    /**
     * Structural guard: the quantity cell must never go back to a 0-decimal
     * format, in any current or future invoice template.
     */
    public function test_no_invoice_template_formats_quantity_to_zero_decimals(): void
    {
        $files = glob(resource_path('views/invoice/pdf*.blade.php'));
        $this->assertNotEmpty($files, 'No invoice PDF templates found.');

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                'number_format($item->quantity, 0)',
                file_get_contents($file),
                basename($file) . ' formats the quantity to 0 decimals — fractional '
                    . 'Thousand Unit quantities would print as 0.'
            );
        }
    }
}
