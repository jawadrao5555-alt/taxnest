<?php

namespace App\Models;

use App\Services\PrintJobWakePublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PosPrintJob extends Model
{
    protected $table = 'pos_print_jobs';

    protected $fillable = [
        'company_id',
        'type',
        'target_printer',
        'transaction_id',
        'restaurant_order_id',
        'render_query',
        'status',
        'claim_token',
        'device_uid', // Task 1166: per-counter routing — NULL = company-wide job

        'printed_item_ids',
        'error',
        'attempts',
        'created_by',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'printed_item_ids' => 'array',
    ];

    /**
     * All current enqueue paths use Eloquent. Register the wake here rather
     * than in individual controllers/services so a newly added enqueue path
     * cannot accidentally omit it. afterCommit prevents phantom wakes when the
     * enclosing sale transaction rolls back.
     */
    protected static function booted(): void
    {
        static::created(function (self $job): void {
            try {
                DB::afterCommit(function () use ($job): void {
                    try {
                        app(PrintJobWakePublisher::class)->publish($job);
                    } catch (\Throwable $ignored) {
                        // Wake delivery is strictly best-effort.
                    }
                });
            } catch (\Throwable $ignored) {
                // A transaction-manager/configuration problem must not prevent
                // an already persisted print job from being claimed by polling.
            }
        });
    }
}
