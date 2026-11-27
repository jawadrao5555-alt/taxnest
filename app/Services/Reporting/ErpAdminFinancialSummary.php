<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\HealthBill;
use App\Models\HealthPayment;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Aggregate ERP financial activity without reading or returning patient details. */
final class ErpAdminFinancialSummary
{
    public function forCompanies(Collection $companies, array $filters = []): Collection
    {
        $companies = $companies->filter(fn (Company $company) => NestErps::isProductType($company->product_type));
        if ($companies->isEmpty()) {
            return collect();
        }
        $ids = $this->erpCompanies()->whereIn('id', $companies->pluck('id'));
        $subscriptions = $this->subscriptionRows($ids, $filters, grouped: true);
        $operations = $this->operatingRows($this->healthCompanies(clone $ids), $filters, grouped: true);

        return $companies->mapWithKeys(function (Company $company) use ($subscriptions, $operations) {
            $id = $company->id;
            $vertical = $company->getAttribute(NestErps::VERTICAL_COLUMN);

            return [$id => [
                'subscription' => $this->subscriptionTotals($subscriptions, $id),
                'operations' => in_array($vertical, [NestErps::HEALTH, '', null], true)
                    ? $this->operatingTotals($operations, $id) : null,
            ]];
        });
    }

    public function allCompanyTotals(array $filters = []): array
    {
        $ids = $this->erpCompanies();
        if (isset($filters['company_id'])) {
            $ids->where('id', (int) $filters['company_id']);
        }

        return [
            'subscription' => $this->subscriptionTotals($this->subscriptionRows($ids, $filters, grouped: false)),
            'operations' => $this->operatingTotals($this->operatingRows($this->healthCompanies(clone $ids), $filters, grouped: false)),
        ];
    }

    private function erpCompanies(): Builder
    {
        // Binning a company does not erase its recorded financial history.
        return DB::table('companies')->whereIn('product_type', NestErps::PRODUCT_TYPES)->select('id');
    }

    private function healthCompanies(Builder $companies): Builder
    {
        if (Schema::hasColumn('companies', NestErps::VERTICAL_COLUMN)) {
            $companies->where(fn (Builder $query) => $query->where(NestErps::VERTICAL_COLUMN, NestErps::HEALTH)
                ->orWhere(NestErps::VERTICAL_COLUMN, '')->orWhereNull(NestErps::VERTICAL_COLUMN));
        }

        return $companies;
    }

    private function subscriptionRows(Builder $companies, array $filters, bool $grouped): ?array
    {
        if (isset($filters['branch_id'])
            || !Schema::hasColumns('subscription_invoices', ['id', 'company_id', 'document_number', 'document_snapshot', 'status', 'amount', 'created_at'])
            || !Schema::hasColumns('subscription_payments', ['subscription_invoice_id', 'amount', 'paid_at', 'receipt_number', 'receipt_snapshot'])) {
            return null;
        }
        $packageLedger = SubscriptionInvoice::packageCharge()->whereIn('company_id', $companies);
        $invoices = (clone $packageLedger)
            ->whereNotNull('document_snapshot')->whereNotNull('document_number')->where('document_number', '!=', '')
            ->whereNotIn('status', ['void', 'voided', 'cancelled', 'canceled'])
            ->toBase();
        // Unattested historical rows still appear in company account history.
        // This aggregate requires a numbered receipt and saved reviewer evidence.
        $receipts = SubscriptionPayment::query()
            ->whereNotNull('receipt_number')->where('receipt_number', '!=', '')
            ->where('receipt_snapshot->version', 1)->where('receipt_snapshot->review->admin_id', '>', 0)
            ->where('amount', '>', 0)->whereNotNull('paid_at')
            ->select(['subscription_invoice_id', 'paid_at', 'amount'])
            ->toBase();
        $payments = DB::query()->fromSub($receipts, 'receipt')
            ->joinSub($packageLedger->select(['id', 'company_id'])->toBase(), 'invoice',
                fn ($join) => $join->on('invoice.id', '=', 'receipt.subscription_invoice_id'));

        return [
            $this->sums($this->dates($invoices, 'created_at', $filters), 'company_id', 'COUNT(*) AS invoice_count, SUM(amount) AS invoiced', $grouped),
            $this->sums($this->dates($payments, 'receipt.paid_at', $filters), 'invoice.company_id',
                'COUNT(*) AS receipt_count, SUM(receipt.amount) AS received', $grouped),
        ];
    }

    private function operatingRows(Builder $companies, array $filters, bool $grouped): ?array
    {
        if (!Schema::hasColumns('health_bills', ['company_id', 'branch_id', 'doc_type', 'status', 'bill_date', 'total_amount', 'outstanding_amount'])
            || !Schema::hasColumns('health_payments', ['id', 'company_id', 'branch_id', 'business_date', 'split_from_payment_id', 'kind', 'method', 'amount', 'reversed_at'])) {
            return null;
        }

        $bills = DB::table('health_bills')->whereIn('company_id', $companies)
            ->where('doc_type', HealthBill::TYPE_INVOICE)->whereIn('status', HealthBill::LIVE_STATUSES);
        // A split does not move cash between periods. Restore the parent’s
        // original amount from live linked allocations, and count only the parent.
        $splits = DB::table('health_payments')->whereIn('company_id', $companies)->whereNotNull('split_from_payment_id')
            ->where('kind', HealthPayment::KIND_DEPOSIT)->whereNull('reversed_at')
            ->selectRaw('company_id, split_from_payment_id, SUM(amount) as allocated_amount')
            ->groupBy('company_id', 'split_from_payment_id');
        $payments = DB::table('health_payments as receipt')
            ->leftJoinSub($splits, 'split', fn ($join) => $join->on('split.company_id', '=', 'receipt.company_id')
                ->on('split.split_from_payment_id', '=', 'receipt.id'))
            ->whereIn('receipt.company_id', $companies)
            ->whereNull('receipt.reversed_at')->whereNull('receipt.split_from_payment_id')
            ->whereIn('receipt.method', ['cash', 'card', 'online', 'cheque', 'bank', 'other'])
            ->whereRaw('(receipt.amount + COALESCE(split.allocated_amount, 0)) > 0');

        $this->dates($bills, 'bill_date', $filters);
        $this->dates($payments, 'receipt.business_date', $filters);
        if (isset($filters['branch_id'])) {
            $this->branch($bills, 'branch_id', (int) $filters['branch_id']);
            $this->branch($payments, 'receipt.branch_id', (int) $filters['branch_id']);
        }
        $incomingKinds = implode(', ', array_fill(0, count(HealthPayment::INFLOW_KINDS), '?'));
        $payments->selectRaw(
            "SUM(CASE WHEN receipt.kind IN ($incomingKinds) THEN 1 ELSE 0 END) AS receipt_count,
             SUM(CASE WHEN receipt.kind IN ($incomingKinds) THEN receipt.amount + COALESCE(split.allocated_amount, 0) ELSE 0 END) AS received,
             SUM(CASE WHEN receipt.kind = ? THEN receipt.amount ELSE 0 END) AS refunds",
            [...HealthPayment::INFLOW_KINDS, ...HealthPayment::INFLOW_KINDS, HealthPayment::KIND_REFUND],
        );

        return [
            $this->sums($bills, 'company_id', 'COUNT(*) AS invoice_count, SUM(total_amount) AS invoiced, SUM(outstanding_amount) AS outstanding', $grouped),
            $this->sums($payments, 'receipt.company_id', '', $grouped),
        ];
    }

    private function dates(Builder $query, string $column, array $filters): Builder
    {
        return $query
            ->when(isset($filters['date_from']), fn (Builder $range) => $range->where($column, '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $range) => $range->where(
                $column, '<', CarbonImmutable::parse($filters['date_to'])->addDay()->toDateString(),
            ));
    }

    private function branch(Builder $query, string $column, int $branchId): void
    {
        $query->where(fn (Builder $filter) => $filter->where($column, $branchId)
            ->when($branchId === 0, fn (Builder $legacy) => $legacy->orWhereNull($column)));
    }

    private function sums(Builder $query, string $companyColumn, string $measures, bool $grouped): Collection
    {
        if ($measures !== '') {
            $query->selectRaw($measures);
        }

        return $grouped
            ? $query->addSelect($companyColumn.' as company_id')->groupBy($companyColumn)->get()->keyBy('company_id')
            : $query->get();
    }

    private function subscriptionTotals(?array $results, int|string $companyId = 0): ?array
    {
        if ($results === null) {
            return null;
        }
        [$invoices, $payments] = $results;

        return [
            'invoice_count' => (int) ($invoices->get($companyId)->invoice_count ?? 0),
            'invoiced' => (float) ($invoices->get($companyId)->invoiced ?? 0),
            'receipt_count' => (int) ($payments->get($companyId)->receipt_count ?? 0),
            'received' => (float) ($payments->get($companyId)->received ?? 0),
        ];
    }

    private function operatingTotals(?array $results, int|string $companyId = 0): ?array
    {
        $totals = $this->subscriptionTotals($results, $companyId);
        if ($totals === null) {
            return null;
        }
        $refunds = (float) ($results[1]->get($companyId)->refunds ?? 0);

        return $totals + [
            'refunds' => $refunds,
            'net_received' => round($totals['received'] - $refunds, 2),
            'outstanding' => (float) ($results[0]->get($companyId)->outstanding ?? 0),
        ];
    }
}
