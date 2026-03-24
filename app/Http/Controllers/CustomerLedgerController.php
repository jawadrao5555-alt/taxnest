<?php

namespace App\Http\Controllers;

use App\Models\CustomerLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerLedgerController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');

        $customers = CustomerLedger::where('company_id', $companyId)
            ->select(
                'customer_name',
                'customer_ntn',
                DB::raw('SUM(debit) as total_invoiced'),
                DB::raw('SUM(credit) as total_received'),
                DB::raw('SUM(debit) - SUM(credit) as outstanding')
            )
            ->groupBy('customer_name', 'customer_ntn')
            ->orderBy('customer_name')
            ->get();

        return view('customers.index', compact('customers'));
    }

    public function show($customerNtn)
    {
        $companyId = app('currentCompanyId');

        $entries = CustomerLedger::where('company_id', $companyId)
            ->where('customer_ntn', $customerNtn)
            ->with('invoice')
            ->orderBy('id', 'asc')
            ->get();

        $summary = [
            'customer_name' => $entries->first()->customer_name ?? 'Unknown',
            'customer_ntn' => $customerNtn,
            'total_invoiced' => $entries->sum('debit'),
            'total_received' => $entries->sum('credit'),
            'outstanding' => $entries->sum('debit') - $entries->sum('credit'),
        ];

        return view('customers.ledger', compact('entries', 'summary'));
    }

    public function addPayment(Request $request)
    {
        $request->validate([
            'customer_ntn' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = app('currentCompanyId');

        $lastEntry = CustomerLedger::where('company_id', $companyId)
            ->where('customer_ntn', $request->customer_ntn)
            ->orderBy('id', 'desc')
            ->first();

        $lastBalance = $lastEntry ? $lastEntry->balance_after : 0;
        $newBalance = $lastBalance - $request->amount;

        CustomerLedger::create([
            'company_id' => $companyId,
            'customer_name' => $lastEntry->customer_name ?? 'Unknown',
            'customer_ntn' => $request->customer_ntn,
            'invoice_id' => null,
            'debit' => 0,
            'credit' => $request->amount,
            'balance_after' => $newBalance,
            'type' => 'payment',
            'notes' => $request->notes,
        ]);

        return redirect('/customers/' . $request->customer_ntn . '/ledger')->with('success', 'Payment of PKR ' . number_format($request->amount, 2) . ' recorded successfully.');
    }

    public function addAdjustment(Request $request)
    {
        $request->validate([
            'customer_ntn' => 'required|string|max:50',
            'adjustment_type' => 'required|in:debit,credit',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = app('currentCompanyId');

        $lastEntry = CustomerLedger::where('company_id', $companyId)
            ->where('customer_ntn', $request->customer_ntn)
            ->orderBy('id', 'desc')
            ->first();

        $lastBalance = $lastEntry ? $lastEntry->balance_after : 0;
        $debit = $request->adjustment_type === 'debit' ? $request->amount : 0;
        $credit = $request->adjustment_type === 'credit' ? $request->amount : 0;
        $newBalance = $lastBalance + $debit - $credit;

        CustomerLedger::create([
            'company_id' => $companyId,
            'customer_name' => $lastEntry->customer_name ?? 'Unknown',
            'customer_ntn' => $request->customer_ntn,
            'invoice_id' => null,
            'debit' => $debit,
            'credit' => $credit,
            'balance_after' => $newBalance,
            'type' => 'adjustment',
            'notes' => $request->notes,
        ]);

        return redirect('/customers/' . $request->customer_ntn . '/ledger')->with('success', 'Adjustment recorded successfully.');
    }
}
