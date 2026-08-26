<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\InvoiceNumberingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The number a shop reads for its own invoice must be short.
 *
 * Two long numbers used to fight for that job. FBR issues a ~30 character
 * reference for every filed invoice, and it headlined every screen, email and
 * PDF; underneath it, our own number was itself a 13-digit registration prefix
 * glued to the sequence ("3120180085013DI05962"). The shop was reading twenty
 * characters to tell one bill from the next.
 *
 * Rules pinned here:
 *  - A new invoice is numbered D0001, per company, growing past the pad.
 *  - Our number is what every human-facing surface shows; FBR's reference is
 *    never the invoice's name (it keeps its own labelled place beside the QR).
 *  - Invoices already stored with the long spelling are DISPLAYED short — the
 *    stored value is left exactly as filed, because the regulator-facing
 *    reference and debit-note links are rebuilt from it.
 *  - A sequence already used under ANY spelling is never handed out again;
 *    reusing it would send FBR a reference it has already filed.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/DiShortInvoiceNumberTest.php --testdox
 */
class DiShortInvoiceNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('next_invoice_number')->default(1);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('invoice_number')->nullable();
            $t->string('internal_invoice_number')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->timestamps();
        });
    }

    private function company(int $next = 1): Company
    {
        $company = new Company(['name' => 'AL REHMAN TRADERS']);
        $company->next_invoice_number = $next;
        $company->save();

        return $company;
    }

    private function storeInvoice(int $companyId, string $number): void
    {
        Invoice::withoutGlobalScopes()->insert([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'internal_invoice_number' => $number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_new_company_starts_at_d0001(): void
    {
        $company = $this->company();

        $this->assertSame('D0001', InvoiceNumberingService::peekNextNumber($company->id));
        $this->assertSame('D0001', InvoiceNumberingService::generateNextNumber($company->id));
        $this->assertSame('D0002', InvoiceNumberingService::generateNextNumber($company->id));
    }

    public function test_the_number_grows_past_the_pad_instead_of_being_cut(): void
    {
        $company = $this->company(12345);

        $this->assertSame('D12345', InvoiceNumberingService::generateNextNumber($company->id));
    }

    /**
     * The whole point: what the shop reads is short, whatever shape the number
     * was stored in years ago.
     */
    public function test_a_long_stored_number_is_shown_short(): void
    {
        $invoice = new Invoice([
            'invoice_number' => '3120180085013DI05962',
            'internal_invoice_number' => '3120180085013DI05962',
        ]);

        $this->assertSame('D5962', $invoice->display_invoice_number);
    }

    public function test_fbrs_own_reference_is_never_the_invoices_name(): void
    {
        $invoice = new Invoice([
            'invoice_number' => 'D0036',
            'internal_invoice_number' => 'D0036',
            'fbr_invoice_number' => '3120180085013DI8I449K417830',
        ]);

        $this->assertSame('D0036', $invoice->display_invoice_number);
    }

    /**
     * A number we cannot read a sequence out of is handed back untouched — a
     * hand-typed import must never be renamed into a number nobody issued.
     */
    public function test_an_unrecognised_number_is_left_alone(): void
    {
        $invoice = new Invoice([
            'invoice_number' => '3620344337269DI1782545961366',
            'internal_invoice_number' => '3620344337269DI1782545961366',
        ]);

        $this->assertSame('3620344337269DI1782545961366', $invoice->display_invoice_number);

        $typed = new Invoice(['invoice_number' => 'INV-2026-000672']);
        $this->assertSame('INV-2026-000672', $typed->display_invoice_number);
    }

    /**
     * The sequence — not the spelling — is what FBR has already seen.
     */
    public function test_a_sequence_filed_under_the_long_spelling_is_never_reissued(): void
    {
        $company = $this->company();
        $this->storeInvoice($company->id, '3120180085013DI00001');
        $this->storeInvoice($company->id, '3120180085013DI00002');

        $this->assertSame('D0003', InvoiceNumberingService::generateNextNumber($company->id));
    }

    /** The narrower pad we used to issue counts as the same invoice. */
    public function test_a_sequence_issued_under_the_older_pad_is_never_reissued(): void
    {
        $company = $this->company();
        $this->storeInvoice($company->id, 'D001');

        $this->assertSame('D0002', InvoiceNumberingService::generateNextNumber($company->id));
    }

    /**
     * Shortening OUR number must not move a single character of what the
     * regulator receives: invoice 1 is still {identifier}DI00001 to FBR.
     */
    public function test_the_regulator_facing_reference_is_unchanged(): void
    {
        $service = (new \ReflectionClass(\App\Services\FbrService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(\App\Services\FbrService::class, 'buildFbrFormatInvoiceRef');
        $method->setAccessible(true);

        $company = new Company(['name' => 'AL REHMAN TRADERS']);
        $company->fbr_registration_no = '3120180085013';

        $short = new Invoice(['invoice_number' => 'D0001', 'internal_invoice_number' => 'D0001']);
        $older = new Invoice(['invoice_number' => 'D001', 'internal_invoice_number' => 'D001']);

        $this->assertSame('3120180085013DI00001', $method->invoke($service, $company, $short));
        $this->assertSame('3120180085013DI00001', $method->invoke($service, $company, $older));
    }

    /** Another company's D0001 is irrelevant — numbering is per company. */
    public function test_numbering_is_per_company(): void
    {
        $a = $this->company();
        $b = $this->company();

        $this->assertSame('D0001', InvoiceNumberingService::generateNextNumber($a->id));
        $this->assertSame('D0001', InvoiceNumberingService::generateNextNumber($b->id));
    }
}
