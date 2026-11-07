<?php

namespace App\Console\Commands;

use App\Services\Reporting\{DiagnosticSourceCollection, DiagnosticSourceEvidence};
use App\Support\NestErps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

class CollectDiagnosticSourceObservations extends Command
{
    protected $signature = 'diagnostics:observe-sources';
    protected $description = 'Retain seven days of scoped source-presence observations; never fiscal replay or health certification';

    public function handle(DiagnosticSourceCollection $collector): int
    {
        if (!Schema::hasTable('diagnostic_source_observations')) {
            $this->error('Diagnostic observation storage is unavailable.');
            return self::FAILURE;
        }
        $run = (string) Str::uuid();
        $day = now(config('reporting_connector.timezone', 'Asia/Karachi'))->subDay()->toDateString();
        $failures = 0;
        $query = DB::table('companies')->select(['id', 'product_type']);
        if (Schema::hasColumn('companies', 'deleted_at')) $query->whereNull('deleted_at');
        $query->orderBy('id')->chunkById(100, function ($companies) use ($collector, $day, $run, &$failures) {
            foreach ($companies as $company) foreach (DiagnosticSourceEvidence::SOURCES as $id => $definition) {
                if (NestErps::canonicalProductType($company->product_type) !== $definition[0]) continue;
                try { if ($collector->capture((int) $company->id, $id, $day, $run) === 'SOURCE_QUERY_FAILED') $failures++; }
                catch (\Throwable) { $failures++; }
            }
        });
        // Retire ONLY this derived ledger. Never prune source/fiscal/print data.
        DB::table('diagnostic_source_observations')->where('collected_at', '<', now(config('app.timezone'))->subDays(7)->format('Y-m-d H:i:s'))->delete();
        $this->line('Source observation run finished; collection failures: '.$failures.'. Completeness remains unproven.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
