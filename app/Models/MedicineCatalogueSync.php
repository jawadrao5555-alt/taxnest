<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Progress row of one DRAP catalogue crawl (Task 1579).
 *
 * Durable on purpose (hours-long-batch rule): ~1,070 pages at a polite pace
 * is 45+ minutes, a deploy restarts the queue worker mid-run, and the admin
 * page must still show honest progress afterwards. The cursor (phase +
 * next_page) lives HERE, so the next job run simply continues; nothing about
 * the run depends on cache or on a single worker process surviving.
 *
 * @see \App\Services\Pharmacy\MedicineCatalogueSyncService
 * @see \App\Jobs\SyncMedicineCatalogueJob
 */
class MedicineCatalogueSync extends Model
{
    protected $table = 'medicine_catalogue_syncs';

    public const ACTIVE_STATES = ['queued', 'running'];

    /** Active on paper but silent this long ⇒ the worker died; a new run may start. */
    public const STALE_AFTER_MINUTES = 20;

    protected $fillable = [
        'state', 'trigger', 'started_by', 'phase_index', 'next_page', 'total_pages',
        'pages_done', 'rows_seen', 'rows_created', 'rows_updated', 'price_changes',
        'errors_count', 'last_error', 'cancel_requested', 'started_at',
        'last_progress_at', 'completed_at',
    ];

    protected $casts = [
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE_STATES, true);
    }

    public function isStale(): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        $last = $this->last_progress_at ?? $this->started_at ?? $this->created_at;

        return $last !== null && $last->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public static function active(): ?self
    {
        return static::whereIn('state', self::ACTIVE_STATES)->latest('id')->first();
    }

    /** Whole-run progress as a fraction, honest about unknown page counts. */
    public function progressPercent(int $phaseCount): ?int
    {
        if (in_array($this->state, ['completed'], true)) {
            return 100;
        }
        if (!$this->total_pages || $phaseCount < 1) {
            return null;
        }
        // Phases are weighted equally only for display; the first (unfiltered)
        // phase dominates the real work, so show its own fraction while in it.
        $inPhase = max(0, min(1, ($this->next_page - 1) / max(1, $this->total_pages)));
        $pct = (($this->phase_index + $inPhase) / $phaseCount) * 100;

        return (int) floor(max(0, min(99, $pct)));
    }

    public function toStatusArray(int $phaseCount = 1): array
    {
        return [
            'id' => (int) $this->id,
            'state' => $this->isStale() ? 'stalled' : $this->state,
            'trigger' => $this->trigger,
            'phase_index' => (int) $this->phase_index,
            'phase_count' => $phaseCount,
            'next_page' => (int) $this->next_page,
            'total_pages' => $this->total_pages !== null ? (int) $this->total_pages : null,
            'pages_done' => (int) $this->pages_done,
            'rows_seen' => (int) $this->rows_seen,
            'rows_created' => (int) $this->rows_created,
            'rows_updated' => (int) $this->rows_updated,
            'price_changes' => (int) $this->price_changes,
            'errors_count' => (int) $this->errors_count,
            'last_error' => $this->last_error,
            'percent' => $this->progressPercent($phaseCount),
            'started_at' => $this->started_at?->toIso8601String(),
            'last_progress_at' => $this->last_progress_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'active' => $this->isActive() && !$this->isStale(),
        ];
    }
}
