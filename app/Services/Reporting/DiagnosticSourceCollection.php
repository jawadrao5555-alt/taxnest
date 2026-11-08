<?php

namespace App\Services\Reporting;

use App\Support\NestErps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bounded derived observations; source records and business state are never mutated. */
final class DiagnosticSourceCollection
{
    public function capture(int $companyId, string $source, string $day, string $run): string
    {
        if (! isset(DiagnosticSourceEvidence::SOURCES[$source]) || ! \Illuminate\Support\Str::isUuid($run)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) {
            throw new \InvalidArgumentException('Invalid diagnostic observation identity.');
        }
        $tz = config('reporting_connector.timezone', 'Asia/Karachi');
        $from = Carbon::createFromFormat('!Y-m-d', $day, $tz);
        if ($from->format('Y-m-d') !== $day || $from->isAfter(Carbon::now($tz)->startOfDay())
            || $from->isBefore(Carbon::now($tz)->startOfDay()->subDays(6))) {
            throw new \InvalidArgumentException('Observe only the current seven calendar dates.');
        }
        $company = DB::table('companies')->where('id', $companyId);
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $company->whereNull('deleted_at');
        }
        $company = $company->first(['id', 'product_type']);
        [$product, $table, $stamp] = DiagnosticSourceEvidence::SOURCES[$source];
        $fixed = DiagnosticSourceEvidence::SOURCES[$source][3] ?? [];
        $unavailableReason = DiagnosticSourceEvidence::SOURCES[$source][4] ?? null;
        $safeColumns = DiagnosticSourceEvidence::SOURCES[$source][5] ?? [];
        if (! $company || NestErps::canonicalProductType($company->product_type) !== $product) {
            throw new \InvalidArgumentException('Source does not apply to company.');
        }
        $identity = ['company_id' => $companyId, 'source' => $source, 'business_date' => $day, 'run_id' => $run];
        $result = ['registry_version' => DiagnosticSourceEvidence::REGISTRY_VERSION, 'collected_at' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'), 'status' => 'UNAVAILABLE',
            'retained_rows' => null, 'first_source_at' => null, 'last_source_at' => null];
        try {
            if ($unavailableReason !== null) {
                DB::table('diagnostic_source_observations')->insertOrIgnore($identity + $result);

                return $result['status'];
            }
            $required = array_merge(['id', $stamp, $source === 'di.fiscal' ? 'invoice_id' : 'company_id'], array_keys($fixed), $safeColumns);
            $available = Schema::hasTable($table);
            foreach ($required as $field) {
                $available = $available && Schema::hasColumn($table, $field);
            }
            if ($source === 'di.fiscal') {
                $available = $available && Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'company_id');
            }
            if ($available) {
                $q = DB::table($table.' as e');
                if ($source === 'di.fiscal') {
                    $q->join('invoices as i', 'i.id', '=', 'e.invoice_id')->where('i.company_id', $companyId);
                } else {
                    $q->where('e.company_id', $companyId);
                }
                foreach ($fixed as $column => $value) {
                    $q->where('e.'.$column, $value);
                }
                $to = $from->copy()->endOfDay();
                $q->whereBetween('e.'.$stamp, [$from->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    $to->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')]);
                $row = $q->selectRaw('COUNT(*) as retained_rows, MIN(e.'.$stamp.') as first_source_at, MAX(e.'.$stamp.') as last_source_at')->first();
                $result = array_replace($result, ['status' => (int) $row->retained_rows > 0 ? 'OBSERVED' : 'NO_RETAINED_EVENTS',
                    'retained_rows' => (int) $row->retained_rows, 'first_source_at' => $row->first_source_at, 'last_source_at' => $row->last_source_at]);
            }
        } catch (\Illuminate\Database\QueryException) {
            // No SQL, bindings, payload or exception message enters the report.
            $result['status'] = 'SOURCE_QUERY_FAILED';
        }
        // Same-run retry is idempotent; a new run retains a new observation.
        // Do not overwrite earlier readings or erase evidence of late arrivals.
        DB::table('diagnostic_source_observations')->insertOrIgnore($identity + $result);

        return $result['status'];
    }

    public function latest(int $companyId, string $source, string $from, string $to): array
    {
        try {
            if (! Schema::hasTable('diagnostic_source_observations')) {
                return ['status' => 'UNAVAILABLE', 'complete_collection_proven' => false];
            }
            if (! Schema::hasColumns('diagnostic_source_observations', ['id', 'company_id', 'source', 'business_date',
                'registry_version', 'collected_at', 'status', 'retained_rows', 'first_source_at', 'last_source_at'])) {
                return ['status' => 'COLLECTION_READ_FAILED', 'complete_collection_proven' => false];
            }
            $r = DB::table('diagnostic_source_observations')->where('company_id', $companyId)->where('source', $source)
                ->whereBetween('business_date', [$from, $to])->orderByDesc('collected_at')->orderByDesc('id')
                ->first(['business_date', 'registry_version', 'collected_at', 'status', 'retained_rows', 'first_source_at', 'last_source_at']);
            if (! $r) {
                return ['status' => 'NOT_OBSERVED', 'complete_collection_proven' => false];
            }

            return ['status' => $r->status, 'registry_version' => (int) $r->registry_version, 'business_date' => $r->business_date, 'collected_at' => $r->collected_at,
                'retained_rows' => $r->retained_rows === null ? null : (int) $r->retained_rows,
                'first_source_at' => $r->first_source_at, 'last_source_at' => $r->last_source_at,
                'timestamp_storage_timezone' => config('app.timezone'), 'complete_collection_proven' => false,
                'note' => 'Latest retained-source observation only; does not prove original collection, deleted events, completeness or regulator acceptance.'];
        } catch (\Illuminate\Database\QueryException) {
            return ['status' => 'COLLECTION_READ_FAILED', 'complete_collection_proven' => false];
        }
    }
}
