<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One bulk "submit to FBR" run for a company.
 *
 * Durable on purpose: the shop starts a run of several thousand invoices,
 * closes the browser (or the phone app), and comes back hours later to a
 * finished result. Nothing about the run may depend on the page staying open
 * or on the cache surviving — see the migration for what that cost before.
 *
 * @see \App\Jobs\SeedBulkSubmitBatchJob   dispatches the per-invoice jobs
 * @see \App\Jobs\BulkSubmitInvoiceJob     submits one invoice, records the outcome
 */
class InvoiceBulkSubmission extends Model
{
    /** States where work is still expected to happen. */
    public const ACTIVE_STATES = ['queued', 'dispatching', 'running'];

    /**
     * No progress for this long while active = nothing is processing the queue
     * (worker/cron down). The run is then treated as stalled so the shop can
     * start a fresh one instead of being locked out by a dead batch forever.
     */
    public const STALE_AFTER_MINUTES = 25;

    /** Keep the row small — only problem invoices are listed. */
    public const MAX_FAILURES_KEPT = 200;

    protected $fillable = [
        'company_id',
        'user_id',
        'target_status',
        'scope',
        'state',
        'invoice_ids',
        'max_invoice_id',
        'cursor_id',
        'total',
        'dispatched',
        'done',
        'success',
        'failed',
        'skipped',
        'pending',
        'failures',
        'cancel_requested',
        'started_at',
        'last_progress_at',
        'completed_at',
        'acknowledged_at',
    ];

    protected $casts = [
        'invoice_ids' => 'array',
        'failures' => 'array',
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'completed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE_STATES, true);
    }

    /** Active on paper, but nothing has moved for a long time. */
    public function isStale(): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        $last = $this->last_progress_at ?? $this->started_at ?? $this->created_at;

        return $last !== null && $last->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /** The run currently owning this company's queue, if any. */
    public static function activeFor(int $companyId): ?self
    {
        return static::where('company_id', $companyId)
            ->whereIn('state', self::ACTIVE_STATES)
            ->latest('id')
            ->first();
    }

    /**
     * The most recent finished run the shop has not acknowledged yet — this is
     * what greets them when they closed the page mid-run and came back later.
     */
    public static function unseenFinishedFor(int $companyId): ?self
    {
        return static::where('company_id', $companyId)
            ->whereNotIn('state', self::ACTIVE_STATES)
            ->whereNull('acknowledged_at')
            ->where('updated_at', '>=', now()->subDays(3))
            ->latest('id')
            ->first();
    }

    public function progressPercent(): int
    {
        if ($this->total <= 0) {
            return 0;
        }

        return (int) min(100, round(min($this->done, $this->total) / $this->total * 100));
    }

    /** Payload the invoice list polls. Keep it small — it is fetched every few seconds. */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'active' => $this->isActive(),
            // The UI's "run is over" flag.
            'finished' => !$this->isActive(),
            'stale' => $this->isStale(),
            'target_status' => $this->target_status,
            'scope' => $this->scope,
            'total' => (int) $this->total,
            'dispatched' => (int) $this->dispatched,
            // A crashed worker can re-run one job, so clamp for display.
            'done' => (int) min($this->done, max($this->total, $this->done)),
            'success' => (int) $this->success,
            'failed' => (int) $this->failed,
            'skipped' => (int) $this->skipped,
            'pending' => (int) $this->pending,
            'percent' => $this->progressPercent(),
            'cancel_requested' => (bool) $this->cancel_requested,
            'failures' => array_values($this->failures ?? []),
            'failures_capped' => count($this->failures ?? []) >= self::MAX_FAILURES_KEPT,
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'acknowledged' => $this->acknowledged_at !== null,
        ];
    }
}
