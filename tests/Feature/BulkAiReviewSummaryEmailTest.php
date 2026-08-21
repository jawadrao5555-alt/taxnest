<?php

namespace Tests\Feature;

use App\Http\Controllers\AiInvoiceReaderController;
use App\Mail\BulkAiReviewSummaryMail;
use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Models\User;
use App\Services\BulkAiImageImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1343: email the Bulk AI review summary straight to another reviewer.
 *
 * Locks the hand-off rules:
 *   - one PDF-carrying mail per recipient, and every recipient is recorded
 *     (sent AND failed) with who sent it, so an owner can audit the hand-off;
 *   - the mail says in plain words that the private source photos are not
 *     attached — and never leaks a storage path, uuid or content hash;
 *   - company scoped: another company's batch is a 404 and mails nothing;
 *   - abuse capped: bad addresses, too many recipients in one send, and the
 *     rolling 24h company cap are all refused before any mail goes out.
 */
class BulkAiReviewSummaryEmailTest extends TestCase
{
    private Company $company;
    private BulkAiImageBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('ntn')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('internal_invoice_number')->nullable();
            $t->string('invoice_number')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('status')->default('draft');
            $t->timestamps();
        });
        Schema::create('bulk_ai_image_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('batch_uuid');
            $t->string('status')->default('completed');
            $t->unsignedInteger('total_images')->default(0);
            $t->unsignedInteger('reserved_credits')->default(0);
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('retention_until')->nullable();
            $t->string('annexure_status')->default('none');
            $t->string('annexure_filename')->nullable();
            $t->timestamps();
        });
        Schema::create('bulk_ai_image_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('company_id');
            $t->string('source_uuid');
            $t->unsignedInteger('position');
            $t->string('original_filename');
            $t->string('content_hash')->nullable();
            $t->string('storage_path')->nullable();
            $t->string('status')->default('not_started');
            $t->string('reservation_status')->default('reserved');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->longText('warnings_json')->nullable();
            $t->longText('details_json')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('source_deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('bulk_ai_report_shares', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('sent_by')->nullable();
            $t->string('recipient');
            $t->string('status')->default('sent');
            $t->text('error')->nullable();
            $t->timestamps();
        });

        $this->company = Company::create(['name' => 'Distributor Traders', 'ntn' => '1234567']);
        app()->instance('currentCompanyId', $this->company->id);

        $this->batch = BulkAiImageBatch::create([
            'company_id' => $this->company->id,
            'user_id' => 7,
            'batch_uuid' => 'batch-uuid-1',
            'status' => 'completed',
            'total_images' => 2,
            'finished_at' => now(),
        ]);

        $draftId = DB::table('invoices')->insertGetId([
            'company_id' => $this->company->id,
            'internal_invoice_number' => 'DI-00021',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->item(1, 'invoice-01.jpg', 'ready', $draftId);
        $this->item(2, 'invoice-02.jpg', 'needs_review', null, ['HS code missing on line 1.']);

        $actor = new User();
        $actor->id = 7;
        $actor->name = 'Ayesha Khan';
        Auth::setUser($actor);
    }

    private function item(int $position, string $filename, string $status, ?int $invoiceId = null, array $warnings = []): void
    {
        DB::table('bulk_ai_image_items')->insert([
            'batch_id' => $this->batch->id,
            'company_id' => $this->company->id,
            'source_uuid' => 'source-uuid-' . $position,
            'position' => $position,
            'original_filename' => $filename,
            'content_hash' => 'contenthash' . $position,
            'storage_path' => 'private/ai-bulk/' . $this->company->id . '/' . $this->batch->id . '/source-uuid-' . $position . '/source.jpg',
            'status' => $status,
            'reservation_status' => 'consumed',
            'invoice_id' => $invoiceId,
            'warnings_json' => $warnings ? json_encode($warnings) : null,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function send($recipients, ?int $batchId = null)
    {
        $batchId = $batchId ?? $this->batch->id;
        $request = Request::create(
            '/invoices/ai-reader/bulk-images/' . $batchId . '/report/email',
            'POST',
            ['recipients' => $recipients]
        );

        return (new AiInvoiceReaderController())->bulkReportEmail($request, $batchId, app(BulkAiImageImportService::class));
    }

    public function test_summary_is_mailed_to_every_recipient_and_each_hand_off_is_recorded(): void
    {
        Mail::fake();

        $response = $this->send(' Accountant@Example.com , reviewer@example.com, accountant@example.com ');

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['ok']);
        // The duplicate address is collapsed and everything is lower-cased.
        $this->assertSame(['accountant@example.com', 'reviewer@example.com'], $payload['sent']);
        $this->assertSame([], $payload['failed']);

        Mail::assertSent(BulkAiReviewSummaryMail::class, 2);
        foreach (['accountant@example.com', 'reviewer@example.com'] as $email) {
            Mail::assertSent(BulkAiReviewSummaryMail::class, function (BulkAiReviewSummaryMail $mail) use ($email) {
                return $mail->hasTo($email)
                    && str_starts_with($mail->pdfBytes, '%PDF')
                    && str_contains($mail->pdfFilename, 'bulk-ai-review-batch-' . $this->batch->id)
                    && $mail->senderName === 'Ayesha Khan';
            });
        }

        $rows = DB::table('bulk_ai_report_shares')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('accountant@example.com', $rows[0]->recipient);
        $this->assertSame('sent', $rows[0]->status);
        $this->assertSame('Ayesha Khan', $rows[0]->sent_by);
        $this->assertEquals(7, $rows[0]->user_id);
        $this->assertEquals($this->company->id, $rows[0]->company_id);
        $this->assertEquals($this->batch->id, $rows[0]->batch_id);

        // The batch page gets the hand-off history straight back.
        $this->assertSame('reviewer@example.com', $payload['shares'][0]['recipient']);
        $this->assertCount(2, $payload['shares']);
        $this->assertSame(
            BulkAiImageImportService::REPORT_SHARE_DAILY_LIMIT - 2,
            $payload['allowance_left']
        );
    }

    public function test_mail_says_the_private_photos_are_not_attached_and_never_leaks_them(): void
    {
        $service = app(BulkAiImageImportService::class);
        $mail = new BulkAiReviewSummaryMail(
            $this->company,
            $service->reviewReport($this->batch),
            '%PDF-1.4 fake',
            $service->reviewReportFilename($this->batch, 'pdf'),
            'Ayesha Khan'
        );
        $mail->build();
        $html = $mail->render();
        $text = view('emails.bulk-ai-review-summary-text', [
            'company' => $this->company,
            'report' => $service->reviewReport($this->batch),
            'senderName' => 'Ayesha Khan',
        ])->render();

        $this->assertStringContainsString('Batch #' . $this->batch->id, (string) $mail->subject);
        $this->assertStringContainsString('Distributor Traders', (string) $mail->subject);

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Distributor Traders', $body);
            $this->assertStringContainsStringIgnoringCase('not attached', $body);
            $this->assertStringNotContainsString('private/ai-bulk', $body);
            $this->assertStringNotContainsString('source-uuid-', $body);
            $this->assertStringNotContainsString('contenthash', $body);
            $this->assertStringNotContainsString('batch-uuid-1', $body);
            $this->assertStringNotContainsString('invoice-01.jpg', $body);
        }
        $this->assertStringContainsString('Ayesha Khan', $html);
    }

    public function test_another_companys_batch_cannot_be_emailed(): void
    {
        Mail::fake();

        $other = Company::create(['name' => 'Rival Traders']);
        $otherBatch = BulkAiImageBatch::create([
            'company_id' => $other->id,
            'batch_uuid' => 'batch-uuid-2',
            'status' => 'completed',
            'total_images' => 1,
        ]);

        $response = $this->send('accountant@example.com', $otherBatch->id);

        $this->assertSame(404, $response->getStatusCode());
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('bulk_ai_report_shares')->count());
    }

    public function test_bad_or_too_many_recipients_are_refused_before_any_mail_goes_out(): void
    {
        Mail::fake();

        $this->assertSame(422, $this->send('   ')->getStatusCode());
        $this->assertSame(422, $this->send('accountant@example.com, not-an-email')->getStatusCode());
        $this->assertSame(422, $this->send(
            collect(range(1, BulkAiImageImportService::REPORT_SHARE_MAX_RECIPIENTS + 1))
                ->map(fn ($n) => 'reviewer' . $n . '@example.com')->implode(', ')
        )->getStatusCode());

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('bulk_ai_report_shares')->count());
    }

    public function test_a_batch_with_nothing_processed_yet_cannot_be_emailed(): void
    {
        Mail::fake();

        $empty = BulkAiImageBatch::create([
            'company_id' => $this->company->id,
            'batch_uuid' => 'batch-uuid-3',
            'status' => 'uploading',
            'total_images' => 3,
        ]);

        $this->assertSame(422, $this->send('accountant@example.com', $empty->id)->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_rolling_24h_company_cap_stops_abuse_but_older_sends_do_not_count(): void
    {
        Mail::fake();

        $this->seedShares(BulkAiImageImportService::REPORT_SHARE_DAILY_LIMIT, now()->subHours(3));
        $response = $this->send('accountant@example.com');

        $this->assertSame(429, $response->getStatusCode());
        Mail::assertNothingSent();

        // Yesterday's hand-offs must not keep a shop blocked forever.
        DB::table('bulk_ai_report_shares')->update(['created_at' => now()->subDays(2)]);
        $this->assertSame(200, $this->send('accountant@example.com')->getStatusCode());
        Mail::assertSent(BulkAiReviewSummaryMail::class, 1);
    }

    /**
     * The cap must be a real company-wide limit, not a read-then-send check:
     * a second staff session that starts while the first send is still in
     * flight has to see the allowance already claimed. Re-entering the
     * endpoint from inside the first request's MessageSending event reproduces
     * exactly that interleaving on a single PHP worker.
     */
    public function test_an_overlapping_second_send_cannot_spend_the_same_allowance(): void
    {
        config(['mail.default' => 'array']);
        $limit = BulkAiImageImportService::REPORT_SHARE_DAILY_LIMIT;
        $this->seedShares($limit - 2, now()->subHours(2)); // exactly 2 left

        $overlapping = null;
        Event::listen(MessageSending::class, function () use (&$overlapping) {
            if ($overlapping !== null) {
                return; // only interleave once
            }
            $overlapping = $this->send('second-session@example.com');
        });

        $response = $this->send('first@example.com, second@example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($overlapping, 'the overlapping request never ran');
        $this->assertSame(429, $overlapping->getStatusCode());

        // The window holds exactly the cap — the overlapping send reserved nothing.
        $this->assertSame($limit, DB::table('bulk_ai_report_shares')
            ->where('created_at', '>=', now()->subDay())->count());
        $this->assertSame(0, DB::table('bulk_ai_report_shares')
            ->where('recipient', 'second-session@example.com')->count());
        $this->assertCount(2, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_recipients_are_reserved_before_any_mail_is_rendered_or_sent(): void
    {
        config(['mail.default' => 'array']);

        $atSendTime = null;
        Event::listen(MessageSending::class, function () use (&$atSendTime) {
            $atSendTime = $atSendTime ?? DB::table('bulk_ai_report_shares')
                ->pluck('status', 'recipient')->all();
        });

        $this->send('one@example.com, two@example.com');

        // Both rows already existed (queued) when the FIRST mail went out.
        $this->assertSame(
            ['one@example.com' => BulkAiImageImportService::REPORT_SHARE_QUEUED, 'two@example.com' => BulkAiImageImportService::REPORT_SHARE_QUEUED],
            $atSendTime
        );
        // …and both are settled once the send finishes.
        $this->assertSame(
            ['sent', 'sent'],
            DB::table('bulk_ai_report_shares')->orderBy('id')->pluck('status')->all()
        );
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP 535 authentication failed'));

        $response = $this->send('accountant@example.com');

        $this->assertSame(500, $response->getStatusCode());
        $row = DB::table('bulk_ai_report_shares')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('accountant@example.com', $row->recipient);
        $this->assertStringContainsString('SMTP 535', (string) $row->error);
    }

    public function test_email_route_is_registered_company_scoped_and_throttled(): void
    {
        $route = Route::getRoutes()->getByName('invoices.ai-reader.bulk.report.email');

        $this->assertNotNull($route);
        $this->assertSame('invoices/ai-reader/bulk-images/{batchId}/report/email', $route->uri());
        $this->assertContains('POST', $route->methods());
        foreach (['auth', 'company', 'company.approval', 'throttle:5,1'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }

    private function seedShares(int $count, \Illuminate\Support\Carbon $at): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'batch_id' => $this->batch->id,
                'company_id' => $this->company->id,
                'user_id' => 7,
                'sent_by' => 'Ayesha Khan',
                'recipient' => 'old' . $i . '@example.com',
                'status' => 'sent',
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }
        DB::table('bulk_ai_report_shares')->insert($rows);
    }
}
