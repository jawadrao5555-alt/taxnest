<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Jobs\BulkSubmitInvoiceJob;
use App\Jobs\IntelligenceProcessingJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceBulkSubmission;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1245 — Bulk submit of draft DI invoices to FBR.
 *
 *  1. Batch of drafts → every invoice submitted, batch record shows success.
 *  2. One bad invoice (already has an FBR number) → skipped, others still go.
 *  3. Expired subscription → 422 up-front, invoices stay draft.
 *  4. Double-click: second request after batch completes finds nothing left
 *     to submit (no double submission); while running it re-attaches (409).
 *  5. The run is durable: it is tracked on a DB row, not in the cache and not
 *     in the page, so it survives the browser closing and the shop can come
 *     back to the finished summary. Stopping and stalling are covered too.
 */
class InvoiceBulkSubmitTest extends TestCase
{
    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->string('product_type')->nullable();
            $table->string('ntn')->nullable();
            $table->string('fbr_environment')->nullable();
            $table->integer('compliance_score')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->string('override_reason')->nullable();
            $table->integer('override_invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('internal_invoice_number')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->timestamp('fbr_submission_date')->nullable();
            $table->string('status')->default('draft');
            $table->string('fbr_status')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_ntn')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('submission_mode')->nullable();
            $table->string('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->boolean('is_fbr_processing')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->string('fbr_invoice_id')->nullable();
            $table->text('qr_data')->nullable();
            $table->string('integrity_hash')->nullable();
            $table->string('share_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('hs_code')->nullable();
            $table->string('schedule_type')->nullable();
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->string('sro_schedule_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->decimal('mrp', 15, 2)->nullable();
            $table->string('description')->nullable();
            $table->decimal('quantity', 15, 2)->default(1);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('changes_json')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('record_hash')->nullable(); $table->string('sha256_hash')->nullable(); $table->string('previous_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->text('rule_flags')->nullable();
            $table->text('anomaly_flags')->nullable();
            $table->integer('final_score')->nullable();
            $table->string('risk_level')->nullable();
            $table->boolean('fbr_validated')->default(false);
            $table->timestamps();
        });

        Schema::create('invoice_anomalies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('anomaly_type')->nullable();
            $table->string('severity')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_bulk_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('target_status', 20)->default('draft');
            $table->string('scope', 20)->default('all');
            $table->string('state', 20)->default('queued');
            $table->json('invoice_ids')->nullable();
            $table->unsignedBigInteger('max_invoice_id')->default(0);
            $table->unsignedBigInteger('cursor_id')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('dispatched')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->json('failures')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
        $this->company = Company::create([
            'name' => 'Bulk Test Co',
            'status' => 'approved',
            'company_status' => 'active',
            'product_type' => 'di',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'bulkadmin@test.pk',
            'password' => Hash::make('secret1234'),
            'company_id' => $this->company->id,
            'role' => 'company_admin',
        ]);
        $this->user = $this->user->fresh();
    }

    protected function makeDraft(array $attrs = []): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'invoice_number' => 'INV-' . uniqid(),
            'status' => 'draft',
            'buyer_name' => 'Buyer',
            'total_amount' => 118,
        ], $attrs));

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'hs_code' => '0101.2100',
            'schedule_type' => 'standard',
            'tax_rate' => 18,
            'description' => 'Test item',
            'quantity' => 1,
            'price' => 100,
            'tax' => 18,
        ]);

        return $invoice;
    }

    /** Fake the shared FBR sync path so no real FBR call happens. */
    protected function fakeFbrSuccess(): void
    {
        $mock = $this->partialMock(InvoiceController::class);
        $mock->shouldReceive('submitToFbrSync')->andReturnUsing(function (Invoice $invoice) {
            $invoice->status = 'locked';
            $invoice->fbr_status = 'production';
            $invoice->is_fbr_processing = false;
            $invoice->fbr_invoice_number = 'FBR-' . $invoice->id;
            $invoice->save();
            return ['status' => 'success', 'fbr_invoice_number' => 'FBR-' . $invoice->id, 'execution_ms' => 5];
        });
    }

    public function test_batch_of_drafts_all_submitted(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $a = $this->makeDraft();
        $b = $this->makeDraft();
        $c = $this->makeDraft();

        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', [
            'invoice_ids' => [$a->id, $b->id, $c->id],
        ]);

        $res->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('total', 3);
        $batch = InvoiceBulkSubmission::findOrFail((int) $res->json('batch_key'));
        $this->assertSame('completed', $batch->state);
        $this->assertSame(3, (int) $batch->success);
        $this->assertSame(0, (int) $batch->failed);

        foreach ([$a, $b, $c] as $inv) {
            $fresh = $inv->fresh();
            $this->assertSame('locked', $fresh->status);
            $this->assertSame('bulk', $fresh->submission_mode);
            $this->assertNotEmpty($fresh->fbr_invoice_number);
            $this->assertFalse((bool) $fresh->is_fbr_processing);
        }

        // Nothing is left holding the company back from starting another run.
        $this->assertNull(InvoiceBulkSubmission::activeFor($this->company->id));
    }

    public function test_one_ineligible_invoice_does_not_block_the_rest(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $good = $this->makeDraft();
        $alreadySubmitted = $this->makeDraft(['status' => 'locked', 'fbr_invoice_number' => 'FBR-OLD']);
        $good2 = $this->makeDraft();

        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', [
            'invoice_ids' => [$good->id, $alreadySubmitted->id, $good2->id],
        ]);

        // Controller pre-filters non-drafts, so only 2 are queued.
        $res->assertOk()->assertJsonPath('total', 2);
        $batch = InvoiceBulkSubmission::findOrFail((int) $res->json('batch_key'));
        $this->assertSame('completed', $batch->state);
        $this->assertSame(2, (int) $batch->success);
        $this->assertSame('FBR-OLD', $alreadySubmitted->fresh()->fbr_invoice_number);
        $this->assertSame('locked', $good->fresh()->status);
        $this->assertSame('locked', $good2->fresh()->status);
    }

    public function test_expired_subscription_blocks_bulk_submit(): void
    {
        \App\Models\Subscription::create([
            'company_id' => $this->company->id,
            'active' => true,
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $a = $this->makeDraft();

        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', [
            'invoice_ids' => [$a->id],
        ]);

        if ($res->status() === 302) { fwrite(STDERR, "REDIRECT TO: ".$res->headers->get("Location")."
"); } $res->assertStatus(422);
        $this->assertSame('draft', $a->fresh()->status);
    }

    public function test_double_submit_does_not_resubmit(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $a = $this->makeDraft();

        $first = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', [
            'invoice_ids' => [$a->id],
        ]);
        $first->assertOk();
        $this->assertSame('locked', $a->fresh()->status);

        // Second click after completion: nothing submittable remains.
        $second = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', [
            'invoice_ids' => [$a->id],
        ]);
        $second->assertStatus(422);
    }

    public function test_second_request_while_running_reattaches(): void
    {
        $a = $this->makeDraft();
        $b = $this->makeDraft();

        // A run already in flight for this company.
        $running = InvoiceBulkSubmission::create([
            'company_id' => $this->company->id,
            'state' => 'running',
            'total' => 10,
            'dispatched' => 10,
            'done' => 4,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', [
            'invoice_ids' => [$a->id, $b->id],
        ]);

        $res->assertStatus(409)->assertJsonPath('batch_key', (string) $running->id);
        $res->assertJsonPath('batch.done', 4);
        $this->assertSame('draft', $a->fresh()->status);
        $this->assertSame(1, InvoiceBulkSubmission::count(), 'a second click must never start a parallel run');
    }

    public function test_status_endpoint_scoped_to_company(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $a = $this->makeDraft();
        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', ['invoice_ids' => [$a->id]]);
        $batchKey = $res->json('batch_key');

        $this->actingAs($this->user)->getJson('/invoices/bulk-submit-status?batch_key=' . $batchKey)
            ->assertOk()->assertJsonPath('batch.finished', true);

        // Another company can't read the batch.
        $otherCompany = Company::create(['name' => 'Other', 'status' => 'approved', 'company_status' => 'active']);
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@test.pk',
            'password' => Hash::make('secret1234'),
            'company_id' => $otherCompany->id,
            'role' => 'company_admin',
        ]);
        $otherUser = $otherUser->fresh();
        $this->actingAs($otherUser)->getJson('/invoices/bulk-submit-status?batch_key=' . $batchKey)
            ->assertStatus(404);
    }

    /**
     * Counters live on the batch row and move with one atomic UPDATE each, so
     * parallel workers cannot lose an increment, and the run is closed exactly
     * once — only after dispatching is finished (state 'running').
     */
    public function test_counters_add_up_and_the_run_closes_exactly_once(): void
    {
        $batch = InvoiceBulkSubmission::create([
            'company_id' => $this->company->id,
            'state' => 'running',
            'total' => 3,
            'dispatched' => 3,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        BulkSubmitInvoiceJob::recordResult($batch->id, 101, 'success', 'ok');
        BulkSubmitInvoiceJob::recordResult($batch->id, 102, 'failed', 'FBR rejected');
        $batch->refresh();
        $this->assertSame(2, (int) $batch->done);
        $this->assertSame('running', $batch->state, 'must not finish before every invoice reported');

        BulkSubmitInvoiceJob::recordResult($batch->id, 103, 'skipped', 'not eligible');
        $batch->refresh();
        $this->assertSame(3, (int) $batch->done);
        $this->assertSame(1, (int) $batch->success);
        $this->assertSame(1, (int) $batch->failed);
        $this->assertSame(1, (int) $batch->skipped);
        $this->assertSame('completed', $batch->state);
        $this->assertNotNull($batch->completed_at);

        // Only the problems are kept, with their reason.
        $this->assertCount(2, $batch->failures);
        $this->assertSame('FBR rejected', $batch->failures[0]['message']);

        // A straggling duplicate after the run closed must not reopen it.
        $completedAt = $batch->completed_at;
        BulkSubmitInvoiceJob::recordResult($batch->id, 103, 'skipped', 'late duplicate');
        $batch->refresh();
        $this->assertSame('completed', $batch->state);
        $this->assertEquals($completedAt, $batch->completed_at);
    }

    /** While still dispatching, done == total must NOT end the run. */
    public function test_run_does_not_finish_while_still_dispatching(): void
    {
        $batch = InvoiceBulkSubmission::create([
            'company_id' => $this->company->id,
            'state' => 'dispatching',
            'total' => 1,
            'dispatched' => 1,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        BulkSubmitInvoiceJob::recordResult($batch->id, 201, 'success', 'ok');

        $this->assertSame('dispatching', $batch->fresh()->state);
    }

    /**
     * The whole point of the rewrite: the shop starts a run, closes the page,
     * and the server finishes it. On return, the status endpoint — asked with
     * no batch key at all — hands back the finished run, and Dismiss clears it.
     */
    public function test_finished_run_is_still_reported_after_the_page_was_closed(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $a = $this->makeDraft();
        $this->actingAs($this->user)->postJson('/invoices/bulk-submit', ['select_all_drafts' => true])->assertOk();
        $this->assertSame('locked', $a->fresh()->status);

        // A fresh page load knows nothing — no key, no local state.
        $res = $this->actingAs($this->user)->getJson('/invoices/bulk-submit-status');
        $res->assertOk()
            ->assertJsonPath('batch.state', 'completed')
            ->assertJsonPath('batch.finished', true)
            ->assertJsonPath('batch.success', 1);

        $this->actingAs($this->user)->postJson('/invoices/bulk-submit-ack')->assertOk();

        // Once acknowledged it stops greeting them.
        $this->actingAs($this->user)->getJson('/invoices/bulk-submit-status')->assertStatus(404);
    }

    /** Stopping leaves already-submitted invoices alone and drains the rest. */
    public function test_stopping_a_run_skips_the_remaining_invoices(): void
    {
        $batch = InvoiceBulkSubmission::create([
            'company_id' => $this->company->id,
            'state' => 'running',
            'total' => 2,
            'dispatched' => 2,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);

        $this->actingAs($this->user)->postJson('/invoices/bulk-submit-cancel')
            ->assertOk()
            ->assertJsonPath('batch.cancel_requested', true);

        $invoice = $this->makeDraft();
        (new BulkSubmitInvoiceJob($invoice->id, $batch->id, $this->user->id))->handle();

        $this->assertSame('draft', $invoice->fresh()->status, 'a stopped run must not submit anything else');
        $batch->refresh();
        $this->assertSame(1, (int) $batch->skipped);

        BulkSubmitInvoiceJob::recordResult($batch->id, 999, 'skipped', 'Run stopped before this invoice was submitted.');
        $this->assertSame('cancelled', $batch->fresh()->state);
    }

    /**
     * A run whose worker died must not lock the shop out forever: the next
     * click closes the dead run as stalled and starts a fresh one.
     */
    public function test_a_stalled_run_does_not_block_a_new_one(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $dead = InvoiceBulkSubmission::create([
            'company_id' => $this->company->id,
            'state' => 'running',
            'total' => 500,
            'dispatched' => 500,
            'done' => 12,
            'started_at' => now()->subHours(3),
            'last_progress_at' => now()->subHours(2),
        ]);

        $a = $this->makeDraft();
        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', ['select_all_drafts' => true]);

        $res->assertOk();
        $this->assertSame('stalled', $dead->fresh()->state);
        $this->assertSame('locked', $a->fresh()->status);
    }

    /** "Submit all" covers every eligible draft — there is no per-run cap. */
    public function test_select_all_covers_every_draft_with_no_cap(): void
    {
        Queue::fake([IntelligenceProcessingJob::class]);
        $this->fakeFbrSuccess();

        $drafts = collect(range(1, 12))->map(fn () => $this->makeDraft());

        $res = $this->actingAs($this->user)->postJson('/invoices/bulk-submit', ['select_all_drafts' => true]);
        $res->assertOk()->assertJsonPath('total', 12);

        $batch = InvoiceBulkSubmission::latest('id')->first();
        $this->assertSame(12, (int) $batch->dispatched);
        $this->assertSame(12, (int) $batch->success);
        $this->assertSame('completed', $batch->state);
        $this->assertTrue($drafts->every(fn ($d) => $d->fresh()->status === 'locked'));
    }
}
