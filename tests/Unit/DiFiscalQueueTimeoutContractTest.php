<?php

namespace Tests\Unit;

use App\Jobs\BulkSubmitInvoiceJob;
use App\Jobs\RetryFailedFbrInvoicesJob;
use App\Jobs\SeedBulkSubmitBatchJob;
use App\Jobs\SendInvoiceToFbrJob;
use App\Services\DiFiscalSubmissionState;
use Tests\TestCase;

/**
 * A queue reservation shorter than a fiscal job can redeliver a still-running
 * regulator POST. Keep this explicit contract next to the job declarations.
 */
class DiFiscalQueueTimeoutContractTest extends TestCase
{
    public function test_fiscal_job_timeout_is_shorter_than_every_queue_reservation(): void
    {
        $longestJobTimeout = max(
            (new BulkSubmitInvoiceJob(1, 1))->timeout,
            (new SeedBulkSubmitBatchJob(1))->timeout,
            (new RetryFailedFbrInvoicesJob(1))->timeout,
            (new SendInvoiceToFbrJob(1))->timeout,
        );

        foreach (['database', 'beanstalkd', 'redis'] as $connection) {
            $this->assertGreaterThan(
                $longestJobTimeout,
                config("queue.connections.{$connection}.retry_after"),
                "{$connection} may not redeliver a running DI fiscal job"
            );
        }
        $this->assertGreaterThan(
            max(
                config('queue.connections.database.retry_after'),
                config('queue.connections.beanstalkd.retry_after'),
                config('queue.connections.redis.retry_after'),
            ),
            DiFiscalSubmissionState::LEASE_SECONDS,
            'A DI lease must outlive any queue reservation and seal, not replay, on expiry.'
        );
    }

    public function test_unsafe_low_environment_reservation_is_clamped_fail_closed(): void
    {
        $this->assertGreaterThanOrEqual(360, config('queue.connections.database.retry_after'));
        $this->assertGreaterThanOrEqual(360, config('queue.connections.beanstalkd.retry_after'));
        $this->assertGreaterThanOrEqual(360, config('queue.connections.redis.retry_after'));
    }
}