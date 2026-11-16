<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class SchoolFeeReportController extends Controller
{
    private function context(): array
    {
        $user = Auth::guard('fbrpos')->user();
        abort_unless($user && $user->is_active && ($user->role === 'company_admin'
            || in_array($user->pos_role, ['pos_admin', 'pos_manager', 'pos_cashier'], true)), 403);

        $company = Company::findOrFail($user->company_id);
        abort_unless($company->isSchoolFeePilot(), 404);

        $branches = app(\App\Services\BranchContextService::class);
        $branches->useGuard('fbrpos');
        $accessible = $branches->accessibleBranches();
        $owner = $branches->isOwner($user);
        $active = app()->bound('currentBranchId') && app('currentBranchId') ? (int) app('currentBranchId') : null;

        view()->share('schoolCampuses', $accessible);
        view()->share('schoolCampusId', $active);
        view()->share('schoolCanConfigure', $owner);

        return [$company, $accessible, $owner, $active];
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'preset' => 'nullable|in:daily,monthly,half_yearly,yearly,two_year,custom,all',
            'from' => 'nullable|required_if:preset,custom|date_format:Y-m-d',
            'to' => 'nullable|required_if:preset,custom|date_format:Y-m-d|after_or_equal:from',
            'payment_method' => 'nullable|in:cash,card,bank',
            'fee_head' => 'nullable|string|max:20|regex:/^[a-zA-Z0-9_]+$/',
            'campus' => 'nullable|string|max:30',
            'cashier' => 'nullable|string|max:100',
            'fbr_status' => 'nullable|in:accepted,pending,failed,off',
            'fiscal_number' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $preset = $data['preset'] ?? 'monthly';
        $today = CarbonImmutable::today();
        [$from, $to] = match ($preset) {
            'daily' => [$today, $today],
            'monthly' => [$today->startOfMonth(), $today->endOfMonth()],
            'half_yearly' => $today->month <= 6
                ? [$today->startOfYear(), $today->startOfYear()->addMonths(5)->endOfMonth()]
                : [$today->startOfYear()->addMonths(6), $today->endOfYear()],
            'yearly' => [$today->startOfYear(), $today->endOfYear()],
            'two_year' => [$today->subYear()->startOfYear(), $today->endOfYear()],
            'custom' => [CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to'])],
            'all' => [null, null],
        };

        $data['preset'] = $preset;
        $data['from'] = $from?->toDateString();
        $data['to'] = $to?->toDateString();
        return $data;
    }

    private function query(Company $company, $accessible, bool $owner, ?int $active, array $filters): Builder
    {
        $query = DB::table('school_fee_receipts')->where('company_id', $company->id);
        $accessibleIds = $accessible->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (!$owner) {
            if ($active !== null) {
                abort_unless(in_array($active, $accessibleIds, true), 403);
                $query->where('branch_id', $active);
            } else {
                abort_unless($accessibleIds === [], 403);
                $query->whereNull('branch_id');
            }
        } elseif (($filters['campus'] ?? '') === 'all') {
            if ($accessibleIds !== []) {
                $query->where(fn ($scope) => $scope->whereIn('branch_id', $accessibleIds)->orWhereNull('branch_id'));
            } else {
                $query->whereNull('branch_id');
            }
        } elseif (($filters['campus'] ?? '') === 'unassigned') {
            $query->whereNull('branch_id');
        } else {
            $campus = ctype_digit((string) ($filters['campus'] ?? '')) ? (int) $filters['campus'] : $active;
            if ($campus !== null) {
                abort_unless(in_array($campus, $accessibleIds, true), 403);
                $query->where('branch_id', $campus);
            } else {
                $query->whereNull('branch_id');
            }
        }

        if ($filters['from']) $query->whereDate('collected_at', '>=', $filters['from']);
        if ($filters['to']) $query->whereDate('collected_at', '<=', $filters['to']);
        if (!empty($filters['payment_method'])) $query->where('payment_method', $filters['payment_method']);
        if (!empty($filters['cashier'])) $query->where('cashier_name', 'like', '%'.trim($filters['cashier']).'%');
        if (!empty($filters['fiscal_number'])) $query->where('fbr_invoice_number', 'like', '%'.trim($filters['fiscal_number']).'%');
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('student_reference', 'like', '%'.$search.'%')
                    ->orWhere('student_name', 'like', '%'.$search.'%');
                if (preg_match('/^(?:SF-)?(\d+)$/i', $search, $match)) $q->orWhere('id', (int) $match[1]);
            });
        }
        if (!empty($filters['fee_head'])) {
            $head = $filters['fee_head'];
            $query->where(function ($q) use ($head) {
                $q->where('fee_head', $head);
                if (DB::getDriverName() === 'sqlite') {
                    $q->orWhereRaw("exists (select 1 from json_each(school_fee_receipts.fee_lines) line where json_extract(line.value, '$.fee_head') = ?)", [$head]);
                } else {
                    $q->orWhereJsonContains('fee_lines', ['fee_head' => $head]);
                }
            });
        }
        match ($filters['fbr_status'] ?? null) {
            'accepted' => $query->where('fbr_status', 'submitted')->whereNotNull('fbr_invoice_number'),
            'pending' => $query->whereIn('fbr_status', ['pending', 'processing', 'uncertain']),
            'failed' => $query->whereIn('fbr_status', ['failed', 'rejected']),
            'off' => $query->whereNull('fbr_pos_transaction_id'),
            default => null,
        };

        return $query;
    }

    private function summary(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'total_paisa' => (int) (clone $query)->sum('amount_paisa'),
            'cash_paisa' => (int) (clone $query)->where('payment_method', 'cash')->sum('amount_paisa'),
            'card_paisa' => (int) (clone $query)->where('payment_method', 'card')->sum('amount_paisa'),
            'bank_paisa' => (int) (clone $query)->where('payment_method', 'bank')->sum('amount_paisa'),
            'accepted' => (clone $query)->where('fbr_status', 'submitted')->whereNotNull('fbr_invoice_number')->count(),
            'pending' => (clone $query)->whereIn('fbr_status', ['pending', 'processing', 'uncertain'])->count(),
            'failed' => (clone $query)->whereIn('fbr_status', ['failed', 'rejected'])->count(),
        ];
    }

    private function data(Request $request): array
    {
        [$company, $campuses, $owner, $active] = $this->context();
        $filters = $this->filters($request);
        $query = $this->query($company, $campuses, $owner, $active, $filters);
        return compact('company', 'campuses', 'owner', 'active', 'filters', 'query');
    }

    public function index(Request $request)
    {
        $data = $this->data($request);
        $data['summary'] = $this->summary($data['query']);
        $data['receipts'] = $data['query']->orderByDesc('collected_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();
        $data['feeHeads'] = DB::table('school_fee_heads')->where('company_id', $data['company']->id)
            ->where('active', true)->orderBy('label')->get();
        unset($data['query']);
        return response()->view('fbr-pos.school-fees.reports', $data)
            ->header('Cache-Control', 'private, no-store');
    }

    public function csv(Request $request)
    {
        $data = $this->data($request);
        $query = $data['query']->orderBy('id');
        $filename = 'school-fee-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Receipt', 'Date', 'Student reference', 'Student name', 'Fee period', 'Fee head', 'Payment method', 'Payment reference', 'Amount PKR', 'Campus', 'Cashier', 'IMS status', 'Fiscal number']);
            $query->chunkById(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    $safe = fn ($value) => preg_match('/^[=+\-@]/', (string) $value) ? "'".$value : $value;
                    fputcsv($out, [
                        'SF-'.$row->id, $row->collected_at, $safe($row->student_reference), $safe($row->student_name ?? ''),
                        $row->fee_period, $safe($row->fee_head_label ?? $row->fee_head), $row->payment_method,
                        $safe($row->payment_reference ?? ''), number_format($row->amount_paisa / 100, 2, '.', ''),
                        $safe($row->campus_name ?? ''), $safe($row->cashier_name ?? ''), $row->fbr_status ?? 'off',
                        $safe($row->fbr_invoice_number ?? ''),
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function pdf(Request $request)
    {
        $data = $this->data($request);
        $data['summary'] = $this->summary($data['query']);
        // Deliberately no application-level date or row cap: the chosen report
        // range is exported in full. CSV remains the preferred very-large export.
        $data['receipts'] = $data['query']->orderBy('collected_at')->orderBy('id')->get();
        unset($data['query']);
        \App\Support\PosLocale::applyPdfSafeLocale();
        return Pdf::loadView('fbr-pos.school-fees.reports-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->download('school-fee-report-'.now()->format('Ymd-His').'.pdf');
    }
}
