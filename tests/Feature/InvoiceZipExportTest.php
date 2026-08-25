<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceZipExport;
use App\Services\InvoiceZipBuilderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Background ZIP export of invoice PDFs.
 *
 * The point of this feature is a shop being able to pull EVERY draft it has
 * ever imported into one archive to check them, so the invariants worth
 * locking are the ones that would silently hand back an incomplete or
 * misleading archive:
 *   - the scope filter really selects drafts / completed / everything,
 *   - two invoices sharing a visible number both survive (no overwrite),
 *   - the build resumes across chunks and only reports 100% when finished,
 *   - a manifest always ships with the PDFs,
 *   - expired archives are actually deleted from disk.
 */
class InvoiceZipExportTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('fbr_registration_no')->nullable();
            $t->string('address')->nullable();
            $t->string('province')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('internal_invoice_number')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('buyer_name')->nullable();
            $t->string('buyer_ntn')->nullable();
            $t->string('buyer_address', 500)->nullable();
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('total_value_excluding_st', 15, 2)->default(0);
            $t->decimal('total_sales_tax', 15, 2)->default(0);
            $t->decimal('wht_rate', 8, 2)->default(0);
            $t->decimal('wht_amount', 15, 2)->default(0);
            $t->decimal('net_receivable', 15, 2)->default(0);
            $t->string('status')->default('draft');
            $t->string('fbr_status')->nullable();
            $t->string('document_type')->nullable();
            $t->date('invoice_date')->nullable();
            $t->string('share_uuid')->nullable();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('address')->nullable();
            $t->string('city', 100)->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id');
            $t->string('hs_code')->nullable();
            $t->string('description', 500)->nullable();
            $t->decimal('quantity', 15, 4)->default(0);
            $t->decimal('price', 15, 2)->default(0);
            $t->decimal('tax', 15, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('invoice_zip_exports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->json('filters')->nullable();
            $t->string('scope_label')->nullable();
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('total_invoices')->default(0);
            $t->unsignedInteger('processed_invoices')->default(0);
            $t->unsignedInteger('failed_invoices')->default(0);
            $t->unsignedTinyInteger('progress')->default(0);
            $t->unsignedBigInteger('max_invoice_id')->default(0);
            $t->unsignedBigInteger('cursor_id')->default(0);
            $t->json('failed_ids')->nullable();
            $t->boolean('size_capped')->default(false);
            $t->string('file_path')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->string('lock_token', 64)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        $this->company = Company::create([
            'name' => 'ZIP Test Traders',
            'fbr_registration_no' => '1234567',
            'address' => '1 Test Road, Lahore',
            'province' => 'Punjab',
        ]);
    }

    private function makeInvoice(string $status, ?string $internal, string $date = '2026-08-10'): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'internal_invoice_number' => $internal,
            'invoice_number' => $internal,
            'buyer_name' => 'Buyer ' . ($internal ?? 'x'),
            'buyer_address' => 'Somewhere',
            'status' => $status,
            'document_type' => 'Sale Invoice',
            'invoice_date' => $date,
            'total_value_excluding_st' => 1000,
            'total_sales_tax' => 180,
            'total_amount' => 1180,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'hs_code' => '15179090',
            'description' => 'Cooking Oil',
            'quantity' => 10,
            'price' => 100,
            'tax' => 180,
        ]);

        return $invoice;
    }

    /** Drive the chunk pipeline the way the job and the poller both do. */
    private function build(InvoiceZipExport $export): InvoiceZipExport
    {
        for ($i = 0; $i < 50; $i++) {
            if (InvoiceZipBuilderService::processNextChunk($export) === 'done') {
                break;
            }
            $export->refresh();
        }

        return $export->fresh();
    }

    private function entries(InvoiceZipExport $export): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue(
            $zip->open(InvoiceZipBuilderService::absolutePath($export)) === true,
            'ZIP archive could not be opened'
        );

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    public function test_draft_scope_packs_only_drafts(): void
    {
        $this->makeInvoice('draft', 'DI00001');
        $this->makeInvoice('draft', 'DI00002');
        $this->makeInvoice('locked', 'DI00003');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));

        $this->assertSame('ready', $export->status);
        $this->assertSame(2, $export->total_invoices);
        $this->assertSame(2, $export->processed_invoices);
        $this->assertSame(100, (int) $export->progress);

        $names = $this->entries($export);
        $this->assertContains('drafts/DI00001.pdf', $names);
        $this->assertContains('drafts/DI00002.pdf', $names);
        $this->assertContains('_manifest.csv', $names);
        $this->assertNotContains('DI00003.pdf', $names);
    }

    public function test_completed_scope_skips_drafts(): void
    {
        $this->makeInvoice('draft', 'DI00001');
        $this->makeInvoice('locked', 'DI00002');
        $this->makeInvoice('pending_verification', 'DI00003');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_COMPLETED]
        ));

        $this->assertSame('ready', $export->status);
        $this->assertSame(2, $export->total_invoices);

        $names = $this->entries($export);
        $this->assertContains('DI00002.pdf', $names);
        $this->assertContains('DI00003.pdf', $names);
        $this->assertNotContains('drafts/DI00001.pdf', $names);
    }

    public function test_all_scope_packs_everything(): void
    {
        $this->makeInvoice('draft', 'DI00001');
        $this->makeInvoice('locked', 'DI00002');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_ALL]
        ));

        $this->assertSame(2, $export->total_invoices);
        $names = $this->entries($export);
        $this->assertContains('drafts/DI00001.pdf', $names);
        $this->assertContains('DI00002.pdf', $names);
    }

    public function test_two_invoices_with_the_same_number_both_survive(): void
    {
        // A re-issued or double-imported draft can carry a number that is
        // already in the archive; an overwrite would quietly drop one of them.
        $this->makeInvoice('draft', 'DI00001');
        $b = $this->makeInvoice('draft', 'DI00001');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));

        $names = array_values(array_filter($this->entries($export), fn ($n) => str_ends_with($n, '.pdf')));
        $this->assertCount(2, $names, 'both same-numbered invoices must be in the archive');
        $this->assertContains('drafts/DI00001.pdf', $names);
        $this->assertContains('drafts/DI00001__' . $b->id . '.pdf', $names);
    }

    public function test_date_filters_narrow_the_archive(): void
    {
        $this->makeInvoice('draft', 'DI00001', '2026-07-15');
        $this->makeInvoice('draft', 'DI00002', '2026-08-15');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT, 'from' => '2026-08-01', 'to' => '2026-08-31']
        ));

        $this->assertSame(1, $export->total_invoices);
        $this->assertContains('drafts/DI00002.pdf', $this->entries($export));
    }

    public function test_build_resumes_across_chunks_and_only_finishes_at_the_end(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->makeInvoice('draft', 'DI0000' . $i);
        }

        $export = InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        );

        // First call only sizes the job up; it must not claim to be finished.
        $this->assertSame('continue', InvoiceZipBuilderService::processNextChunk($export));
        $export->refresh();
        $this->assertSame('processing', $export->status);
        $this->assertSame(3, $export->total_invoices);
        $this->assertLessThan(100, (int) $export->progress);

        $export = $this->build($export);
        $this->assertSame('ready', $export->status);
        $this->assertSame(100, (int) $export->progress);
        $this->assertNotNull($export->completed_at);
        $this->assertGreaterThan(0, (int) $export->file_size);
    }

    public function test_an_empty_result_fails_loudly_instead_of_shipping_an_empty_zip(): void
    {
        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));

        $this->assertSame('failed', $export->status);
        $this->assertStringContainsString('No invoices matched', $export->error_message);
    }

    public function test_starting_a_new_export_clears_the_previous_one(): void
    {
        $this->makeInvoice('draft', 'DI00001');

        $first = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));
        $firstPath = InvoiceZipBuilderService::absolutePath($first);
        $this->assertFileExists($firstPath);

        InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        );

        $this->assertNull(InvoiceZipExport::find($first->id));
        $this->assertFileDoesNotExist($firstPath);
    }

    public function test_expired_exports_are_purged_from_disk(): void
    {
        $this->makeInvoice('draft', 'DI00001');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));
        $path = InvoiceZipBuilderService::absolutePath($export);
        $this->assertFileExists($path);

        InvoiceZipExport::where('id', $export->id)->update([
            'created_at' => now()->subHours(InvoiceZipBuilderService::RETENTION_HOURS + 1),
        ]);

        $this->assertSame(1, InvoiceZipBuilderService::purgeExpired());
        $this->assertNull(InvoiceZipExport::find($export->id));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_claimed_export_reports_busy_instead_of_double_building(): void
    {
        $this->makeInvoice('draft', 'DI00001');

        $export = InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        );

        $token = InvoiceZipBuilderService::claim($export);
        $this->assertNotNull($token);
        $this->assertSame('busy', InvoiceZipBuilderService::processNextChunk($export));

        InvoiceZipBuilderService::release($export, $token);
        $this->assertSame('continue', InvoiceZipBuilderService::processNextChunk($export->fresh()));
    }

    /**
     * MySQL counts the rows an UPDATE actually CHANGED; SQLite counts the rows
     * it MATCHED. The builder used to infer "do I still hold the lease?" from
     * that count, so on MySQL a renewal issued inside the same second as the
     * claim — exactly what finalize() does — looked like a lost lease and the
     * build bailed out one step short of 'ready'. Every real export froze at
     * 95% while this suite, on SQLite, stayed green.
     *
     * Ownership is a SELECT now, so a write that changes nothing must still
     * report success. Run this class against MySQL after touching the locking
     * code; SQLite alone cannot see the failure.
     */
    public function test_a_lease_survives_a_renewal_that_changes_nothing(): void
    {
        $this->makeInvoice('draft', 'DI00001');

        $export = InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        );

        $token = InvoiceZipBuilderService::claim($export);
        $this->assertNotNull($token);

        // Same second, re-stamping the value the claim just wrote.
        $this->assertTrue($this->callProtected('renewLease', $export, $token));
        $this->assertTrue($this->callProtected('renewLease', $export, $token));

        // A state write whose values the row already holds is still our write.
        $fresh = $export->fresh();
        $this->assertTrue($this->callProtected('writeState', $export, $token, [
            'status' => $fresh->status,
            'progress' => (int) $fresh->progress,
        ]));

        // A lease that genuinely moved on must still be refused — including
        // when the write it is attempting would change nothing, which is the
        // case a no-op-means-success shortcut would wave straight through into
        // a second worker scribbling on the same archive.
        InvoiceZipBuilderService::release($export, $token);
        $this->assertNotNull(InvoiceZipBuilderService::claim($export));

        $current = $export->fresh();
        $this->assertFalse($this->callProtected('renewLease', $export, $token));
        $this->assertFalse($this->callProtected('writeState', $export, $token, [
            'status' => $current->status,
            'progress' => (int) $current->progress,
        ]));
        $this->assertFalse($this->callProtected('writeState', $export, $token, ['progress' => 42]));
        $this->assertSame((int) $current->progress, (int) $export->fresh()->progress);
    }

    /** The whole build, driven end to end without letting the clock advance. */
    public function test_a_build_finishing_inside_one_second_still_reports_ready(): void
    {
        $this->makeInvoice('draft', 'DI00001');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));

        $this->assertSame('ready', $export->status);
        $this->assertSame(100, (int) $export->progress);
        $this->assertNotNull($export->completed_at);
        $this->assertFileExists(InvoiceZipBuilderService::absolutePath($export));
    }

    /**
     * These jobs ride their own queue because a host's default queue worker can
     * be a PHP build WITHOUT the zip extension — which is exactly what killed
     * every export on the live server: the site's own PHP had zip, the cron's
     * did not, so the build died the moment a worker picked it up. Losing this
     * assignment in a refactor would strand exports again, silently.
     */
    public function test_archive_jobs_are_queued_where_a_zip_capable_worker_listens(): void
    {
        $this->assertSame('zip', (new \App\Jobs\BuildInvoiceZipJob(1))->queue);
        $this->assertSame('zip', (new \App\Jobs\BuildAuditPackJob(1))->queue);
    }

    /**
     * An invoice PDF must not carry entire embedded font files. It did, and it
     * turned a 5,961-invoice archive into 4.9 GB that no shop could download.
     */
    public function test_archived_invoice_pdfs_do_not_embed_whole_fonts(): void
    {
        $this->makeInvoice('draft', 'DI00001');

        $export = $this->build(InvoiceZipBuilderService::start(
            $this->company->id,
            null,
            ['scope' => InvoiceZipBuilderService::SCOPE_DRAFT]
        ));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(InvoiceZipBuilderService::absolutePath($export)) === true);

        $largestPdf = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (str_ends_with(strtolower($entry['name']), '.pdf')) {
                $largestPdf = max($largestPdf, (int) $entry['size']);
            }
        }
        $zip->close();

        $this->assertGreaterThan(0, $largestPdf, 'no PDF found in the archive');
        $this->assertLessThan(200 * 1024, $largestPdf, 'invoice PDF looks like it is embedding whole fonts again');
    }

    private function callProtected(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(InvoiceZipBuilderService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(null, ...$args);
    }
}
