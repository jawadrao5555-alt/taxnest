<?php

namespace App\Services;

/** Read-only reporting classification. NEVER an authorization/deletion predicate. */
final class AdminPosBillDisposition
{
    public const LABELS = [
        'reported' => 'Reported', 'local_final' => 'Local Final',
        'pending_decision' => 'Pending Decision', 'reporting_pending' => 'Reporting Pending',
        'reporting_failed' => 'Reporting Failed', 'unclassified' => 'Not classified / legacy state',
    ];

    public static function state($record, string $product): string
    {
        $prefix = $product === 'pos' ? 'pra' : 'fbr';
        if (trim((string) $record->{$prefix.'_invoice_number'}) !== '') return 'reported';
        $status = $record->{$prefix.'_status'};
        if (in_array($status, ['failed', 'config_error'], true)) return 'reporting_failed';
        if (in_array($status, ['pending', 'submitted', 'verification_pending', 'pending_verification', 'processing', 'submitting'], true)) return 'reporting_pending';
        if ($record->invoice_mode === 'local' && $status === 'local') return 'pending_decision';
        // Existing persisted reporting-OFF finals, independent of TODAY's
        // company settings. Unknown legacy statuses must never become Local.
        if ($record->status === 'completed' && $status === null
            && in_array($record->invoice_mode, [$prefix, null], true)) return 'local_final';
        return 'unclassified';
    }

    /** Same exact states for counts, rows, totals and CSV on SQLite/MariaDB. */
    public static function sql(string $product): string
    {
        [$table, $prefix] = $product === 'pos' ? ['pos_transactions', 'pra'] : ['fbr_pos_transactions', 'fbr'];
        return "CASE WHEN TRIM(COALESCE($table.{$prefix}_invoice_number,'')) <> '' THEN 'reported' "
            ."WHEN $table.{$prefix}_status IN ('failed','config_error') THEN 'reporting_failed' "
            ."WHEN $table.{$prefix}_status IN ('pending','submitted','verification_pending','pending_verification','processing','submitting') THEN 'reporting_pending' "
            ."WHEN $table.invoice_mode='local' AND $table.{$prefix}_status='local' THEN 'pending_decision' "
            ."WHEN $table.status='completed' AND $table.{$prefix}_status IS NULL AND ($table.invoice_mode='$prefix' OR $table.invoice_mode IS NULL) THEN 'local_final' "
            ."ELSE 'unclassified' END";
    }
}
