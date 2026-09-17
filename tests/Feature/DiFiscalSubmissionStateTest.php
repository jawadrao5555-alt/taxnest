<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\DiFiscalSubmissionState;
use App\Services\FbrService;
use App\Services\IntegrityHashService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Local-only DI fiscal state-machine tests. No FBR URL, token or HTTP client is
 * used here: the cases model the regulator outcomes at the mutation boundary.
 */
class DiFiscalSubmissionStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('internal_invoice_number')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_invoice_id')->nullable();
            $table->timestamp('fbr_submission_date')->nullable();
            $table->string('status')->default('draft');
            $table->string('fbr_status')->nullable();
            $table->boolean('is_fbr_processing')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->string('submission_mode')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->string('fiscal_submission_state')->nullable();
            $table->string('fiscal_submission_environment')->nullable();
            $table->string('fiscal_submission_provenance')->nullable();
            $table->string('fiscal_payload_hash')->nullable();
            $table->timestamp('fiscal_lease_expires_at')->nullable();
            $table->timestamp('fiscal_acknowledged_at')->nullable();
            $table->string('document_type')->nullable();
            $table->string('reference_invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('buyer_ntn')->nullable();
            $table->string('buyer_cnic')->nullable();
            $table->string('buyer_registration_type')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_value_excluding_st', 15, 2)->default(0);
            $table->decimal('total_sales_tax', 15, 2)->default(0);
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
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->create([
            'company_id' => 91, 'invoice_number' => 'DI-91-1',
            'internal_invoice_number' => 'DI-91-1', 'document_type' => 'Sale Invoice',
            'invoice_date' => '2026-09-16', 'total_amount' => 118,
            'total_value_excluding_st' => 100, 'total_sales_tax' => 18,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'hs_code' => '0101.2100',
            'schedule_type' => 'standard', 'tax_rate' => 18, 'description' => 'line',
            'quantity' => 1, 'price' => 100, 'tax' => 18,
        ]);
        return $invoice;
    }

    public function test_accepted_requires_reference_and_explicit_environment(): void
    {
        $invoice = $this->invoice();
        $claimed = DiFiscalSubmissionState::reserve($invoice->id, 'test', 'sandbox');
        $this->assertNotNull($claimed);
        $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id, 'second', 'sandbox'));

        $this->expectException(\InvalidArgumentException::class);
        DiFiscalSubmissionState::accepted($claimed, '', 'sandbox', 'test');
    }

    public function test_timeout_callback_loss_stays_non_replayable_until_reconciled(): void
    {
        $invoice = $this->invoice();
        $claimed = DiFiscalSubmissionState::reserve($invoice->id, 'test', 'production');
        DiFiscalSubmissionState::verificationRequired($claimed, 'production', 'ambiguous_transport');
        $claimed->save();

        $fresh = $invoice->fresh();
        $this->assertSame('pending_verification', $fresh->status);
        $this->assertSame(DiFiscalSubmissionState::VERIFICATION_REQUIRED, $fresh->fiscal_submission_state);
        $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id, 'unsafe_replay', 'production'));
    }

    public function test_expired_claim_is_sealed_for_verification_not_released_for_replay(): void
    {
        $invoice = $this->invoice();
        $claimed = DiFiscalSubmissionState::reserve($invoice->id, 'test', 'sandbox');
        $this->assertNotNull($claimed);
        $claimed->fiscal_lease_expires_at = now()->subMinute();
        $claimed->save();

        $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id, 'unsafe_replay', 'sandbox'));
        $fresh = $invoice->fresh();
        $this->assertSame('pending_verification', $fresh->status);
        $this->assertSame('expired_submission_lease', $fresh->fiscal_submission_provenance);
    }

    public function test_synthetic_response_cannot_become_regulator_acceptance(): void
    {
        $invoice = $this->invoice();
        $claimed = DiFiscalSubmissionState::reserve($invoice->id, 'test', 'sandbox');
        DiFiscalSubmissionState::simulated($claimed);
        $claimed->save();

        $fresh = $invoice->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('simulated', $fresh->fbr_status);
        $this->assertNull($fresh->fbr_invoice_number);
        $this->assertNull($fresh->fbr_submission_hash, 'Demo completion must release the canonical claim for a later real attempt.');
    }

    public function test_integrity_hash_covers_fiscal_reference_and_immutable_line_identity(): void
    {
        $invoice = $this->invoice()->fresh()->load('items');
        $first = IntegrityHashService::generate($invoice);
        $invoice->fbr_invoice_number = 'FBR-ONE';
        $second = IntegrityHashService::generate($invoice);
        $invoice->items->first()->hs_code = '0101.2200';
        $third = IntegrityHashService::generate($invoice);

        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
    }

    public function test_only_a_valid_regulator_acknowledgement_shape_is_accepted(): void
    {
        $parser = new \ReflectionMethod(FbrService::class, 'parseFbrResponse');
        $parser->setAccessible(true);
        $service = new FbrService();

        $accepted = $parser->invoke($service, [
            'invoiceNumber' => 'FBR-ACK-91',
            'validationResponse' => ['statusCode' => '00', 'status' => 'valid'],
        ]);
        $rejected = $parser->invoke($service, [
            'validationResponse' => ['statusCode' => '01', 'status' => 'invalid', 'error' => 'Rejected'],
        ]);
        $malformed = $parser->invoke($service, [
            'validationResponse' => ['statusCode' => '00', 'status' => 'valid'],
        ]);

        $this->assertTrue($accepted['valid']);
        $this->assertSame('FBR-ACK-91', $accepted['invoiceNumber']);
        $this->assertFalse($rejected['valid']);
        $this->assertFalse($malformed['valid'], 'A valid label without a regulator reference is not acceptance.');
    }

    public function test_accepted_or_rejected_terminal_states_cannot_be_double_claimed(): void
    {
        $accepted = $this->invoice();
        $claimed = DiFiscalSubmissionState::reserve($accepted->id, 'test', 'sandbox');
        DiFiscalSubmissionState::accepted($claimed, 'FBR-ACK-92', 'sandbox', 'test_ack');
        $claimed->save();
        $this->assertNull(DiFiscalSubmissionState::reserve($accepted->id, 'duplicate', 'sandbox'));

        $rejected = $this->invoice();
        $claimedRejected = DiFiscalSubmissionState::reserve($rejected->id, 'test', 'sandbox');
        DiFiscalSubmissionState::rejected($claimedRejected);
        $claimedRejected->save();
        $this->assertNotNull(DiFiscalSubmissionState::reserve($rejected->id, 'explicit_rejection_retry', 'sandbox'));
    }
}