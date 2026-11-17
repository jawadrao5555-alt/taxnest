<?php

namespace App\Jobs;

use App\Models\FbrPosTransaction;
use App\Services\CollectionFbrSubmission;
use App\Services\FbrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SubmitCollectionFbrReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public int $transactionId)
    {
    }

    public function handle(FbrService $fbr): void
    {
        $transaction = FbrPosTransaction::with(['company', 'items'])->find($this->transactionId);
        if (!$transaction || !CollectionFbrSubmission::canSubmit($transaction)
            || ($transaction->company->fbr_connection_mode ?? 'cloud') !== 'cloud') {
            return;
        }
        try {
            $fbr->submitFbrPosTransaction($transaction);
        } finally {
            CollectionFbrSubmission::reconcile($transaction->fresh());
        }
    }
}
