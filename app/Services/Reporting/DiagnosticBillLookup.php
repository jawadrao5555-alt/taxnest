<?php

namespace App\Services\Reporting;

use App\Support\NestErps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Resolve an explicitly named reference namespace before following immutable bill links. */
final class DiagnosticBillLookup
{
    public function read(array $args, ?array $allowed): array
    {
        if (array_diff(array_keys($args), ['company_name', 'company_id', 'reference_kind', 'bill_reference', 'counter_date', 'branch_id'])) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported bill lookup filter.']);
        }
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150',
            'company_id' => 'nullable|integer|min:1', 'reference_kind' => 'required|in:invoice_serial,pra_daily_token',
            'bill_reference' => 'required|string|min:1|max:150',
            'counter_date' => 'required_if:reference_kind,pra_daily_token|nullable|date_format:Y-m-d',
            'branch_id' => 'nullable|integer|min:1'])->validate();
        if ($v['reference_kind'] === 'invoice_serial' && isset($v['counter_date'])) {
            throw ValidationException::withMessages(['counter_date' => 'Counter date applies only to daily tokens.']);
        }
        $query = DB::table('companies')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($v['company_name']))]);
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }
        if (isset($v['company_id'])) {
            $query->where('id', $v['company_id']);
        }
        $companies = $query->limit(2)->get(['id', 'name', 'product_type']);
        if ($companies->count() !== 1) {
            throw ValidationException::withMessages(['company_name' => 'Exact company is ambiguous, unavailable or outside this connection scope.']);
        }
        $company = $companies->sole();
        $product = NestErps::canonicalProductType($company->product_type);
        $base = ['version' => 1, 'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => $product],
            'reference_kind' => $v['reference_kind'], 'bill_id' => null, 'candidates' => [], 'has_more' => false,
            'complete' => false, 'physical_print' => 'unknown',
            'note' => 'A reference resolves identity only. Absence does not prove deletion, missing KOT, physical printing or fiscal acceptance.'];
        $table = match ($product) {
            'pos' => 'pos_transactions', 'di' => 'invoices', 'fbrpos' => 'fbr_pos_transactions', default => null,
        };
        $daily = $v['reference_kind'] === 'pra_daily_token';
        if (! $table || ($daily && $product !== 'pos')) {
            return array_replace($base, ['status' => 'UNSUPPORTED_REFERENCE']);
        }
        $required = ['id', 'company_id', ...($daily ? ['bill_token', 'created_at'] : ['invoice_number'])];
        if (isset($v['branch_id'])) {
            $required[] = 'branch_id';
        }
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $required)) {
                return array_replace($base, ['status' => 'UNAVAILABLE']);
            }
            $bills = DB::table($table)->where('company_id', $company->id);
            if (isset($v['branch_id'])) {
                $bills->where('branch_id', $v['branch_id']);
            }
            if ($daily) {
                if (! preg_match('/^L0*([1-9][0-9]{0,8})$/D', $v['bill_reference'], $match)) {
                    throw ValidationException::withMessages(['bill_reference' => 'PRA daily reference must be an L-prefixed positive token.']);
                }
                // OrderTokenService has historically reset this counter at 06:00
                // in app timezone. Current mutable receipt style is irrelevant.
                $start = Carbon::createFromFormat('!Y-m-d', $v['counter_date'], config('app.timezone'))->setTime(6, 0);
                $bills->where('bill_token', (int) $match[1])
                    ->where('created_at', '>=', $start->format('Y-m-d H:i:s'))
                    ->where('created_at', '<', $start->copy()->addDay()->format('Y-m-d H:i:s'));
                $base['counter_window'] = ['from_inclusive' => $start->toIso8601String(),
                    'to_exclusive' => $start->copy()->addDay()->toIso8601String(), 'timezone' => config('app.timezone')];
            } else {
                $bills->where('invoice_number', $v['bill_reference']);
            }
            // Both local and fiscal streams can reuse a daily token. Never pick
            // one by amount, customer, current style, or a nearby timestamp.
            $ids = $bills->orderBy('id')->limit(3)->pluck('id');

            return array_replace($base, ['status' => $ids->isEmpty() ? 'NOT_FOUND' : ($ids->count() === 1 ? 'RESOLVED' : 'AMBIGUOUS'),
                'bill_id' => $ids->count() === 1 ? (int) $ids->first() : null,
                'candidates' => $ids->take(2)->map(fn ($id) => ['bill_id' => (int) $id])->all(), 'has_more' => $ids->count() > 2]);
        } catch (\Illuminate\Database\QueryException) {
            return array_replace($base, ['status' => 'SOURCE_QUERY_FAILED']);
        }
    }
}
