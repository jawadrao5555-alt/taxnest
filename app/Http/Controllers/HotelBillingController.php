<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use App\Services\BranchContextService;
use App\Services\HotelAccessService;
use App\Services\HotelBillingDirectory;
use Illuminate\Http\Request;

/** One Hotel invoice directory; canonical POS tax filters/netting remain authoritative. */
class HotelBillingController extends PosController
{
    private string $stream = 'all';

    private function prepare(Request $request): void
    {
        HotelAccessService::abortUnlessFrontDesk(auth('pos')->user());
        $actor = auth('pos')->user();
        $requested = $request->query('stream', $request->query('tab', 'all'));
        $this->stream = in_array($requested, ['all', 'pra', 'local'], true) ? $requested : 'all';
        // Local stream stays owner/admin-only and honors the saved hide-local switch.
        if ((!$actor->isPosAdmin() || $actor->posHidesLocalStream()) && $this->stream !== 'pra') {
            $this->stream = 'pra';
        }
        $request->validate([
            'date_from' => 'nullable|date', 'date_to' => 'nullable|date',
            'customer' => 'nullable|string|max:255', 'room' => 'nullable|string|max:80',
            'invoice' => 'nullable|string|max:120',
            'pra_status' => 'nullable|in:submitted,pending,failed,offline,local',
            'payment_state' => 'nullable|in:paid,due',
            'tax_rate' => 'nullable|regex:/^(exempt|[0-9]{1,3}(\.[0-9]{1,2})?)$/',
        ]);
    }

    public function index(Request $request)
    {
        $this->prepare($request);
        $data = parent::taxReports($request)->getData();
        $all = $this->buildTaxReportQuery($request, $data['tab'], true)->get();
        $directory = app(HotelBillingDirectory::class)->describe($all);
        $data += ['directory' => $directory, 'stream' => $this->stream];
        return view('pos.hotel.bills', $data);
    }

    protected function applyReportFilters($query, $tab, $cashierFilter = null, $forUser = null)
    {
        app(BranchContextService::class)->applyToQuery($query, 'branch_id');
        $query->withoutGlobalScope('hide_archived');
        if ($this->stream !== 'all') PosTransaction::applyStreamTab($query, $this->stream);
        $actor = auth('pos')->user();
        $query->where(function ($scope) use ($actor) {
            PosTransaction::applyBillingScopeFilter($scope, $actor->posBillingScope());
            if ($actor->posBillingScopeIsDerived() && $actor->posBillingScope() !== 'both') {
                $scope->orWhere('created_by', $actor->id);
            }
        });
        PosTransaction::applyCashierIsolation($query, $actor);
        return $query;
    }

    protected function buildTaxReportQuery(Request $request, $tab = 'pra', $skipTaxRateFilter = false)
    {
        $query = parent::buildTaxReportQuery($request, $tab, $skipTaxRateFilter);
        $companyId = (int) app('currentCompanyId');
        $branchId = app(BranchContextService::class)->getActiveBranchId();
        // EXISTS avoids multiplying an invoice by its folio lines.
        $hotelInvoice = function ($q) use ($companyId, $branchId, $request) {
            $q->selectRaw('1')->from('hotel_folio_entries as hfe')
                ->join('hotel_stays as hs', 'hs.id', '=', 'hfe.stay_id')
                ->whereColumn('hfe.pos_transaction_id', 'pos_transactions.id')
                ->where('hfe.company_id', $companyId)->where('hs.company_id', $companyId)
                ->whereIn('hfe.entry_type', ['charge', 'adjustment'])
                ->when($branchId, fn ($s) => $s->where('hs.branch_id', $branchId));
            if ($request->filled('room')) {
                $q->whereExists(fn ($r) => $r->selectRaw('1')->from('hotel_rooms as hr')
                    ->whereColumn('hr.id', 'hs.room_id')->where('hr.company_id', $companyId)
                    ->where('hr.room_number', \App\Helpers\DbCompat::like(), '%'.$request->room.'%'));
            }
        };
        if (\App\Services\HotelShell::isNativeCategory(Company::find($companyId)) && !$request->filled('room')) {
            // Tax Reports must retain standalone outlet/counter bills in a Hotel company.
            // Linked Hotel invoices still obey the stay's branch; never turn a hidden
            // other-branch stay invoice into an apparently standalone POS invoice.
            $query->where(function ($scope) use ($hotelInvoice, $companyId) {
                $scope->whereExists($hotelInvoice)->orWhereNotExists(function ($q) use ($companyId) {
                    $q->selectRaw('1')->from('hotel_folio_entries as linked_folio')
                        ->whereColumn('linked_folio.pos_transaction_id', 'pos_transactions.id')
                        ->where('linked_folio.company_id', $companyId)
                        ->whereIn('linked_folio.entry_type', ['charge', 'adjustment']);
                });
            });
        } else {
            $query->whereExists($hotelInvoice);
        }
        if ($request->filled('invoice')) {
            $query->where('invoice_number', \App\Helpers\DbCompat::like(), '%'.$request->invoice.'%');
        }
        if ($request->filled('pra_status')) {
            if ($request->pra_status === 'local') PosTransaction::applyStreamTab($query, 'local');
            elseif ($request->pra_status === 'submitted') $query->where('pra_status', 'submitted')->whereNotNull('pra_invoice_number');
            else $query->where('pra_status', $request->pra_status);
        }
        if ($request->filled('payment_state')) {
            $details = app(HotelBillingDirectory::class)->describe((clone $query)->get());
            $ids = collect($details['rows'])->filter(fn ($row) => $row['money'] !== null
                && ($request->payment_state === 'due' ? $row['money']['balance'] > 0.009 : $row['money']['balance'] <= 0.009))->keys();
            $query->whereIn('id', $ids);
        }
        return $query;
    }

    public function csv(Request $request)
    {
        $this->prepare($request);
        if ($r = $this->planGate('reports_enabled')) return $r;
        [$data] = $this->buildTaxReportPdfData($request);
        $directory = app(HotelBillingDirectory::class)->describe($data['transactions']);
        return response()->streamDownload(function () use ($data, $directory) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Invoice', 'Type', 'Date', 'Stay', 'Guest', 'Room', 'Payment Method', 'PRA Fiscal Number', 'PRA Status', 'Tax Base', 'Tax', 'Total', 'Stay Paid (net refunds)', 'Stay Due (cash estimate)']);
            foreach ($data['transactions'] as $t) {
                $row = $directory['rows'][$t->id] ?? null;
                $return = $t->transaction_type === 'return';
                $sign = $return && $data['billTypeFilter'] !== 'returns' ? -1 : 1;
                $iv = $data['itemValues'][$t->id] ?? null;
                $base = $data['taxRateFilter'] ? ($iv['item_subtotal'] ?? 0) : $t->subtotal - $t->discount_amount;
                $tax = $data['taxRateFilter'] ? ($iv['item_tax'] ?? 0) : $t->tax_amount;
                $total = $data['taxRateFilter'] ? $base + $tax : $t->total_amount;
                $safe = fn ($s) => preg_match('/^[=+\-@\t\r]/u', (string) $s) ? "'".$s : $s;
                fputcsv($f, [$safe($t->invoice_number), $return ? 'Credit Note' : 'Sale', $t->created_at->format('Y-m-d H:i'),
                    $safe($row['stay']->stay_number ?? ''), $safe($row['stay']->guest_name ?? $t->customer_name),
                    $safe($row['stay']->room?->room_number ?? ''), \App\Support\PosPaymentLabels::label($t->payment_method),
                    $safe($t->pra_invoice_number ?? ''), $t->pra_status ?? 'local',
                    number_format($sign * $base, 2, '.', ''), number_format($sign * $tax, 2, '.', ''), number_format($sign * $total, 2, '.', ''),
                    $row && $row['money'] !== null ? number_format($row['money']['paid'], 2, '.', '') : '',
                    $row && $row['money'] !== null ? number_format($row['money']['balance'], 2, '.', '') : '']);
            }
            fputcsv($f, []);
            fputcsv($f, ['Filtered net total', number_format($data['summary']->total_sales, 2, '.', '')]);
            fputcsv($f, ['Filtered net tax', number_format($data['summary']->total_tax, 2, '.', '')]);
            fputcsv($f, ['Stay payment columns repeat for the same stay; do not add invoice rows. Security deposits are excluded.']);
            fclose($f);
        }, 'Hotel_Bills_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request)
    {
        $this->prepare($request);
        if ($r = $this->planGate('reports_enabled')) return $r;
        [$data, $filename] = $this->buildTaxReportPdfData($request);
        $data['directory'] = app(HotelBillingDirectory::class)->describe($data['transactions']);
        $data['stream'] = $this->stream;
        return $this->renderReportPdf('pos.hotel.bills-pdf', $data, $filename, 'landscape');
    }
}
