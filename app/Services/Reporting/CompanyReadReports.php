<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Explicit SELECT-only reports. No fiscal API, job dispatch, raw query input or customer/device credentials. */
final class CompanyReadReports
{
    public function companies(string $name, ?array $allowed): array
    {
        $q = DB::table('companies')->whereIn('product_type', ['pos', 'fbrpos', 'di']);
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($allowed !== null) {
            $q->whereIn('id', $allowed);
        }
        $term = mb_strtolower(trim($name));
        if ($term !== '') {
            $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%']);
        }

        return $q->orderBy('name')->limit(11)->get(['id', 'name', 'product_type'])->map(fn ($r) => (array) $r)->all();
    }

    public function company(string $name, ?array $allowed, ?int $id = null): object
    {
        $q = DB::table('companies')->whereIn('product_type', ['pos', 'fbrpos', 'di'])->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($allowed !== null) {
            $q->whereIn('id', $allowed);
        }
        if ($id !== null) {
            $chosen = $q->where('id', $id)->first(['id', 'name', 'product_type']);
            if (! $chosen) {
                throw ValidationException::withMessages(['company_id' => 'Company ID/name do not match or are outside this connection’s scope.']);
            }

            return $chosen;
        }
        $exact = $q->limit(2)->get(['id', 'name', 'product_type'])->map(fn ($r) => (array) $r)->all();
        $matches = $this->companies($name, $allowed);
        $chosen = count($exact) === 1 ? $exact : (count($matches) === 1 ? $matches : []);
        if (! $chosen) {
            throw ValidationException::withMessages(['company_name' => count($matches) ? 'Company name is ambiguous. Choose an exact name from find_companies.' : 'Company not found or outside this connection’s scope.']);
        }

        return (object) $chosen[0];
    }

    public function context(array $args, ?array $allowed): array
    {
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150', 'company_id' => 'nullable|integer|min:1', 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d', 'date_basis' => 'nullable|in:calendar,business', 'branch_id' => 'nullable|integer|min:1', 'terminal_id' => 'nullable|integer|min:1', 'payment_method' => 'nullable|string|max:32'])->validate();
        $tz = config('reporting_connector.timezone');
        $today = Carbon::now($tz)->toDateString();
        $from = Carbon::parse($v['date_from'] ?? $today, $tz)->startOfDay();
        $to = Carbon::parse($v['date_to'] ?? $v['date_from'] ?? $today, $tz)->startOfDay();
        if ($from->gt($to) || $from->diffInDays($to) > config('reporting_connector.max_days') - 1) {
            throw ValidationException::withMessages(['date_from' => 'Use an ordered date range of at most 93 days.']);
        }
        $company = $this->company($v['company_name'], $allowed, isset($v['company_id']) ? (int) $v['company_id'] : null);
        $d = match ($company->product_type) {
            'pos' => ['table' => 'pos_transactions', 'items' => 'pos_transaction_items', 'fk' => 'transaction_id', 'fiscal' => 'pra_status', 'number' => 'pra_invoice_number', 'authority' => 'PRA', 'tax' => 'tax_amount'],
            'fbrpos' => ['table' => 'fbr_pos_transactions', 'items' => 'fbr_pos_transaction_items', 'fk' => 'transaction_id', 'fiscal' => 'fbr_status', 'number' => 'fbr_invoice_number', 'authority' => 'FBR POS', 'tax' => 'tax_amount'],
            'di' => ['table' => 'invoices', 'items' => 'invoice_items', 'fk' => 'invoice_id', 'fiscal' => 'fbr_status', 'number' => 'fbr_invoice_number', 'authority' => 'FBR DI', 'tax' => 'total_sales_tax']
        };
        if (! Schema::hasTable($d['table'])) {
            throw ValidationException::withMessages(['report' => 'Billing table is unavailable; no zero total can be inferred.']);
        }
        $basis = $v['date_basis'] ?? 'calendar';
        if ($basis === 'business' && ($company->product_type === 'di' || ! Schema::hasColumn($d['table'], 'business_date'))) {
            throw ValidationException::withMessages(['date_basis' => 'Business-date evidence is unavailable for this module. Use calendar dates.']);
        }
        $q = DB::table($d['table'].' as b')->where('b.company_id', $company->id);
        foreach (['branch_id', 'terminal_id', 'payment_method'] as $filter) {
            if (isset($v[$filter])) {
                if (! Schema::hasColumn($d['table'], $filter)) {
                    throw ValidationException::withMessages([$filter => 'This stored filter is unavailable for this module.']);
                }
                $q->where('b.'.$filter, $v[$filter]);
            }
        }

        $calendarBounds = [$from->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), $to->copy()->endOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')];
        if ($basis === 'business') {
            $q->whereBetween('b.business_date', [$from->toDateString(), $to->toDateString()]);
        } else {
            $q->whereBetween('b.created_at', $calendarBounds);
        }
        $meta = ['company' => ['id' => $company->id, 'name' => $company->name, 'product' => $company->product_type], 'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => $basis, 'timezone' => $tz],
            'filters' => array_intersect_key($v, array_flip(['branch_id', 'terminal_id', 'payment_method'])), 'as_of' => Carbon::now($tz)->toIso8601String(), 'currency' => 'PKR', 'freshness' => 'Live database SELECT at request time; no GitHub approval or cached report', 'authority' => $d['authority']];

        return compact('company', 'd', 'q', 'meta', 'calendarBounds');
    }

    private function completed(array $c)
    {
        $q = clone $c['q'];

        return $q->where('b.status', $c['company']->product_type === 'di' ? 'locked' : 'completed');
    }

    private function returnExpr(array $c): string
    {
        return $c['company']->product_type === 'di' && Schema::hasColumn($c['d']['table'], 'document_type') ? "COALESCE(b.document_type, '') = 'Credit Note'" :
         (Schema::hasColumn($c['d']['table'], 'transaction_type') ? "COALESCE(b.transaction_type, '') = 'return'" : '1 = 0');
    }

    private function reportedExpr(array $c): string
    {
        $t = $c['d']['table'];
        $f = $c['d']['fiscal'];
        $n = $c['d']['number'];
        $states = $c['company']->product_type === 'di' ? "'production'" : "'submitted'";

        return Schema::hasColumn($t, $f) && Schema::hasColumn($t, $n) ? "COALESCE(b.$f,'') IN ($states) AND b.$n IS NOT NULL AND TRIM(b.$n) <> ''" : '1 = 0';
    }

    public function summary(array $args, ?array $allowed): array
    {
        $c = $this->context($args, $allowed);
        $q = $this->completed($c);
        $ret = $this->returnExpr($c);
        $reported = $this->reportedExpr($c);
        $tax = $c['d']['tax'];
        $taxAvailable = Schema::hasColumn($c['d']['table'], $tax);
        $taxExpr = $taxAvailable ? 'COALESCE(b.'.$tax.',0)' : '0';
        $unknownExpr = $taxAvailable ? 'SUM(CASE WHEN b.'.$tax.' IS NULL THEN 1 ELSE 0 END)' : 'COUNT(*)';
        $sum = (clone $q)->selectRaw("$unknownExpr AS tax_unknown_documents, "."COUNT(*) AS completed_documents, SUM(CASE WHEN $ret THEN 0 ELSE 1 END) AS sales_documents, SUM(CASE WHEN $ret THEN 1 ELSE 0 END) AS return_documents, COALESCE(SUM(CASE WHEN $ret THEN 0 ELSE b.total_amount END),0) AS sales_total,
   COALESCE(SUM(CASE WHEN $ret THEN b.total_amount ELSE 0 END),0) AS return_total,
   COALESCE(SUM(CASE WHEN $ret THEN -1*($taxExpr) ELSE $taxExpr END),0) AS net_tax,
   COALESCE(SUM(CASE WHEN $reported THEN (CASE WHEN $ret THEN -b.total_amount ELSE b.total_amount END) ELSE 0 END),0) AS reported_net_amount, COALESCE(SUM(CASE WHEN $reported THEN 0 ELSE (CASE WHEN $ret THEN -b.total_amount ELSE b.total_amount END) END),0) AS unreported_net_amount, SUM(CASE WHEN $reported THEN 1 ELSE 0 END) AS reported_count, SUM(CASE WHEN $reported THEN 0 ELSE 1 END) AS unreported_count")->first();
        $states = Schema::hasColumn($c['d']['table'], $c['d']['fiscal']) ? (clone $q)->selectRaw('COALESCE(b.'.$c['d']['fiscal'].", 'reporting_off_or_not_recorded') AS fiscal_state, COUNT(*) AS bills, COALESCE(SUM(b.total_amount),0) AS gross_document_amount, COALESCE(SUM(CASE WHEN $ret THEN -b.total_amount ELSE b.total_amount END),0) AS net_document_amount")->groupBy('b.'.$c['d']['fiscal'])->get() : collect();
        $statuses = (clone $c['q'])->selectRaw('b.status AS document_status, COUNT(*) AS bills')->groupBy('b.status')->get();
        $payments = Schema::hasColumn($c['d']['table'], 'payment_method') ? (clone $q)->selectRaw("COALESCE(b.payment_method,'not_recorded') AS method, COUNT(*) AS bills, COALESCE(SUM(CASE WHEN $ret THEN 0 ELSE b.total_amount END),0) AS sales_invoice_value, COALESCE(SUM(CASE WHEN $ret THEN b.total_amount ELSE 0 END),0) AS return_invoice_value, COALESCE(SUM(CASE WHEN $ret THEN -b.total_amount ELSE b.total_amount END),0) AS net_invoice_value")->groupBy('b.payment_method')->get() : collect();

        return $c['meta'] + ['totals' => ['completed_documents' => (int) $sum->completed_documents, 'sales_documents' => (int) $sum->sales_documents, 'return_documents' => (int) $sum->return_documents, 'sales_total' => round((float) $sum->sales_total, 2), 'return_credit_note_total' => round((float) $sum->return_total, 2),
            'net_billing' => round((float) $sum->sales_total - (float) $sum->return_total, 2), 'tax_unknown_documents' => (int) $sum->tax_unknown_documents, 'net_tax' => $taxAvailable && (int) $sum->tax_unknown_documents === 0 ? round((float) $sum->net_tax, 2) : null,
            'reported_net_amount' => round((float) $sum->reported_net_amount, 2), 'unreported_net_amount' => round((float) $sum->unreported_net_amount, 2), 'reported_bills' => (int) $sum->reported_count, 'unreported_bills' => (int) $sum->unreported_count],
            'fiscal_states' => $states, 'document_statuses' => $statuses, 'payment_methods' => $payments,
            'tax_items' => $this->taxSummary($c), 'notes' => ['Sales tax fields are stored bill tax; withholding, levy and additional tax evidence is item-level and must not be conflated with net sales tax.', 'DI billing totals include locked documents across stored environments; fiscal_states separates production and sandbox. Sandbox is not production revenue evidence.', 'DI production submissions count as reported; sandbox and simulated documents never count as production reporting.', 'Completed POS / locked DI documents only in financial totals; cancelled/draft status counts shown separately.',
                'Returns/credit notes shown separately and subtracted once. Reported means stored accepted status plus a nonempty fiscal number; it is not independent central authority verification.',
                'Payment method amounts are invoice values, not proof of money actually collected. Item rows and quantities are separate; zero-rate is not automatically exempt.']];
    }

    private function itemQuery(array $c)
    {
        $d = $c['d'];

        return DB::table($d['items'].' as i')->joinSub($this->completed($c)->select('b.*'), 'b', fn ($j) => $j->on('b.id', '=', 'i.'.$d['fk']));
    }

    private function itemFields(array $c): array
    {
        $di = $c['company']->product_type === 'di';
        $t = $c['d']['items'];
        $tax = $di ? 'tax' : 'tax_amount';
        if (! Schema::hasTable($t) || ! Schema::hasColumn($t, $tax) || ! Schema::hasColumn($t, 'tax_rate')) {
            return [];
        }
        $hasClass = Schema::hasColumn($t, $di ? 'schedule_type' : 'is_tax_exempt');
        $exempt = $di ? ($hasClass ? "i.schedule_type = 'exempt'" : '1=0') : ($hasClass ? 'i.is_tax_exempt = 1' : '1=0');
        $zero = $di ? ($hasClass ? "i.schedule_type = 'zero_rated' AND i.tax_rate = 0" : '1=0') : ($hasClass ? 'i.is_tax_exempt = 0 AND i.tax_rate = 0' : '1=0');
        $class = "CASE WHEN $exempt THEN 'exempt' WHEN i.tax_rate > 0 THEN 'taxable' WHEN $zero THEN 'zero_rate' ELSE 'unknown' END";

        return ['tax' => $tax, 'description' => $di ? 'description' : 'item_name', 'class' => $class];
    }

    private function taxSummary(array $c): array
    {
        $f = $this->itemFields($c);
        if (! $f) {
            return ['available' => false, 'reason' => 'Stored item tax classification is unavailable.'];
        }
        $ret = $this->returnExpr($c);
        // Classify scoped rows first; MariaDB ONLY_FULL_GROUP_BY rejects a
        // repeated CASE expression over ungrouped source columns.
        $rows = $this->itemQuery($c)->select('i.quantity', 'i.'.$f['tax'].' as stored_item_tax')
            ->selectRaw($f['class'].' AS classification')
            ->selectRaw("CASE WHEN $ret THEN 1 ELSE 0 END AS is_return");
        $groups = DB::query()->fromSub($rows, 'e')->selectRaw('e.classification, COUNT(*) AS item_rows,
            SUM(CASE WHEN e.is_return=1 THEN 0 ELSE 1 END) AS sales_item_rows,
            SUM(CASE WHEN e.is_return=1 THEN 1 ELSE 0 END) AS return_item_rows,
            COALESCE(SUM(e.quantity),0) AS unsigned_quantity,
            SUM(CASE WHEN e.stored_item_tax IS NULL THEN 1 ELSE 0 END) AS tax_unknown_rows,
            CASE WHEN SUM(CASE WHEN e.stored_item_tax IS NULL THEN 1 ELSE 0 END)>0 THEN NULL
            ELSE COALESCE(SUM(CASE WHEN e.is_return=1 THEN -e.stored_item_tax ELSE e.stored_item_tax END),0) END AS net_item_tax')
            ->groupBy('e.classification')->get();

        return ['available' => true, 'groups' => $groups, 'note' => 'Counts are invoice item rows; catalogue item count and mixed unit quantities are not equivalent. Stored tax classifications are used, not guessed from zero tax. Quantities are unsigned and may mix units; use paginated tax item evidence for per-unit inspection.'];
    }

    public function bills(array $args, ?array $allowed): array
    {
        $c = $this->context($args, $allowed);
        $v = validator($args, ['reported' => 'nullable|in:reported,unreported,any', 'status' => 'nullable|string|max:32', 'fiscal_status' => 'nullable|string|max:40', 'kind' => 'nullable|in:sale,return,any', 'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $q = clone $c['q'];
        $reported = $this->reportedExpr($c);
        $ret = $this->returnExpr($c);
        if (($v['reported'] ?? 'any') !== 'any') {
            $q->whereRaw(($v['reported'] === 'unreported' ? 'NOT (' : '(').$reported.')');
        }
        if (! empty($v['status'])) {
            $q->where('b.status', $v['status']);
        }
        if (! empty($v['fiscal_status']) && Schema::hasColumn($c['d']['table'], $c['d']['fiscal'])) {
            $q->where('b.'.$c['d']['fiscal'], $v['fiscal_status']);
        }
        if (($v['kind'] ?? 'any') !== 'any') {
            $q->whereRaw(($v['kind'] === 'sale' ? 'NOT (' : '(').$ret.')');
        }
        $q->where('b.id', '>', (int) ($v['after_id'] ?? 0));
        $limit = (int) ($v['limit'] ?? 25);
        $records = $q->orderBy('b.id')->limit($limit + 1)->get();

        return $c['meta'] + ['bills' => $records->take($limit)->map(fn ($b) => $this->bill($b, $c))->all(), 'has_more' => $records->count() > $limit, 'next_after_id' => $records->count() > $limit ? $records[$limit - 1]->id : null];
    }

    private function bill(object $b, array $c): array
    {
        $f = $c['d']['fiscal'];
        $n = $c['d']['number'];
        $di = $c['company']->product_type === 'di';

        return ['id' => $b->id, 'invoice_number' => $b->invoice_number ?? $b->internal_invoice_number ?? null, 'date' => $b->created_at, 'status' => $b->status,
            'kind' => $di ? (($b->document_type ?? '') === 'Credit Note' ? 'return' : 'sale') : (($b->transaction_type ?? '') === 'return' ? 'return' : 'sale'),
            'invoice_amount' => isset($b->total_amount) ? (float) $b->total_amount : null, 'tax_amount' => isset($b->{$c['d']['tax']}) ? (float) $b->{$c['d']['tax']} : null,
            'document_type' => $b->document_type ?? null, 'business_date' => $b->business_date ?? null, 'branch_id' => $b->branch_id ?? null, 'terminal_id' => $b->terminal_id ?? null, 'payment_method' => $b->payment_method ?? null, 'fiscal_status' => $b->$f ?? null, 'fiscal_number' => $b->$n ?? null,
            'reported' => in_array($b->$f ?? null, $di ? ['production'] : ['submitted'], true) && trim((string) ($b->$n ?? '')) !== ''];
    }

    public function detail(array $args, ?array $allowed): array
    {
        $c = $this->context($args, $allowed);
        $v = validator($args, ['bill_id' => 'required|integer|min:1', 'after_item_id' => 'nullable|integer|min:0'])->validate();
        $b = (clone $c['q'])->where('b.id', $v['bill_id'])->first();
        if (! $b) {
            throw ValidationException::withMessages(['bill_id' => 'Bill not found for this company and period.']);
        }
        $f = $this->itemFields($c);
        $items = [];
        $more = false;
        $next = null;
        if ($f) {
            $records = DB::table($c['d']['items'].' as i')->where('i.'.$c['d']['fk'], $b->id)->where('i.id', '>', (int) ($v['after_item_id'] ?? 0))->orderBy('i.id')->limit(101)->select('i.id', 'i.'.$f['description'].' as description', 'i.quantity', 'i.tax_rate', 'i.'.$f['tax'].' as tax_amount')->selectRaw($f['class'].' AS classification')->addSelect($this->optionalItemColumns($c))->get();
            $more = $records->count() > 100;
            $items = $records->take(100)->all();
            $next = $more ? $records[99]->id : null;
        }

        return $c['meta'] + ['bill' => $this->bill($b, $c), 'items' => $items, 'items_available' => (bool) $f, 'has_more_items' => $more, 'next_after_item_id' => $next];
    }

    public function operations(array $args, ?array $allowed): array
    {
        $c = $this->context($args, $allowed);
        $id = $c['company']->id;
        $t = $c['company']->product_type === 'pos' ? 'pos_products' : 'products';
        $stock = null;
        $inventory = null;
        if (Schema::hasTable('inventory_stocks')) {
            $inventory = DB::table('inventory_stocks')->where('company_id', $id)->selectRaw('COUNT(*) AS branch_stock_rows, SUM(CASE WHEN quantity<=0 THEN 1 ELSE 0 END) AS zero_or_negative_rows')->first();
        }
        if ($c['company']->product_type !== 'di' && Schema::hasTable($t) && Schema::hasColumn($t, 'stock_quantity')) {
            $stock = DB::table($t)->where('company_id', $id)->selectRaw('COUNT(*) AS catalogue_items, SUM(CASE WHEN stock_quantity<=0 THEN 1 ELSE 0 END) AS zero_or_negative_stock_items')->first();
        }
        $printing = Schema::hasTable('pos_print_jobs') ? DB::table('pos_print_jobs')->where('company_id', $id)->whereBetween('created_at', $c['calendarBounds'])->selectRaw('status, COUNT(*) AS jobs')->groupBy('status')->get() : null;
        $agent = null;
        if (Schema::hasTable('pos_agent_devices')) {
            $agent = DB::table('pos_agent_devices')->where('company_id', $id)->limit(100)->get(array_values(array_filter(['name', 'hostname', 'agent_version', 'last_seen_at'], fn ($f) => Schema::hasColumn('pos_agent_devices', $f))));
        }

        return $c['meta'] + ['legacy_product_stock_summary' => $stock, 'branch_inventory_summary' => $inventory, 'print_job_states' => $printing, 'agents' => $agent, 'agent_limit' => 100, 'print_period_basis' => 'calendar created_at', 'stock_basis' => 'Current stored stock snapshot, not a historical stock valuation', 'note' => 'Null means unavailable, not zero. Print-job success is not proof of physical paper. Agent timestamps are server records, not a connectivity guarantee.'];
    }

    private function optionalItemColumns(array $c): array
    {
        $fields = $c['company']->product_type === 'di' ? ['price', 'hs_code', 'default_uom', 'schedule_type', 'mrp', 'further_tax', 'st_withheld_amount', 'petroleum_levy'] : ['unit_price', 'subtotal', 'total', 'discount', 'item_discount', 'hs_code', 'uom', 'is_third_schedule'];

        return array_map(fn ($f) => 'i.'.$f, array_values(array_filter($fields, fn ($f) => Schema::hasColumn($c['d']['items'], $f))));
    }

    public function taxItems(array $args, ?array $allowed): array
    {
        $c = $this->context($args, $allowed);
        $v = validator($args, ['classification' => 'nullable|in:exempt,taxable,zero_rate,unknown,any', 'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $f = $this->itemFields($c);
        if (! $f) {
            return $c['meta'] + ['available' => false, 'reason' => 'Stored item tax evidence unavailable.'];
        }
        $q = $this->itemQuery($c)->where('i.id', '>', (int) ($v['after_id'] ?? 0));
        if (($v['classification'] ?? 'any') !== 'any') {
            $q->whereRaw('('.$f['class'].') = ?', [$v['classification']]);
        }
        $limit = (int) ($v['limit'] ?? 25);
        $ret = $this->returnExpr($c);
        $rows = $q->orderBy('i.id')->limit($limit + 1)->select('i.id', 'b.id as bill_id', 'i.'.$f['description'].' as description', 'i.quantity', 'i.tax_rate', 'i.'.$f['tax'].' as tax_amount')
            ->selectRaw($f['class'].' AS classification')->selectRaw("CASE WHEN $ret THEN 'return' ELSE 'sale' END AS document_kind")->addSelect($this->optionalItemColumns($c))->get();

        return $c['meta'] + ['available' => true, 'items' => $rows->take($limit)->all(), 'has_more' => $rows->count() > $limit, 'next_after_id' => $rows->count() > $limit ? $rows[$limit - 1]->id : null];
    }

    public function payments(array $args, ?array $allowed): array
    {
        $c = $this->context($args, $allowed);
        $ret = $this->returnExpr($c);
        $t = $c['d']['table'];
        $cash = null;
        $ledger = null;
        if (Schema::hasColumn($t, 'cash_received') && Schema::hasColumn($t, 'change_due')) {
            $cash = $this->completed($c)->selectRaw("COALESCE(SUM(CASE WHEN $ret THEN 0 ELSE cash_received END),0) AS stored_sale_cash_received, COALESCE(SUM(CASE WHEN $ret THEN 0 ELSE change_due END),0) AS stored_sale_change_due, COALESCE(SUM(CASE WHEN $ret THEN cash_received ELSE 0 END),0) AS stored_return_cash_received")->first();
        }
        if ($c['company']->product_type === 'pos' && Schema::hasTable('hotel_folio_entries')) {
            $q = DB::table('hotel_folio_entries')->where('company_id', $c['company']->id)->whereIn('entry_type', ['payment', 'refund', 'deposit', 'deposit_refund']);
            if ($c['meta']['period']['basis'] === 'business') {
                if (! Schema::hasColumn('hotel_folio_entries', 'settlement_business_date') || ! Schema::hasColumn('hotel_folio_entries', 'refund_business_date')) {
                    $q = null;
                } else {
                    $q->whereRaw("CASE WHEN entry_type = 'refund' THEN COALESCE(refund_business_date,settlement_business_date) ELSE settlement_business_date END BETWEEN ? AND ?", [$c['meta']['period']['from'], $c['meta']['period']['to']]);
                }
            } else {
                $q->whereBetween('created_at', $c['calendarBounds']);
            }
            if ($q) {
                $ledger = $q->selectRaw("entry_type, COALESCE(payment_method,'not_recorded') AS method, COUNT(*) AS movements, COALESCE(SUM(amount),0) AS stored_amount")->groupBy('entry_type', 'payment_method')->get();
            }
        }

        return $c['meta'] + ['stored_pos_cash_fields' => $cash, 'hotel_folio_movements' => $ledger, 'actual_collected_total' => null,
            'notes' => ['These are separate stored evidence streams, never add them together: a hotel payment can accompany a POS invoice.',
                'A credit note adjusts billing; it does not prove a refund. Refund and deposit movements are separate. Cash fields alone do not prove card, split, credit or hotel balances. No universal collected total is inferred.',
                'Null means the source is unavailable or does not establish a reliable universal total.']];
    }
}
