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

    public function index()
    {
        $company = $this->company();
        return response()->view('fbr-pos.school-fees.index', [
            'company' => $company,
            'receipts' => $this->receipts($company)->orderByDesc('id')->paginate(20),
            'requestKey' => (string) Str::uuid(),
        ])->withHeaders(['Cache-Control' => 'no-store']);
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
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $data['date'] ?? today()->format('Y-m-d');
        // A calendar-day preview only. Do not silently adopt retail Z-close,
        // tax totals or business-day rollover rules for school fee receipts.
        $query = $this->receipts($company)->whereDate('collected_at', $date);
        $cashAmountPaisa = (int) (clone $query)->sum('amount_paisa');
        $receiptCount = (clone $query)->count();
        $receipts = $query->orderByDesc('id')->paginate(20)->withQueryString();
        return response()->view('fbr-pos.school-fees.report', compact(
            'company', 'date', 'dayClose', 'cashAmountPaisa', 'receiptCount', 'receipts'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $company = $this->company();
        abort_if((bool) $request->session()->get('impersonation.readonly'), 403);
        $data = $request->validate([
            'request_key' => 'required|uuid',
            'student_reference' => 'required|string|max:100',
            'fee_head' => 'required|in:tuition,admission,exam',
            'amount' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'payment_method' => 'required|in:cash',
        ]);
        $data['student_reference'] = trim($data['student_reference']);
        [$whole, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
        $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($amount <= 0 || $data['student_reference'] === '') {
            throw ValidationException::withMessages(['amount' => __('pos.school_invalid_collection')]);
        }
        $branchId = $this->branchId();
        // Lock the tenant row to serialize concurrent requests; unique key is the
        // durable backstop. A lost response/repeated click cannot collect twice.
        $id = DB::transaction(function () use ($company, $data, $amount, $branchId) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('school_fee_receipts')->where('company_id', $company->id)
                ->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->student_reference !== $data['student_reference']
                    || $existing->fee_head !== $data['fee_head']
                    || (int) $existing->amount_paisa !== $amount
                    || ($existing->branch_id === null ? null : (int) $existing->branch_id) !== $branchId) {
                    throw ValidationException::withMessages(['request_key' => __('pos.school_retry_conflict')]);
                }
                return $existing->id;
            }
            // Recheck under the tenant lock: two tabs cannot overshoot the cap.
            $access = \App\Services\SubscriptionAccessService::hasAccess($company);
            abort_unless($access['allowed'] ?? false, 403, $access['reason'] ?? 'Collection access denied.');
            $limit = \App\Services\PlanLimitService::canCreateFbrPosBill($company->id);
            abort_unless($limit['allowed'] ?? false, 403, $limit['reason'] ?? 'Collection limit reached.');
            return DB::table('school_fee_receipts')->insertGetId([
                'company_id' => $company->id, 'branch_id' => $branchId,
                'collected_by' => Auth::guard('fbrpos')->id(),
                'request_key' => $data['request_key'],
                'student_reference' => $data['student_reference'],
                'fee_head' => $data['fee_head'], 'amount_paisa' => $amount,
                'collected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        $url = '/fbr-pos/school-fees/'.$id.'/receipt';
        return $request->expectsJson()
            ? response()->json(['success' => true, 'receipt_id' => $id, 'receipt_url' => $url])
            : redirect()->away($url);
    }

    public function receipt(int $id)
    {
        $company = $this->company();
        $receipt = $this->receipts($company)->where('id', $id)->first();
        abort_unless($receipt, 404);
        return response()->view('fbr-pos.school-fees.receipt', compact('company', 'receipt'))
            ->header('Cache-Control', 'private, no-store');
    }
}
