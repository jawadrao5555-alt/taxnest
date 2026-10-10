<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\DiFiscalSubmissionState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DiCanonicalRetryCommandTest extends DiFiscalSubmissionStateTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('fbr_environment'); $t->softDeletes(); $t->timestamps();
        });
        Company::create(['name' => 'Fictional seller', 'fbr_environment' => 'sandbox']);
    }

    private function draft(): Invoice
    {
        return Invoice::withoutGlobalScopes()->create(['company_id' => 1, 'invoice_number' => 'DI-CLI-1']);
    }

    public function test_command_reserves_and_delegates_to_canonical_controller(): void
    {
        $invoice = $this->draft();
        $this->mock(InvoiceController::class)->shouldReceive('submitToFbrSync')->once()
            ->withArgs(function (Invoice $claimed, string $environment) use ($invoice) {
                return $claimed->id === $invoice->id && $claimed->is_fbr_processing
                    && $claimed->submission_mode === 'cli_retry' && $environment === 'sandbox';
            })->andReturnUsing(function (Invoice $claimed) {
                DiFiscalSubmissionState::verificationRequired($claimed, 'sandbox', 'test_callback_loss');
                $claimed->save();
                return ['status' => 'pending_verification'];
            });
        $this->artisan('fbr:retry', ['invoice_id' => $invoice->id, '--attempts' => 1, '--interval' => 0])->assertExitCode(1);
        $this->assertSame('pending_verification', $invoice->fresh()->status);
        $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id, 'again', 'sandbox'));
    }

    public function test_command_does_not_submit_an_accepted_invoice_or_unknown_outcome(): void
    {
        $invoice = $this->draft();
        DiFiscalSubmissionState::accepted($invoice, 'ACK-CLI', 'sandbox', 'test'); $invoice->save();
        $this->mock(InvoiceController::class)->shouldNotReceive('submitToFbrSync');
        $this->artisan('fbr:retry', ['invoice_id' => $invoice->id, '--attempts' => 1])->assertExitCode(0);
        $invoice = $this->draft();
        DiFiscalSubmissionState::verificationRequired($invoice, 'sandbox', 'test'); $invoice->save();
        $this->artisan('fbr:retry', ['invoice_id' => $invoice->id, '--attempts' => 1])->assertExitCode(1);
    }
    public function test_callback_exception_never_releases_an_unknown_submission_for_replay(): void
    {
        $invoice = $this->draft();
        $this->mock(InvoiceController::class)->shouldReceive('submitToFbrSync')->once()->andThrow(new \RuntimeException('Synthetic callback loss'));
        $this->artisan('fbr:retry', ['invoice_id' => $invoice->id, '--attempts' => 1])->assertExitCode(1);
        $this->assertSame('pending_verification', $invoice->fresh()->status);
        $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id, 'again', 'sandbox'));
    }

}
