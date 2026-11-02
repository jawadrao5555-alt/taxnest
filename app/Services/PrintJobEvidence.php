<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Server-clock, append-only attempt history. Never stores documents or claim tokens. */
final class PrintJobEvidence
{
    public const RETENTION_DAYS = 30;

    public static function record(int $companyId, string $event, ?int $jobId = null, ?int $attempt = null, array $context = []): void
    {
        try {
            $allowed = ['device_uid', 'agent_version', 'target_printer', 'type', 'order_id', 'user_id',
                'request_id', 'reason', 'outcome', 'stage', 'content_ms', 'content_attempts', 'print_ms', 'gap_seconds', 'printer_names'];
            $safe = array_intersect_key($context, array_flip($allowed));
            foreach ($safe as $key => $value) {
                if ($key === 'printer_names') {
                    $safe[$key] = array_slice(array_values(array_filter((array) $value, 'is_string')), 0, 50);
                    $safe[$key] = array_map(fn ($name) => mb_substr($name, 0, 255), $safe[$key]);
                } elseif (is_string($value)) $safe[$key] = mb_substr($value, 0, $key === 'target_printer' ? 255 : 100);
                elseif (!is_numeric($value) && $value !== null) unset($safe[$key]);
            }
            try {
                Log::info('PRINT_EVIDENCE', ['company_id' => $companyId, 'job_id' => $jobId, 'attempt' => $attempt,
                    'event' => $event, 'context' => $safe, 'clock' => 'server']);
            } catch (\Throwable $ignored) {
                // A full/broken file-log sink must not silence the independent DB stream.
            }
            if (!Schema::hasTable('pos_print_evidence')) {
                throw new \RuntimeException('Diagnostic schema unavailable');
            }
            $row = [
                'company_id' => $companyId, 'job_id' => $jobId, 'attempt' => $attempt,
                'event' => $event, 'context' => json_encode($safe), 'received_at' => now(),
            ];
            if (Schema::hasColumn('pos_print_evidence', 'product_type')) {
                $product = DB::table('companies')->where('id', $companyId)->value('product_type');
                $row['product_type'] = in_array($product, ['pos', 'fbrpos'], true) ? $product : null;
                $job = $jobId && Schema::hasTable('pos_print_jobs')
                    ? DB::table('pos_print_jobs')->where('company_id', $companyId)->where('id', $jobId)->first() : null;
                $row['job_id'] = $job?->id;
                $table = $product === 'pos' ? 'pos_transactions' : ($product === 'fbrpos' ? 'fbr_pos_transactions' : null);
                $bill = $job && $table && Schema::hasTable($table)
                    ? DB::table($table)->where('company_id', $companyId)->where('id', $job->transaction_id ?? 0)->first() : null;
                $order = $job && $product === 'pos' && Schema::hasTable('restaurant_orders')
                    ? DB::table('restaurant_orders')->where('company_id', $companyId)->where('id', $job->restaurant_order_id ?? 0)->first() : null;
                if (!$job && $product === 'pos' && is_numeric($safe['order_id'] ?? null) && Schema::hasTable('restaurant_orders')) {
                    $order = DB::table('restaurant_orders')->where('company_id', $companyId)->where('id', (int) $safe['order_id'])->first();
                }
                if (!$bill && $order && !empty($order->pos_transaction_id) && $table && Schema::hasTable($table)) {
                    $bill = DB::table($table)->where('company_id', $companyId)->where('id', $order->pos_transaction_id)->first();
                }
                $row += ['transaction_id' => $bill?->id, 'order_id' => $order?->id,
                    'branch_id' => $bill?->branch_id ?? $order?->branch_id ?? null];
                if (isset($bill->branch_id, $order->branch_id) && (int) $bill->branch_id !== (int) $order->branch_id) {
                    $row['branch_id'] = null; // conflicting references are not guessed into a branch
                }
            }
            DB::table('pos_print_evidence')->insert($row);
            self::healthAfterCommit($companyId, true);
        } catch (\Throwable $ignored) {
            // Diagnostics must not alter claim, print, or failover behavior.
            self::healthAfterCommit($companyId, false);
            try { Log::warning('PRINT_EVIDENCE_STORAGE_UNAVAILABLE', ['company_id' => $companyId]); } catch (\Throwable $ignored) {}
        }
    }

    private static function healthAfterCommit(int $companyId, bool $success): void
    {
        try {
            // Never introduce a company-wide health-row lock into a sale/result
            // transaction. Rolled-back observations must not claim collection.
            DB::afterCommit(fn () => self::health($companyId, $success));
        } catch (\Throwable $ignored) {}
    }

    private static function health(int $companyId, bool $success): void
    {
        try {
            if (!Schema::hasTable('pos_print_evidence_health')) return;
            DB::table('pos_print_evidence_health')->insertOrIgnore(['company_id' => $companyId]);
            $q = DB::table('pos_print_evidence_health')->where('company_id', $companyId);
            if ($success) {
                (clone $q)->whereNull('first_recorded_at')->update(['first_recorded_at' => now()]);
                $q->update(['last_recorded_at' => now()]);
            } else {
                $q->update(['last_failure_at' => now(), 'failure_count' => DB::raw('failure_count + 1')]);
            }
        } catch (\Throwable $ignored) {
            // If the database itself is unavailable even failure health cannot be persisted.
        }
    }

    public static function prune(int $limit = 1000): int
    {
        if (!Schema::hasTable('pos_print_evidence')) return 0;
        $ids = DB::table('pos_print_evidence')->where('received_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->orderBy('received_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');
        return DB::table('pos_print_evidence')->whereIn('id', $ids)
            ->where('received_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
