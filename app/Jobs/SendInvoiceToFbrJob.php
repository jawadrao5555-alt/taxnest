<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\FbrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInvoiceToFbrJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3; // retry 3 times

    protected $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function handle(): void
    {
        $fbrService = new FbrService();
        $response = $fbrService->submitInvoice($this->invoice);

        if ($response['status'] === 'success') {
            $this->invoice->status = 'locked';
            $this->invoice->invoice_number = $response['fbr_invoice_number'];
            $this->invoice->save();
        } else {
            $this->release(30); // retry after 30 seconds
        }
    }
}
