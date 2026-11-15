<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A local cash receipt ledger, deliberately separate from retail fiscal invoices. */
class FbrPosSchoolFeeController extends Controller
{
    private function company(): Company
    {
        $user = Auth::guard('fbrpos')->user();
        abort_unless($user && $user->is_active && ($user->role === 'company_admin'
            || in_array($user->pos_role, ['pos_admin', 'pos_manager', 'pos_cashier'], true)), 403);
        $company = Company::findOrFail($user->company_id);
        abort_unless($company->isSchoolFeePilot(), 404);
        $branches = app(\App\Services\BranchContextService::class);
        $branches->useGuard('fbrpos');
        $campuses = $branches->accessibleBranches();
        // School cash always belongs to one campus, never an owner 'all'
        // reporting scope. Preserve branchless legacy schools, but fail closed
        // when campuses exist and none are authorized for this actor.
        abort_if($this->branchId() !== null && $this->branchId() < 0, 403);
        if (\Illuminate\Support\Facades\Schema::hasTable('branches')
            && \App\Models\Branch::where('company_id', $company->id)->exists()) {
            abort_if($campuses->isEmpty(), 403, __('pos.school_campus_required'));
            if (!$campuses->contains('id', $this->branchId())) {
                abort_unless($branches->setActiveBranch((int) $campuses->first()->id), 403);
            }
        }
        view()->share('schoolCampuses', $campuses);
        view()->share('schoolCampusId', $this->branchId());
        view()->share('schoolCanConfigure', $branches->isOwner());
        return $company;
    }

    private function branchId(): ?int
    {
        return app()->bound('currentBranchId') && app('currentBranchId')
            ? (int) app('currentBranchId') : null;
    }

    private function receipts(Company $company)
    {
        return DB::table('school_fee_receipts')->where('company_id', $company->id)
            ->where('branch_id', $this->branchId());
    }

    private function historyScope(Company $company, Request $request)
    {
        $request->validate(['unassigned' => 'nullable|boolean']);
        $unassigned = $request->boolean('unassigned');
        abort_if($unassigned && !app(\App\Services\BranchContextService::class)->isOwner(), 403);
        return DB::table('school_fee_receipts')->where('company_id', $company->id)
            ->where('branch_id', $unassigned ? null : $this->branchId());
    }

    public function index(?Request $request = null)
    {
        $request ??= request();
        $company = $this->company();
        return response()->view('fbr-pos.school-fees.index', [
            'company' => $company,
            'studentReferences' => $this->receipts($company)->select('student_reference')->distinct()->orderBy('student_reference')->limit(100)->pluck('student_reference'),
            'accounts' => DB::table('school_fee_accounts')->where('company_id', $company->id)->where('branch_id', $this->branchId())->when($request->filled('student_search'), fn ($q) => $q->where(fn ($s) => $s->where('student_reference', 'like', '%'.mb_substr($request->string('student_search'),0,100).'%')->orWhere('student_name', 'like', '%'.mb_substr($request->string('student_search'),0,100).'%')))->orderByDesc('id')->limit(30)->get(),
            'receipts' => $this->receipts($company)->orderByDesc('id')->paginate(20),
            'requestKey' => (string) Str::uuid(),
            'feeHeads' => $this->feeHeads($company)->where('active', true),
            'collectionClosed' => DB::table('school_fee_day_closes')->where('company_id', $company->id)
                ->where('branch_key', $this->branchId() ?? 0)->where('report_date', today()->toDateString())->exists(),
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function dashboard()
    {
        $company = $this->company();
        $date = today()->toDateString();
        $query = $this->receipts($company)->whereDate('collected_at', $date);
        $cashAmountPaisa = (int) (clone $query)->where('payment_method', 'cash')->sum('amount_paisa');
        $receiptCount = (clone $query)->count();
        $closedReport = DB::table('school_fee_day_closes')->where('company_id', $company->id)
            ->where('branch_key', $this->branchId() ?? 0)->where('report_date', $date)->first();
        return response()->view('fbr-pos.school-fees.dashboard', compact(
            'company', 'date', 'cashAmountPaisa', 'receiptCount', 'closedReport'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function switchCampus(Request $request)
    {
        $this->company();
        $data = $request->validate(['branch_id' => 'required|integer|min:1']);
        $branches = app(\App\Services\BranchContextService::class);
        abort_unless($branches->setActiveBranch((int) $data['branch_id']), 403);
        return redirect()->away('/fbr-pos/school-fees');
    }

    public function history(Request $request)
    {
        return $this->cashReport($request, false);
    }

    public function dayClose(Request $request)
    {
        return $this->cashReport($request, true);
    }

    private function cashReport(Request $request, bool $dayClose)
    {
        $company = $this->company();
        if ($dayClose) {
            abort_unless(\App\Services\PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(), $company), 403);
        }
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:100', 'fee_period' => 'nullable|date_format:Y-m']);
        $date = $data['date'] ?? today()->format('Y-m-d');
        // A calendar-day preview only. Do not silently adopt retail Z-close,
        // tax totals or business-day rollover rules for school fee receipts.
        $unassigned = $request->boolean('unassigned');
        $isOwner = app(\App\Services\BranchContextService::class)->isOwner();
        $query = $this->historyScope($company, $request);
        // Search spans dates; closing always uses the entire selected calendar day.
        if ($dayClose || (!$request->filled('search') && !$request->filled('fee_period'))) $query->whereDate('collected_at', $date);
        if (!$dayClose && $request->filled('search')) {
            $search = trim($data['search']);
            $query->where(function ($q) use ($search) {
                $q->where('student_reference', 'like', '%'.$search.'%');
                if (preg_match('/^(?:SF-)?(\d+)$/i', $search, $match)) $q->orWhere('id', $match[1]);
            });
        }
        if (!$dayClose && $request->filled('fee_period')) $query->where('fee_period', $data['fee_period']);
        $cashAmountPaisa = (int) (clone $query)->where('payment_method', 'cash')->sum('amount_paisa');
        $receiptCount = (clone $query)->count();
        $closedReport = DB::table('school_fee_day_closes')->where('company_id', $company->id)
            ->where('branch_key', $unassigned ? 0 : ($this->branchId() ?? 0))->where('report_date', $date)->first();
        if ($dayClose && $closedReport) {
            $cashAmountPaisa = (int) $closedReport->cash_amount_paisa;
            $receiptCount = (int) $closedReport->receipt_count;
        }
        $receipts = $query->orderByDesc('id')->paginate(20)->withQueryString();
        return response()->view('fbr-pos.school-fees.report', compact(
            'company', 'date', 'dayClose', 'unassigned', 'isOwner', 'cashAmountPaisa', 'receiptCount', 'receipts', 'closedReport'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function close(Request $request)
    {
        $company = $this->company();
        $request->validate(['unassigned' => 'nullable|boolean']);
        $unassigned = $request->boolean('unassigned');
        abort_if($unassigned && !app(\App\Services\BranchContextService::class)->isOwner(), 403);
        abort_unless(\App\Services\PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(), $company), 403);
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'confirm' => 'required|accepted',
            'expected_branch_key' => 'required|integer|min:0',
            'expected_cash_paisa' => 'required|integer|min:0',
            'expected_receipt_count' => 'required|integer|min:0',
            'counted_cash' => ['nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'variance_reason' => 'nullable|string|max:500', 'handover_to' => 'nullable|string|max:100', 'handover_note' => 'nullable|string|max:500',
        ]);
        $branchKey = $unassigned ? 0 : ($this->branchId() ?? 0);
        if ((int) $data['expected_branch_key'] !== $branchKey) {
            throw ValidationException::withMessages(['expected_branch_key' => __('pos.school_close_campus_changed')]);
        }
        $report = DB::transaction(function () use ($company, $data, $branchKey, $unassigned) {
            // Same tenant lock as collection: a close either includes a committed
            // payment or precedes it and makes that new collection fail closed.
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $reports = DB::table('school_fee_day_closes')->where('company_id', $company->id)
                ->where('branch_key', $branchKey)->where('report_date', $data['date']);
            if ($existing = (clone $reports)->first()) {
                if (isset($data['counted_cash']) && $existing->counted_cash_paisa !== null
                    && $this->paisa($data['counted_cash']) !== (int) $existing->counted_cash_paisa) {
                    throw ValidationException::withMessages(['counted_cash' => __('school.closed_count_conflict')]);
                }
                foreach (['variance_reason', 'handover_to', 'handover_note'] as $field) {
                    if (isset($data[$field]) && trim($data[$field]) !== (string) $existing->$field) throw ValidationException::withMessages([$field => __('school.closed_count_conflict')]);
                }
                return $existing;
            }
            if (!isset($data['counted_cash'])) throw ValidationException::withMessages(['counted_cash' => __('school.count_required')]);
            $counted = $this->paisa($data['counted_cash']);
            $query = DB::table('school_fee_receipts')->where('company_id', $company->id)
                ->where('branch_id', $unassigned ? null : $this->branchId())
                ->whereDate('collected_at', $data['date']);
            $cash = (int) (clone $query)->where('payment_method', 'cash')->sum('amount_paisa');
            $count = (clone $query)->count();
            if ($cash !== (int) $data['expected_cash_paisa'] || $count !== (int) $data['expected_receipt_count']) {
                throw ValidationException::withMessages(['date' => __('pos.school_close_changed')]);
            }
            if ($counted !== $cash && trim($data['variance_reason'] ?? '') === '') throw ValidationException::withMessages(['variance_reason' => __('school_ledger.variance_required')]);
            $id = DB::table('school_fee_day_closes')->insertGetId([
                'company_id' => $company->id, 'branch_key' => $branchKey, 'report_date' => $data['date'],
                'cash_amount_paisa' => $cash, 'receipt_count' => $count,
                'counted_cash_paisa' => $counted, 'variance_paisa' => $counted - $cash,
                'variance_reason' => trim($data['variance_reason'] ?? '') ?: null, 'handover_to' => trim($data['handover_to'] ?? '') ?: null, 'handover_note' => trim($data['handover_note'] ?? '') ?: null,
                'closed_by' => Auth::guard('fbrpos')->id(), 'closed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('school_fee_day_closes')->find($id);
        }, 3);
        return $request->expectsJson() ? response()->json([
            'success' => true, 'report_id' => $report->id,
            'cash_amount_paisa' => (int) $report->cash_amount_paisa, 'receipt_count' => (int) $report->receipt_count,
            'counted_cash_paisa' => $report->counted_cash_paisa, 'variance_paisa' => $report->variance_paisa,
        ]) : redirect()->away('/fbr-pos/day-close?date='.$data['date'].($unassigned ? '&unassigned=1' : ''));
    }

    /** Read-only recovery: never submit a payment to discover whether it saved. */
    public function recover(Request $request)
    {
        $company = $this->company();
        $data = $request->validate(['request_key' => 'required|uuid', 'expected_branch_key' => 'required|integer|min:0']);
        abort_unless((int) $data['expected_branch_key'] === ($this->branchId() ?? 0), 409);
        $receipt = $this->receipts($company)->where('request_key', $data['request_key'])->first();
        abort_unless($receipt, 404);
        return response()->json(['success' => true, 'receipt_id' => $receipt->id, 'receipt_url' => '/fbr-pos/school-fees/'.$receipt->id.'/receipt'])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $company = $this->company();
        abort_if($request->boolean('unassigned'), 403);
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        $data = $request->validate([
            'request_key' => 'required|uuid',
            'expected_branch_key' => 'required|integer|min:0',
            'student_reference' => 'required|string|max:100',
            'fee_head' => 'nullable|string|max:20',
            'account_id' => 'nullable|integer|min:1', 'student_name' => 'nullable|string|max:100',
            'fee_lines' => 'nullable|array|min:1|max:20', 'fee_lines.*.fee_head' => 'required|string|max:20',
            'fee_lines.*.amount' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'fee_period' => 'nullable|date_format:Y-m',
            'amount' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'payment_method' => 'required|in:cash,card,bank',
        ]);
        $data['student_reference'] = trim($data['student_reference']);
        $data['student_name'] = trim($data['student_name'] ?? '');
        $lines = $data['fee_lines'] ?? [['fee_head' => $data['fee_head'] ?? '', 'amount' => $data['amount']]];
        $lineInputs = array_map(fn ($line) => ['fee_head' => $line['fee_head'], 'amount_paisa' => $this->paisa($line['amount'])], $lines);
        if (count(array_unique(array_column($lineInputs, 'fee_head'))) !== count($lineInputs) || min(array_column($lineInputs, 'amount_paisa')) <= 0) throw ValidationException::withMessages(['fee_lines' => __('school_ledger.invalid_lines')]);
        $data['fee_head'] = count($lineInputs) === 1 ? $lineInputs[0]['fee_head'] : 'multiple';
        [$whole, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
        $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($amount <= 0 || $data['student_reference'] === '') {
            throw ValidationException::withMessages(['amount' => __('pos.school_invalid_collection')]);
        }
        $branchId = $this->branchId();
        // Another tab may have changed the shared campus session since this
        // form was rendered. Never silently collect or replay in that campus.
        if ((int) $data['expected_branch_key'] !== ($branchId ?? 0)) {
            throw ValidationException::withMessages(['expected_branch_key' => __('pos.school_campus_changed')]);
        }
        $cashConfirmed = $request->boolean('cash_confirmed');
        // Lock the tenant row to serialize concurrent requests; unique key is the
        // durable backstop. A lost response/repeated click cannot collect twice.
        $id = DB::transaction(function () use ($company, $data, $amount, $branchId, $cashConfirmed, $lineInputs) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('school_fee_receipts')->where('company_id', $company->id)
                ->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->student_reference !== $data['student_reference']
                    || $existing->fee_head !== $data['fee_head']
                    || ($existing->fee_period !== null && $existing->fee_period !== ($data['fee_period'] ?? null))
                    || ($existing->payment_method ?? 'cash') !== $data['payment_method']
                    || ($existing->student_name ?? '') !== $data['student_name']
                    || (($existing->requested_account_id === null ? null : (int) $existing->requested_account_id) !== (empty($data['account_id']) ? null : (int) $data['account_id']))
                    || ($existing->fee_lines && array_map(fn ($line) => ['fee_head' => $line['fee_head'], 'amount_paisa' => $line['amount_paisa']], json_decode($existing->fee_lines, true)) !== $lineInputs)
                    || (int) $existing->amount_paisa !== $amount
                    || ($existing->branch_id === null ? null : (int) $existing->branch_id) !== $branchId) {
                    throw ValidationException::withMessages(['request_key' => __('pos.school_retry_conflict')]);
                }
                return $existing->id;
            }
            if (!isset($data['fee_period'])) throw ValidationException::withMessages(['fee_period' => __('school.period_required')]);
            $account = null;
            if (!empty($data['account_id'])) {
                $account = DB::table('school_fee_accounts')->where('company_id', $company->id)->where('branch_id', $branchId)->where('id', $data['account_id'])->first();
                abort_unless($account, 404);
                $savedLines = json_decode($account->fee_lines, true);
                if ($account->student_reference !== $data['student_reference'] || ($account->student_name ?? '') !== $data['student_name'] || $account->fee_period !== $data['fee_period'] || array_map(fn ($line) => ['fee_head' => $line['fee_head'], 'amount_paisa' => $line['amount_paisa']], $savedLines) !== $lineInputs) throw ValidationException::withMessages(['account_id' => __('pos.school_retry_conflict')]);
            } else {
                $savedLines = [];
                foreach ($lineInputs as $line) {
                    $head = $this->feeHeads($company)->get($line['fee_head']);
                    if (!$head || !$head['active']) throw ValidationException::withMessages(['fee_head' => __('school.head_unavailable')]);
                    $savedLines[] = $line + ['label' => $head['label']];
                }
            }
            $payable = $account ? (int) $account->payable_paisa : array_sum(array_column($lineInputs, 'amount_paisa'));
            $paid = ($account ? (int) $account->paid_paisa : 0) + $amount;
            if ($paid > $payable) throw ValidationException::withMessages(['amount' => __('school_ledger.overpayment')]);
            // The operator confirms actual cash only for a new payment.
            // A lost-response replay remains retrievable without reconfirming.
            if (!$cashConfirmed) {
                throw ValidationException::withMessages(['cash_confirmed' => __('school_ledger.confirm_error')]);
            }
            $collectedAt = now();
            $closed = DB::table('school_fee_day_closes')->where('company_id', $company->id)
                ->where('branch_key', $branchId ?? 0)->where('report_date', $collectedAt->toDateString())->exists();
            if ($closed) {
                throw ValidationException::withMessages(['amount' => __('pos.school_closed_collection')]);
            }
            // Recheck under the tenant lock: two tabs cannot overshoot the cap.
            $access = \App\Services\SubscriptionAccessService::hasAccess($company);
            abort_unless($access['allowed'] ?? false, 403, $access['reason'] ?? 'Collection access denied.');
            $limit = \App\Services\PlanLimitService::canCreateFbrPosBill($company->id);
            abort_unless($limit['allowed'] ?? false, 403, $limit['reason'] ?? 'Collection limit reached.');
            if (!$account) {
                $accountId = DB::table('school_fee_accounts')->insertGetId([
                    'company_id' => $company->id, 'branch_id' => $branchId, 'student_reference' => $data['student_reference'],
                    'student_name' => $data['student_name'] ?: null, 'fee_period' => $data['fee_period'], 'fee_lines' => json_encode($savedLines),
                    'payable_paisa' => $payable, 'paid_paisa' => $paid, 'created_at' => $collectedAt, 'updated_at' => $collectedAt,
                ]);
            } else {
                $accountId = $account->id;
                DB::table('school_fee_accounts')->where('id', $accountId)->update(['paid_paisa' => $paid, 'updated_at' => $collectedAt]);
            }
            return DB::table('school_fee_receipts')->insertGetId([
                'account_id' => $accountId, 'requested_account_id' => $data['account_id'] ?? null, 'student_name' => $data['student_name'] ?: null, 'payment_method' => $data['payment_method'],
                'fee_lines' => json_encode($savedLines), 'payable_paisa' => $payable, 'paid_paisa' => $paid, 'balance_paisa' => $payable - $paid,
                'company_id' => $company->id, 'branch_id' => $branchId,
                'collected_by' => Auth::guard('fbrpos')->id(),
                'request_key' => $data['request_key'],
                'student_reference' => $data['student_reference'],
                'fee_head' => $data['fee_head'], 'fee_head_label' => Str::limit(implode(' + ', array_column($savedLines, 'label')), 80, ''),
                'fee_period' => $data['fee_period'], 'amount_paisa' => $amount,
                'campus_name' => $branchId ? (\Illuminate\Support\Facades\Schema::hasTable('branches') ? \App\Models\Branch::where('company_id', $company->id)->whereKey($branchId)->value('name') : null) : __('school.no_campus'),
                'cashier_name' => Auth::guard('fbrpos')->user()->name,
                'collected_at' => $collectedAt, 'created_at' => $collectedAt, 'updated_at' => $collectedAt,
            ]);
        }, 3);
        $url = '/fbr-pos/school-fees/'.$id.'/receipt';
        return $request->expectsJson()
            ? response()->json(['success' => true, 'receipt_id' => $id, 'receipt_url' => $url])
            : redirect()->away($url);
    }

    private function paisa(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function feeHeads(Company $company)
    {
        $heads = collect(['tuition', 'admission', 'exam'])->mapWithKeys(fn ($code) => [
            $code => ['code' => $code, 'label' => __('pos.school_head_'.$code), 'active' => true],
        ]);
        foreach (DB::table('school_fee_heads')->where('company_id', $company->id)->orderBy('id')->get() as $head) {
            $heads->put($head->code, ['code' => $head->code, 'label' => $head->label, 'active' => (bool) $head->active]);
        }
        return $heads;
    }

    private function configurationCompany(): Company
    {
        $company = $this->company();
        $user = Auth::guard('fbrpos')->user();
        abort_unless(app(\App\Services\BranchContextService::class)->isOwner($user), 403);
        return $company;
    }

    public function settings()
    {
        $company = $this->configurationCompany();
        return response()->view('fbr-pos.school-fees.settings', [
            'company' => $company, 'feeHeads' => $this->feeHeads($company),
            'campuses' => \App\Models\Branch::where('company_id', $company->id)->orderBy('name')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function saveFeeHead(Request $request)
    {
        $company = $this->configurationCompany();
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        $data = $request->validate(['code' => 'nullable|string|max:20', 'label' => 'required|string|max:80', 'active' => 'required|boolean']);
        $label = trim($data['label']);
        if ($label === '') throw ValidationException::withMessages(['label' => __('school.label_required')]);
        DB::transaction(function () use ($company, $data, $label) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $code = ($data['code'] ?? null) ?: null;
            if ($code && !$this->feeHeads($company)->has($code)) abort(404);
            $code ??= 'c'.strtolower(Str::random(12));
            DB::table('school_fee_heads')->updateOrInsert(['company_id' => $company->id, 'code' => $code], [
                'label' => $label, 'active' => (bool) $data['active'], 'updated_at' => now(),
            ]);
        });
        return redirect()->away('/fbr-pos/school-fees/settings');
    }

    public function addCampus(Request $request)
    {
        $company = $this->configurationCompany();
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'city' => 'nullable|string|max:100', 'address' => 'nullable|string|max:1000']);
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') throw ValidationException::withMessages(['name' => __('school.label_required')]);
        DB::transaction(function () use ($company, $data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $limit = \App\Services\PlanLimitService::canAddBranch($company->id);
            if (!($limit['allowed'] ?? false)) throw ValidationException::withMessages(['name' => $limit['reason'] ?? __('school.campus_limit')]);
            if (\App\Models\Branch::where('company_id', $company->id)->where('name', $data['name'])->exists()) {
                throw ValidationException::withMessages(['name' => __('school.campus_duplicate')]);
            }
            \App\Models\Branch::create([
                'company_id' => $company->id, 'name' => $data['name'], 'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null, 'is_active' => true,
                'is_head_office' => !\App\Models\Branch::where('company_id', $company->id)->exists(),
            ]);
        });
        return redirect()->away('/fbr-pos/school-fees/settings');
    }

    /** Read-only archive: never reinterpret old retail rows as school fees. */
    private function legacyQuery(Company $company, Request $request)
    {
        $unassigned = $request->boolean('unassigned');
        abort_if($unassigned && !app(\App\Services\BranchContextService::class)->isOwner(), 403);
        return \App\Models\FbrPosTransaction::where('company_id', $company->id)
            ->where('branch_id', $unassigned ? null : $this->branchId());
    }

    private function legacyPinKey(Company $company): string
    {
        return 'school_legacy_pin.'.$company->id.'.'.Auth::guard('fbrpos')->id();
    }

    private function legacyPinAllowed(Company $company): bool
    {
        if (empty($company->confidential_pin)) return true;
        $proof = session($this->legacyPinKey($company), []);
        return isset($proof['at'], $proof['hash'])
            && $proof['at'] <= now()->timestamp && now()->timestamp - $proof['at'] < 1800
            && hash_equals(hash('sha256', $company->confidential_pin), (string) $proof['hash']);
    }

    public function legacyUnlock(Request $request)
    {
        // Detect stale impersonation/panel context before company() canonicalizes
        // the branch service to the authenticated FBR user.
        $user = Auth::guard('fbrpos')->user();
        abort_unless($user && app()->bound('currentCompanyId')
            && (int) app('currentCompanyId') === (int) $user->company_id, 403);
        $company = $this->company();
        abort_unless(app()->bound('currentCompanyId')
            && (int) app('currentCompanyId') === (int) $company->id, 403);
        $request->validate(['pin' => 'required|string|max:32']);
        // Reuse the existing tenant-level lockout; never grant archive access
        // merely because an unrelated panel left a global verified flag.
        $previous = [];
        foreach (['fbr_pos_pin_verified', 'fbr_pos_pin_verified_at'] as $key) {
            $previous[$key] = ['exists' => session()->exists($key), 'value' => session($key)];
        }
        try {
            $response = app(FbrPosController::class)->verifyPin($request);
        } finally {
            // The archive unlock must not grant access in the retail panel.
            foreach ($previous as $key => $saved) {
                if ($saved['exists']) session([$key => $saved['value']]);
                else session()->forget($key);
            }
        }
        $result = $response->getData(true);
        if (!($result['success'] ?? false)) {
            return redirect()->away('/fbr-pos/school-fees/legacy-retail')
                ->withErrors(['pin' => $result['message'] ?? __('pos.school_legacy_locked')]);
        }
        session([$this->legacyPinKey($company) => [
            'at' => now()->timestamp, 'hash' => hash('sha256', (string) $company->confidential_pin),
        ]]);
        return redirect()->away('/fbr-pos/school-fees/legacy-retail');
    }

    public function legacyIndex(Request $request)
    {
        $company = $this->company();
        $request->validate(['search' => 'nullable|string|max:100', 'unassigned' => 'nullable|boolean', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $localAllowed = $this->legacyPinAllowed($company);
        $query = $this->legacyQuery($company, $request);
        if (!$localAllowed) {
            $query->where(fn ($q) => $q->where('invoice_mode', '!=', 'local')->orWhereNull('invoice_mode'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->where(fn ($q) => $q->where('invoice_number', 'like', $search)
                ->orWhere('fbr_invoice_number', 'like', $search));
        }
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->input('to'));
        $archiveSummary = ['count' => (clone $query)->count(), 'total' => (clone $query)->sum('total_amount'), 'tax' => (clone $query)->sum('tax_amount')];
        $transactions = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $unassigned = $request->boolean('unassigned');
        $isOwner = app(\App\Services\BranchContextService::class)->isOwner();
        return response()->view('fbr-pos.school-fees.legacy-index', compact(
            'company', 'transactions', 'localAllowed', 'unassigned', 'isOwner', 'archiveSummary'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function legacyShow(Request $request, int $id)
    {
        $company = $this->company();
        $request->validate(['unassigned' => 'nullable|boolean']);
        $transaction = $this->legacyQuery($company, $request)->with('items')->findOrFail($id);
        abort_if($transaction->invoice_mode === 'local' && !$this->legacyPinAllowed($company),
            403, __('pos.school_legacy_locked'));
        return response()->view('fbr-pos.school-fees.legacy-show', compact('company', 'transaction'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function receipt(int $id)
    {
        $company = $this->company();
        $receipt = $this->historyScope($company, request())->where('id', $id)->first();
        abort_unless($receipt, 404);
        return response()->view('fbr-pos.school-fees.receipt', compact('company', 'receipt'))
            ->header('Cache-Control', 'private, no-store');
    }
}
