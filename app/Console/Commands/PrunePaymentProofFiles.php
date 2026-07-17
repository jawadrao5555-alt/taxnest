<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\PaymentProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the uploaded receipt FILES of payment proofs that were verified or
 * rejected more than N months ago, so storage on the cPanel host stays
 * bounded. The DB row is ALWAYS kept for audit — only the file goes;
 * `file_pruned_at` marks when. Pending proofs are never touched.
 *
 * Also sweeps ORPHANED receipt folders: directories under
 * payment-proofs/{company_id}/ whose company no longer exists (force-deleted
 * from the bin, so not even soft-deleted) AND that have zero payment_proofs
 * rows. Those files can never be downloaded by anyone — pure dead weight.
 * Soft-deleted (binned) companies are NEVER touched.
 *
 * Runs daily from the scheduler (same schedule:run cron as the other jobs).
 */
class PrunePaymentProofFiles extends Command
{
    protected $signature = 'payment-proofs:prune
                            {--months=12 : Delete files for proofs processed more than this many months ago}
                            {--dry-run : List what would be pruned without deleting anything}';

    protected $description = 'Delete receipt files of old verified/rejected payment proofs (DB rows kept for audit).';

    public function handle(): int
    {
        if (!Schema::hasTable('payment_proofs')) {
            $this->info('payment_proofs table missing — nothing to prune.');
            return self::SUCCESS;
        }
        if (!Schema::hasColumn('payment_proofs', 'file_pruned_at')) {
            $this->warn('file_pruned_at column missing — run migrations first (php artisan migrate --force).');
            return self::FAILURE;
        }

        $months = max(1, (int) $this->option('months'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMonths($months);

        $query = PaymentProof::whereIn('status', ['verified', 'rejected'])
            ->whereNull('file_pruned_at')
            ->where('proof_path', '!=', '')
            ->where(function ($q) use ($cutoff) {
                // verified_at is set on both approve & reject; fall back to
                // updated_at for legacy rows where it may be missing.
                $q->where('verified_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('verified_at')->where('updated_at', '<', $cutoff);
                    });
            });

        $deleted = 0;
        $missing = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($proofs) use ($dryRun, &$deleted, &$missing, &$failed) {
            foreach ($proofs as $proof) {
                $disk = Storage::disk('local');
                $exists = $proof->proof_path && $disk->exists($proof->proof_path);

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] #%d company %d %s %s — %s',
                        $proof->id,
                        $proof->company_id,
                        $proof->status,
                        optional($proof->verified_at)->format('Y-m-d') ?? '(no verified_at)',
                        $exists ? 'would delete ' . $proof->proof_path : 'file already missing'
                    ));
                    continue;
                }

                try {
                    if ($exists) {
                        // The 'local' disk has 'throw' => false, so a failed
                        // delete returns false instead of throwing. Only mark
                        // pruned on a CONFIRMED delete — otherwise leave the
                        // row unflagged so tomorrow's run retries it.
                        if (!$disk->delete($proof->proof_path)) {
                            $failed++;
                            Log::warning('Payment proof file prune failed', [
                                'proof_id' => $proof->id,
                                'path' => $proof->proof_path,
                                'error' => 'delete returned false (permissions/IO?)',
                            ]);
                            continue;
                        }
                        $deleted++;
                    } else {
                        // File already gone (manual cleanup / host migration) —
                        // still mark pruned so we stop re-checking it forever.
                        $missing++;
                    }
                    // Keep proof_path text for the audit trail; the flag alone
                    // says the file no longer exists.
                    $proof->forceFill(['file_pruned_at' => now()])->saveQuietly();
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Payment proof file prune failed', [
                        'proof_id' => $proof->id,
                        'path' => $proof->proof_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        if ($dryRun) {
            $this->info("Dry run complete (retention: {$months} months, cutoff {$cutoff->format('Y-m-d')}). Nothing deleted.");
        } else {
            $summary = "Pruned {$deleted} file(s), {$missing} already missing, {$failed} failed (retention: {$months} months).";
            $this->info($summary);
            if ($deleted > 0 || $failed > 0) {
                Log::info('Payment proof retention prune: ' . $summary);
            }
        }

        $this->sweepOrphanFolders($dryRun);

        return self::SUCCESS;
    }

    /**
     * Delete payment-proofs/{company_id} folders whose company_id no longer
     * exists AT ALL (not even soft-deleted) AND has zero payment_proofs rows.
     * Anything else — live companies, binned companies, folders with any DB
     * row, non-numeric folder names — is left strictly alone.
     */
    private function sweepOrphanFolders(bool $dryRun): void
    {
        $disk = Storage::disk('local');
        $dirs = $disk->directories('payment-proofs');

        $removed = 0;
        $failed = 0;

        foreach ($dirs as $dir) {
            $name = basename($dir);

            // Only sweep folders that look exactly like a company id.
            if (!ctype_digit($name)) {
                continue;
            }
            $companyId = (int) $name;

            // Soft-deleted (binned) companies still exist — never touch them.
            if (Company::withTrashed()->whereKey($companyId)->exists()) {
                continue;
            }

            // Belt & braces: if ANY payment_proofs row still points at this
            // company, keep the folder (row keeps proof_path for audit).
            if (PaymentProof::where('company_id', $companyId)->exists()) {
                continue;
            }

            $fileCount = count($disk->allFiles($dir));

            if ($dryRun) {
                $this->line("[dry-run] orphan folder {$dir} ({$fileCount} file(s), company {$companyId} gone) — would delete");
                continue;
            }

            if ($disk->deleteDirectory($dir)) {
                $removed++;
                Log::info('Payment proof orphan folder deleted', [
                    'dir' => $dir,
                    'company_id' => $companyId,
                    'files' => $fileCount,
                ]);
            } else {
                $failed++;
                Log::warning('Payment proof orphan folder delete failed', [
                    'dir' => $dir,
                    'company_id' => $companyId,
                    'error' => 'deleteDirectory returned false (permissions/IO?)',
                ]);
            }
        }

        if ($dryRun) {
            $this->info('Orphan sweep dry run complete. Nothing deleted.');
            return;
        }

        $summary = "Orphan folder sweep: removed {$removed}, failed {$failed}.";
        $this->info($summary);
        if ($removed > 0 || $failed > 0) {
            Log::info('Payment proof orphan sweep: ' . $summary);
        }
    }
}
