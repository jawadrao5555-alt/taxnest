<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoicePdfService;
use Tests\TestCase;

/**
 * A DI invoice must be headlined by the business the buyer actually bought
 * from.
 *
 * Background (reported by a live distributor, Aug 2026): the shop runs two
 * addresses under ONE NTN and gave each branch its own trading name. The PDF
 * swapped the address per branch but kept printing the company's legal name,
 * so an invoice sold from the second address carried the first business's
 * name. Worse, the branch line was suppressed for a branch flagged as head
 * office — and that shop's second trading name sat on exactly such a branch,
 * so its name appeared nowhere on the bill.
 *
 * Rules pinned here:
 *  - A branch whose name differs from the legal name headlines the invoice,
 *    head office or not, and signs it ("For <trading name>").
 *  - ONLY that branch's identity appears: the registered company name is NOT
 *    printed as a second line. The owner rejected it — a buyer holding a bill
 *    from one shop must not see another address's business named on it. The
 *    NTN still ties the document to the filer, and the FBR payload keeps the
 *    registered identity regardless.
 *  - A branch named the same as the company prints that name ONCE.
 *  - No branch = company name, exactly as before.
 *
 * These render the real Blade with in-memory models (no DB, no HTTP).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/DiInvoiceBranchIdentityTest.php --testdox
 */
class DiInvoiceBranchIdentityTest extends TestCase
{
    private const LEGAL_NAME = 'AL REHMAN TRADERS';

    private function company(): Company
    {
        $company = new Company([
            'name' => self::LEGAL_NAME,
            'address' => 'AHMED PUR SHARKIA',
            'city' => 'AHMED PUR EAST',
            'ntn' => 'B282410-8',
            'registration_no' => 'B282410-8',
            'email' => 'seller@example.test',
            'mobile' => '03000000000',
        ]);
        $company->id = 22;

        return $company;
    }

    private function branch(string $name, string $address, string $city, bool $headOffice): Branch
    {
        $branch = new Branch([
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'is_head_office' => $headOffice,
        ]);
        $branch->id = $headOffice ? 3 : 2;
        $branch->company_id = 22;

        return $branch;
    }

    private function render(?Branch $branch): string
    {
        $item = new InvoiceItem([
            'hs_code' => '2402.2000',
            'description' => 'Morven',
            'default_uom' => 'Thousand Unit',
            'quantity' => 0.16,
            'price' => 9903.77,
            'tax' => 285.23,
            'tax_rate' => 18,
        ]);
        $item->id = 8001;

        $invoice = new Invoice([
            'buyer_name' => 'Hassan Super Store',
            'buyer_address' => 'Ghalla Mandi Road',
            'buyer_registration_type' => 'Unregistered',
            'destination_province' => 'Punjab',
            'supplier_province' => 'Punjab',
            'document_type' => 'Sale Invoice',
            'invoice_number' => 'DI-BRANCH-IDENTITY',
            'status' => 'draft',
            'total_amount' => 1869.83,
        ]);
        $invoice->id = 90211;
        $invoice->company_id = 22;
        $invoice->branch_id = $branch?->id;
        $invoice->created_at = now();
        $invoice->updated_at = now();
        $invoice->setRelation('items', collect([$item]));
        $invoice->setRelation('company', $this->company());
        $invoice->setRelation('branch', $branch);

        return view('invoice.pdf-bw', InvoicePdfService::buildData($invoice))->render();
    }

    /** The rendered seller headline, i.e. the big name at the top. */
    private function headline(string $html): string
    {
        preg_match('/class="seller-name">(.*?)<\/div>/s', $html, $m);

        return trim($m[1] ?? '');
    }

    public function test_a_branch_trading_under_its_own_name_headlines_the_invoice(): void
    {
        $html = $this->render($this->branch('AL REHMAN TRADERS ONE', 'OLD POST OFFICE SABZI MANDI ROAD', 'AHMAD PUR EAST', false));

        $this->assertSame('AL REHMAN TRADERS ONE', $this->headline($html));
        $this->assertStringContainsString('OLD POST OFFICE SABZI MANDI ROAD', $html);
        $this->assertStringContainsString('For AL REHMAN TRADERS ONE', $html, 'The signature block must name the trading business.');
    }

    /**
     * The regression that hid the shop's second name: the branch line used to
     * render only for a non-head-office branch.
     */
    public function test_a_head_office_branch_with_its_own_name_is_not_silently_dropped(): void
    {
        $html = $this->render($this->branch('AL REHMAN TRADERS ONE', 'OLD POST OFFICE SABZI MANDI ROAD', 'AHMAD PUR EAST', true));

        $this->assertSame('AL REHMAN TRADERS ONE', $this->headline($html));
    }

    /**
     * The whole point of the change the owner asked for: a bill sold from one
     * trading name must not carry the other business's name (nor its address)
     * anywhere on it.
     */
    public function test_only_the_selling_branch_appears_no_head_office_reference(): void
    {
        // A trading name that is NOT a superstring of the legal name, so the
        // assertion below is about the legal name really being absent.
        $html = $this->render($this->branch('CHOUDHRY TRADERS', 'NEW HOUSING SCHEME', 'LIAQATPUR', false));

        $this->assertStringNotContainsString(
            self::LEGAL_NAME,
            $html,
            'The registered company name must not ride along on a branch invoice.'
        );
        $this->assertStringNotContainsString(
            'AHMED PUR SHARKIA',
            $html,
            'Nor may the head-office address.'
        );
        $this->assertStringContainsString('NEW HOUSING SCHEME', $html);
        $this->assertStringContainsString('NTN: B282410-8', $html, 'The NTN still ties the bill to the filer.');
    }

    /**
     * Branch address and city are one unit. A branch row filled in only
     * partially (both columns are nullable) must not pair its own city with
     * the head office's street — that prints an address nobody trades from.
     */
    public function test_a_partly_filled_branch_never_mixes_in_the_head_office_street(): void
    {
        $branch = $this->branch('CHOUDHRY TRADERS', '', 'LIAQATPUR', false);
        $html = $this->render($branch);

        $this->assertStringNotContainsString('AHMED PUR SHARKIA', $html, 'The head office street leaked into a branch invoice.');
        $this->assertStringContainsString('LIAQATPUR', $html);
    }

    /**
     * The same identity must reach every buyer-facing channel — the public
     * share page and the delivery email read it from here too.
     */
    public function test_the_shared_seller_identity_names_the_branch(): void
    {
        $invoice = new Invoice(['invoice_number' => 'D002']);
        $invoice->id = 90212;
        $invoice->company_id = 22;
        $invoice->setRelation('company', $this->company());
        $invoice->setRelation('branch', $this->branch('CHOUDHRY TRADERS', 'NEW HOUSING SCHEME', 'LIAQATPUR', false));

        $seller = \App\Support\InvoiceSellerIdentity::for($invoice);

        $this->assertSame('CHOUDHRY TRADERS', $seller['name']);
        $this->assertSame('NEW HOUSING SCHEME', $seller['address']);
        $this->assertSame(self::LEGAL_NAME, $seller['legal_name'], 'The legal name stays available, it is just not printed beside the branch.');
    }

    public function test_a_branch_named_after_the_company_prints_that_name_once(): void
    {
        $html = $this->render($this->branch(self::LEGAL_NAME, 'NEW HOUSING SCHEME', 'LIAQATPUR', false));

        $this->assertSame(self::LEGAL_NAME, $this->headline($html));
        $this->assertSame(1, substr_count($html, 'class="seller-name">'), 'Printing the same name twice reads like a mistake.');
        $this->assertStringContainsString('NEW HOUSING SCHEME', $html, 'The branch still supplies the address.');
    }

    public function test_an_invoice_without_a_branch_is_unchanged(): void
    {
        $html = $this->render(null);

        $this->assertSame(self::LEGAL_NAME, $this->headline($html));
        $this->assertStringContainsString('AHMED PUR SHARKIA', $html, 'Falls back to the company address.');
    }
}
