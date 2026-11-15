<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tenant-scoped local ledgers follow the existing company bin/hold/purge policy. */
class LocalCollectionLifecycle
{
    // Children before parents; snapshots are never rewritten during bin or restore.
    public const TABLES = [
        'prospective_pos_quota_retention', 'prospective_fbr_quota_retention',
        'school_fee_receipts', 'school_fee_day_closes', 'school_fee_accounts', 'school_fee_heads',
        'hospital_payments', 'hospital_day_closes', 'hospital_invoices', 'hospital_services',
    ];

    public function counts(int $companyId): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                $counts[$table] = DB::table($table)->where('company_id', $companyId)->count();
            }
        }
        return $counts;
    }

    /** Invoked only by the audited purge hook inside the existing purge transaction. */
    public function purge(int $companyId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Local collection purge requires the company purge transaction.');
        }
        $counts = $this->counts($companyId);
        foreach (array_keys($counts) as $table) {
            DB::table($table)->where('company_id', $companyId)->delete();
        }
        return $counts;
    }
}
