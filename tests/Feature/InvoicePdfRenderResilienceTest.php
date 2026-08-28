<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\InvoicePdfCacheService;
use App\Support\QrImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards for the failure that filled the live server's disk (Aug 2026).
 *
 * The background warmer renders every invoice PDF ahead of time. The PHP build
 * running cron had no GD extension while the one serving the website did, so
 * DomPDF threw on every single render. Three separate weaknesses turned one
 * missing extension into a disk emergency:
 *
 *   1. The QR helper "rescued" the missing GD by returning an SVG. DomPDF
 *      cannot draw an SVG without GD either, so the rescue only moved the
 *      failure from a helper that handles it into a renderer that does not.
 *   2. DomPDF copies each embedded image to a temp file and deletes them only
 *      when a render completes. Every failed render leaked one, forever.
 *   3. Nothing remembered a failure, so the same invoices were retried every
 *      five minutes — 522,000 orphaned files and 7.9 GB later.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/InvoicePdfRenderResilienceTest.php --testdox
 */
class InvoicePdfRenderResilienceTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    private function makeTempFile(string $name, int $ageSeconds): string
    {
        $path = sys_get_temp_dir() . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, time() - $ageSeconds);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_qr_is_a_png_and_never_an_svg(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('This assertion is about which format we emit WITH GD present.');
        }

        $uri = QrImage::dataUri('3120180085013DI8I449K417830', 8);

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertStringNotContainsStringIgnoringCase('svg', $uri);
    }

    public function test_an_unrenderable_qr_yields_nothing_rather_than_an_svg(): void
    {
        // The whole point of the change: whatever goes wrong inside the QR
        // library, the caller gets an empty string it already knows how to
        // handle — never a format that will kill the document downstream.
        // More data than the largest QR version can hold takes the same
        // failure path a missing GD extension would.
        $uri = QrImage::dataUri(str_repeat('A', 8000));

        $this->assertSame('', $uri, 'A QR we cannot draw must be absent, not a format DomPDF will die on.');
    }

    public function test_a_failed_invoice_is_left_alone_for_a_while(): void
    {
        Storage::fake('local');

        $invoice = new Invoice();
        $invoice->id = 4242;
        $invoice->company_id = 7;

        $this->assertFalse(
            InvoicePdfCacheService::recentlyFailed(7, 4242),
            'An invoice nobody has failed on must be fair game for the warmer.'
        );

        InvoicePdfCacheService::markFailed($invoice);

        $this->assertTrue(
            InvoicePdfCacheService::recentlyFailed(7, 4242),
            'Straight after a failure the warmer must skip it instead of retrying every five minutes.'
        );
    }

    public function test_the_cooldown_expires_so_a_fixed_invoice_comes_back(): void
    {
        Storage::fake('local');

        $invoice = new Invoice();
        $invoice->id = 99;
        $invoice->company_id = 3;

        InvoicePdfCacheService::markFailed($invoice);

        $marker = InvoicePdfCacheService::failMarkerPath(3, 99);
        touch($marker, time() - 7200);

        $this->assertTrue(InvoicePdfCacheService::recentlyFailed(3, 99, 21600));
        $this->assertFalse(
            InvoicePdfCacheService::recentlyFailed(3, 99, 3600),
            'Once the cooldown is past, a deploy that fixed the cause must get a fresh attempt.'
        );
    }

    public function test_prune_removes_orphaned_dompdf_files(): void
    {
        $old = $this->makeTempFile('ca_dompdf_img_pruneold' . getmypid(), 7200);
        $background = $this->makeTempFile('bg_dompdf_img_pruneold' . getmypid(), 7200);

        $this->artisan('pdf:prune-temp', ['--hours' => 1])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($background);
    }

    public function test_prune_never_touches_a_file_a_live_render_may_still_be_using(): void
    {
        // A render takes seconds. Anything recent belongs to a render that is
        // still running, and deleting it would break a document being built
        // for someone right now.
        $fresh = $this->makeTempFile('ca_dompdf_img_prunefresh' . getmypid(), 60);
        $unrelated = $this->makeTempFile('someone-elses-file-' . getmypid() . '.tmp', 999999);

        $this->artisan('pdf:prune-temp', ['--hours' => 1])->assertSuccessful();

        $this->assertFileExists($fresh);
        $this->assertFileExists($unrelated, 'The sweep must only ever match DomPDF\'s own prefixes.');
    }
}
