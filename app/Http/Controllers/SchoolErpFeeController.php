<?php

namespace App\Http\Controllers;

use App\Services\SchoolErpFeeLedger;
use App\Services\SchoolErpWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SchoolErpFeeController extends Controller
{
    public function index(Request $request, SchoolErpWorkspace $workspace, SchoolErpFeeLedger $ledger)
    {
        $company = $request->attributes->get('schoolErpCompany');
        $user = $request->attributes->get('schoolErpUser');
        $campuses = $workspace->campuses($user);
        $selection = $request->validate(['campus_id' => ['nullable', 'integer', Rule::in($campuses->pluck('id')->all())]]);
        $campusId = isset($selection['campus_id']) ? (int) $selection['campus_id'] : null;
        $payments = $ledger->receivedPayments($company->id)->selectRaw('invoice_id, SUM(amount_paisa) AS paid_paisa')
            ->groupBy('invoice_id');
        $query = $workspace->scopedRecords($user, 'school_erp_fee_invoices', $campusId);
        $invoices = (clone $query)
            ->join('school_erp_fee_schedules as schedule', 'schedule.id', '=', 'school_erp_fee_invoices.schedule_id')
            ->join('school_erp_students as student', 'student.id', '=', 'school_erp_fee_invoices.student_id')
            ->leftJoinSub($payments, 'received', 'received.invoice_id', '=', 'school_erp_fee_invoices.id')
            ->orderByDesc('school_erp_fee_invoices.id')->paginate(30, [
                'school_erp_fee_invoices.*', 'schedule.label', 'schedule.due_on',
                'student.student_reference', 'student.display_name', 'received.paid_paisa',
            ])->withQueryString();
        $totals = (clone $query)->leftJoinSub($payments, 'received', 'received.invoice_id', '=', 'school_erp_fee_invoices.id')
            ->selectRaw('COALESCE(SUM(school_erp_fee_invoices.amount_paisa), 0) AS invoiced, COALESCE(SUM(received.paid_paisa), 0) AS received')
            ->first();
        $receipts = DB::table('school_erp_fee_payments as payment')
            ->leftJoin('school_erp_fee_reversals as reversal', function ($join) {
                $join->on('reversal.company_id', '=', 'payment.company_id')->on('reversal.payment_id', '=', 'payment.id');
            })
            ->where('payment.company_id', $company->id)->whereIn('payment.invoice_id', $invoices->getCollection()->pluck('id'))
            ->orderBy('payment.id')->get(['payment.*', 'reversal.reason as reversal_reason'])->groupBy('invoice_id');
        $canWrite = $user->isCompanyAdmin() && $workspace->mayWrite($company);
        $terms = $canWrite ? DB::table('school_erp_terms')->where('company_id', $company->id)->orderByDesc('starts_on')->get() : collect();
        $schedules = $canWrite ? DB::table('school_erp_fee_schedules')->where('company_id', $company->id)->orderByDesc('due_on')->get() : collect();

        return view('school-erp.fees', compact('company', 'campuses', 'campusId', 'invoices', 'totals', 'receipts', 'canWrite', 'terms', 'schedules'));
    }

    public function schedule(Request $request, SchoolErpFeeLedger $ledger)
    {
        $data = $request->validate([
            'term_id' => 'required|integer|min:1', 'label' => 'required|string|max:100',
            'amount' => 'required|string|max:12', 'due_on' => 'required|date_format:Y-m-d',
        ]);
        $ledger->schedule($request->attributes->get('schoolErpUser'), $data);

        return to_route('school-erp.fees')->with('status', __('school_erp_fees.schedule_saved'));
    }

    public function issue(Request $request, SchoolErpFeeLedger $ledger)
    {
        $actor = $request->attributes->get('schoolErpUser');
        abort_unless($actor->isCompanyAdmin(), 403);
        $data = $request->validate(['student_reference' => 'required|string|max:64', 'schedule_id' => 'required|integer|min:1']);
        $studentId = DB::table('school_erp_students')->where('company_id', $request->attributes->get('schoolErpCompany')->id)
            ->where('student_reference', $data['student_reference'])->value('id');
        if (!$studentId) {
            throw ValidationException::withMessages(['student_reference' => __('school_erp_fees.enrollment_required')]);
        }
        $invoiceId = $ledger->issue($actor, (int) $studentId, (int) $data['schedule_id']);

        return redirect(route('school-erp.fees').'#fee-'.$invoiceId)->with('status', __('school_erp_fees.invoice_saved'));
    }

    public function receive(Request $request, int $invoice, SchoolErpFeeLedger $ledger)
    {
        $data = $request->validate([
            'request_key' => 'required|uuid', 'amount' => 'required|string|max:12',
            'method' => 'required|in:cash,bank,card', 'reference' => 'required_unless:method,cash|nullable|string|max:100',
        ]);
        $ledger->receive($request->attributes->get('schoolErpUser'), $invoice, $data);

        return redirect(route('school-erp.fees').'#fee-'.$invoice)->with('status', __('school_erp_fees.receipt_saved'));
    }

    public function reverse(Request $request, int $invoice, int $receipt, SchoolErpFeeLedger $ledger)
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $ledger->reverse($request->attributes->get('schoolErpUser'), $invoice, $receipt, $data['reason']);

        return redirect(route('school-erp.fees').'#fee-'.$invoice)->with('status', __('school_erp_fees.receipt_reversed'));
    }

    public function receipt(Request $request, int $receipt, SchoolErpWorkspace $workspace)
    {
        $company = $request->attributes->get('schoolErpCompany');
        $user = $request->attributes->get('schoolErpUser');
        $record = $workspace->scopedRecords($user, 'school_erp_fee_invoices')
            ->join('school_erp_fee_payments as payment', 'payment.invoice_id', '=', 'school_erp_fee_invoices.id')
            ->join('school_erp_students as student', 'student.id', '=', 'school_erp_fee_invoices.student_id')
            ->join('school_erp_fee_schedules as schedule', 'schedule.id', '=', 'school_erp_fee_invoices.schedule_id')
            ->leftJoin('school_erp_fee_reversals as reversal', 'reversal.payment_id', '=', 'payment.id')
            ->where('payment.company_id', $company->id)->where('payment.id', $receipt)
            ->first(['payment.*', 'student.display_name', 'student.student_reference', 'schedule.label',
                'schedule.due_on', 'reversal.reason as reversal_reason']);
        abort_unless($record, 404);

        return view('school-erp.fee-receipt', compact('company', 'record'));
    }
}
