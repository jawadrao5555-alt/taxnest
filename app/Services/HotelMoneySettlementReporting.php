<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\HotelCreditNote;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosDayCloseReport;
use App\Support\PosPaymentBuckets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Full Hotel invoices record sales; dated folio settlements record actual money. */
class HotelMoneySettlementReporting
{
    public static function stamp(HotelStay $stay, array $data, int $userId): array
    {
        if (!$stay->hotel_money_from_folio || !in_array($data['entry_type'] ?? '', ['payment', 'refund'], true)) {
            return [];
        }
        if (!Schema::hasColumn('hotel_folio_entries', 'settlement_business_date')) {
            throw new HotelStayException('Hotel settlement migration is required.');
        }
        $terminal = (int) ($data['terminal_id'] ?? 0);
        abort_unless($terminal >= 0 && ($terminal === 0 || \App\Models\PosTerminal::where('company_id', $stay->company_id)
            ->where('is_active', true)->whereKey($terminal)->exists()), 422);
        $date = PosBusinessDay::forMoment((int) $stay->company_id, now());
        if (PosDayCloseReport::where('company_id', $stay->company_id)->whereDate('report_date', $date)
            ->whereIn('branch_id', [0, (int) ($stay->branch_id ?? 0)])->exists()
            || (($data['payment_method'] ?? 'cash') === 'cash' && PosCounterDrawer::isClosed((int) $stay->company_id, $terminal, $date))) {
            throw new HotelStayException('This payment day or drawer is closed.');
        }
        $billIds = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->whereNotNull('pos_transaction_id')->select('pos_transaction_id');
        $bill = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
            ->whereIn('id', $billIds)->where('transaction_type', 'sale')->latest('id')->first();
        $company = \App\Models\Company::findOrFail($stay->company_id);
        $actor = \App\Models\User::find($userId);
        $pra = $bill ? (!empty($bill->pra_invoice_number) || !in_array($bill->pra_status, [null, 'local'], true))
            : ($company->pos_integration_mode !== 'standalone' && (bool) $actor?->praReportingEnabled($company));
        // Settlement stream is distinct from the legacy Hotel invoice_mode='pra' contract.
        return ['settlement_business_date' => $date, 'settlement_branch_id' => $stay->branch_id,
            'settlement_terminal_id' => $terminal, 'settlement_invoice_mode' => $pra ? 'pra' : 'local'];
    }

    public static function rows(int $companyId, string $date, ?int $branchId = null, ?int $creator = null): Collection
    {
        if (!Schema::hasColumn('hotel_folio_entries', 'settlement_business_date')) return collect();
        $query = HotelFolioEntry::where('company_id', $companyId)->whereIn('entry_type', ['payment', 'refund'])
            ->whereDate('settlement_business_date', $date)
            ->whereIn('stay_id', HotelStay::where('company_id', $companyId)->where('hotel_money_from_folio', true)->select('id'))
            ->when($branchId, fn ($q) => $q->where(fn ($b) => $b->where('settlement_branch_id', $branchId)->orWhereNull('settlement_branch_id')))
            ->when($creator, fn ($q) => $q->where('created_by', $creator));
        if (Schema::hasTable('hotel_credit_notes')) {
            // Credit-note refunds have their own settlement record; count exactly once.
            $query->where(fn ($q) => $q->whereNull('pos_transaction_id')->orWhereNotIn('pos_transaction_id',
                HotelCreditNote::where('company_id', $companyId)->select('credit_transaction_id')));
        }
        return $query->get();
    }

    /** Signed outflow: collection is negative, refund is positive. Sales remain unchanged. */
    public static function outflows(int $companyId, string $date, ?int $branchId = null, ?int $creator = null): array
    {
        $sums = [];
        $online = array_merge(\App\Support\PosPaymentLabels::ONLINE_ALIASES, ['bank_transfer']);
        $rows = self::rows($companyId, $date, $branchId, $creator);
        if ($rows->isNotEmpty()) $sums['_movements'] = $rows->count();
        foreach ($rows as $row) {
            $method = $row->payment_method ?: 'cash';
            $amount = (float) $row->amount * ($row->entry_type === 'payment' ? -1 : 1);
            $bucket = PosPaymentBuckets::bucket($method);
            $sums[$bucket] = ($sums[$bucket] ?? 0) + $amount;
            if ($row->settlement_invoice_mode === 'local') {
                $key = 'local_'.$bucket;
                $sums[$key] = ($sums[$key] ?? 0) + $amount;
            }
            if (in_array($method, $online, true)) $sums['online'] = ($sums['online'] ?? 0) + $amount;
        }
        return $sums;
    }

    public static function cashByDrawer(int $companyId, string $date, ?int $branchId = null, ?int $creator = null): array
    {
        return self::rows($companyId, $date, $branchId, $creator)->filter(fn ($r) => ($r->payment_method ?: 'cash') === 'cash')
            ->groupBy(fn ($r) => (int) ($r->settlement_terminal_id ?? 0))
            ->map(fn ($rows) => round((float) $rows->sum(fn ($r) => (float) $r->amount * ($r->entry_type === 'payment' ? -1 : 1)), 2))->all();
    }
}
