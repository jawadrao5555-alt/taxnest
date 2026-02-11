<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Subscription;
use App\Models\ComplianceReport;
use App\Jobs\SendInvoiceToFbrJob;
use App\Jobs\ComplianceScoringJob;
use App\Services\InvoiceActivityService;
use App\Services\IntegrityHashService;
use App\Services\ComplianceEngine;
use App\Services\HybridComplianceScorer;
use App\Services\VendorRiskEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $invoices = Invoice::where('company_id', $companyId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('invoice.index', compact('invoices'));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $this->checkInvoiceLimit($companyId);
        return view('invoice.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'required|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
        ]);

        $companyId = app('currentCompanyId');
        $this->checkInvoiceLimit($companyId);

        DB::beginTransaction();
        try {
            $totalAmount = 0;
            foreach ($request->items as $item) {
                $totalAmount += ($item['price'] * $item['quantity']) + $item['tax'];
            }

            $invoiceNumber = 'INV-' . now()->format('Ymd') . '-' . str_pad(
                Invoice::where('company_id', $companyId)->count() + 1,
                4, '0', STR_PAD_LEFT
            );

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'invoice_number' => $invoiceNumber,
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'total_amount' => $totalAmount,
                'status' => 'draft',
            ]);

            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'tax' => $item['tax'],
                ]);
            }

            InvoiceActivityService::log($invoice->id, $companyId, 'created', [
                'buyer_name' => $request->buyer_name,
                'total_amount' => $totalAmount,
                'items_count' => count($request->items),
            ]);

            ComplianceScoringJob::dispatch($invoice->id);

            DB::commit();
            return redirect('/invoices')->with('success', 'Invoice created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to create invoice: ' . $e->getMessage())->withInput();
        }
    }

    public function show(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        $invoice->load('items', 'company', 'activityLogs.user');

        $complianceReport = ComplianceReport::where('invoice_id', $invoice->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return view('invoice.show', compact('invoice', 'complianceReport'));
    }

    public function edit(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            abort(403);
        }
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Locked invoices cannot be edited.');
        }
        $invoice->load('items');
        return view('invoice.edit', compact('invoice'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Locked invoices cannot be edited.');
        }

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'required|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
        ]);

        $oldData = [
            'buyer_name' => $invoice->buyer_name,
            'buyer_ntn' => $invoice->buyer_ntn,
            'total_amount' => $invoice->total_amount,
        ];

        DB::beginTransaction();
        try {
            $totalAmount = 0;
            foreach ($request->items as $item) {
                $totalAmount += ($item['price'] * $item['quantity']) + $item['tax'];
            }

            $invoice->update([
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'total_amount' => $totalAmount,
            ]);

            $invoice->items()->delete();
            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'tax' => $item['tax'],
                ]);
            }

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'edited', [
                'old' => $oldData,
                'new' => [
                    'buyer_name' => $request->buyer_name,
                    'buyer_ntn' => $request->buyer_ntn,
                    'total_amount' => $totalAmount,
                ],
            ]);

            ComplianceScoringJob::dispatch($invoice->id);

            DB::commit();
            return redirect('/invoice/' . $invoice->id)->with('success', 'Invoice updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update invoice.')->withInput();
        }
    }

    public function submit(Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Invoice already locked.');
        }

        $companyId = $invoice->company_id;
        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        if ($subscription && $subscription->isExpired()) {
            return redirect('/invoices')->with('error', 'Your subscription has expired. Please renew to submit invoices to FBR.');
        }

        if ($subscription && $subscription->trial_ends_at && $subscription->isTrialExpired()) {
            return redirect('/invoices')->with('error', 'Your trial period has ended. Please subscribe to a plan to submit invoices.');
        }

        $invoice->load('items', 'company');
        $scoreResult = HybridComplianceScorer::score($invoice);

        if ($scoreResult['risk_level'] === 'CRITICAL') {
            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'blocked', [
                'reason' => 'CRITICAL risk level',
                'score' => $scoreResult['final_score'],
                'rule_flags' => $scoreResult['rule_result']['flags'],
            ]);

            $flagMessages = [];
            if ($scoreResult['rule_result']['flags']['RATE_MISMATCH'] ?? false) $flagMessages[] = 'Tax rate mismatch detected';
            if ($scoreResult['rule_result']['flags']['BUYER_RISK'] ?? false) $flagMessages[] = 'Buyer NTN risk (Section 23)';
            if ($scoreResult['rule_result']['flags']['BANKING_RISK'] ?? false) $flagMessages[] = 'Banking violation (Section 73)';
            if ($scoreResult['rule_result']['flags']['STRUCTURE_ERROR'] ?? false) $flagMessages[] = 'Invoice structure error';

            $message = 'FBR submission blocked - CRITICAL compliance risk (Score: ' . $scoreResult['final_score'] . '). Issues: ' . implode(', ', $flagMessages) . '. Please fix and resubmit.';
            return redirect('/invoice/' . $invoice->id)->with('error', $message);
        }

        $invoice->status = 'submitted';
        $invoice->save();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'submitted', [
            'compliance_score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
        ], request()->ip());

        SendInvoiceToFbrJob::dispatch($invoice->id);

        if ($invoice->buyer_ntn) {
            $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
            VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
        }

        return redirect('/invoices')->with('success', 'Invoice submitted to FBR (Compliance Score: ' . $scoreResult['final_score'] . ' - ' . $scoreResult['risk_level'] . ' risk).');
    }

    public function verifyIntegrity(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $invoice->load('items');
        $isValid = IntegrityHashService::verify($invoice);

        if ($isValid) {
            return back()->with('success', 'Integrity check passed. Invoice data has not been tampered with.');
        }

        return back()->with('error', 'Integrity check FAILED. Invoice data may have been altered after FBR submission.');
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load('items', 'company');

        $showWatermark = false;
        $subscription = Subscription::where('company_id', $invoice->company_id)
            ->where('active', true)
            ->first();

        if (!$subscription || $subscription->isExpired()) {
            $showWatermark = true;
        }

        $html = view('invoice.pdf', compact('invoice', 'showWatermark'))->render();

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    private function checkInvoiceLimit($companyId)
    {
        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->first();

        if (!$subscription) {
            abort(403, 'No active subscription. Please subscribe to a plan first.');
        }

        $invoiceCount = Invoice::where('company_id', $companyId)->count();

        if ($invoiceCount >= $subscription->pricingPlan->invoice_limit) {
            abort(403, 'Invoice limit reached. Please upgrade your plan.');
        }
    }
}
