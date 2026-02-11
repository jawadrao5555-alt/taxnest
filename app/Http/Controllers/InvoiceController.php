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
use App\Services\ScheduleEngine;
use Illuminate\Http\Request;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $query = Invoice::where('company_id', $companyId)
            ->with('items', 'branch');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('internal_invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('fbr_invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('buyer_name', 'ilike', "%{$search}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$search}%");
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(15)->appends($request->query());

        return view('invoice.index', compact('invoices'));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $limitCheck = \App\Services\PlanLimitService::canCreateInvoice($companyId);
        if (!$limitCheck['allowed']) {
            return redirect('/invoices')->with('error', $limitCheck['reason']);
        }
        $branches = \App\Models\Branch::where('company_id', $companyId)->orderBy('name')->get();
        return view('invoice.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        $limitCheck = \App\Services\PlanLimitService::canCreateInvoice($companyId);
        if (!$limitCheck['allowed']) {
            return back()->with('error', $limitCheck['reason']);
        }

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'required|string|max:50',
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
        ]);

        $scheduleErrors = ScheduleEngine::validateItems($request->items);
        if (!empty($scheduleErrors)) {
            return back()->withErrors($scheduleErrors)->withInput();
        }

        $companyId = app('currentCompanyId');

        if ($request->branch_id) {
            $branch = \App\Models\Branch::where('id', $request->branch_id)->where('company_id', $companyId)->first();
            if (!$branch) {
                return back()->with('error', 'Invalid branch selected.')->withInput();
            }
        }

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
                'internal_invoice_number' => $invoiceNumber,
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'total_amount' => $totalAmount,
                'status' => 'draft',
                'branch_id' => $request->branch_id,
            ]);

            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'schedule_type' => $item['schedule_type'] ?? 'standard',
                    'pct_code' => $item['pct_code'] ?? null,
                    'tax_rate' => $this->extractTaxRate($item),
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
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

            AuditLogService::log('invoice_created', 'Invoice', $invoice->id, null, [
                'invoice_number' => $invoiceNumber,
                'buyer_name' => $request->buyer_name,
                'total_amount' => $totalAmount,
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
        $invoice->load('items', 'company', 'activityLogs.user', 'branch');

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
        $branches = \App\Models\Branch::where('company_id', $companyId)->orderBy('name')->get();
        return view('invoice.edit', compact('invoice', 'branches'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Locked invoices cannot be edited.');
        }

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'required|string|max:50',
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
        ]);

        $scheduleErrors = ScheduleEngine::validateItems($request->items);
        if (!empty($scheduleErrors)) {
            return back()->withErrors($scheduleErrors)->withInput();
        }

        if ($request->branch_id) {
            $branch = \App\Models\Branch::where('id', $request->branch_id)->where('company_id', $invoice->company_id)->first();
            if (!$branch) {
                return back()->with('error', 'Invalid branch selected.')->withInput();
            }
        }

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
                'branch_id' => $request->branch_id,
            ]);

            $invoice->items()->delete();
            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'schedule_type' => $item['schedule_type'] ?? 'standard',
                    'pct_code' => $item['pct_code'] ?? null,
                    'tax_rate' => $this->extractTaxRate($item),
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
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

            AuditLogService::log('invoice_edited', 'Invoice', $invoice->id, $oldData, [
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'total_amount' => $totalAmount,
            ]);

            ComplianceScoringJob::dispatch($invoice->id);

            DB::commit();
            return redirect('/invoice/' . $invoice->id)->with('success', 'Invoice updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update invoice.')->withInput();
        }
    }

    public function submit(Request $request, Invoice $invoice)
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
            return redirect('/invoices')->with('error', 'Your subscription has expired.');
        }

        if ($subscription && $subscription->trial_ends_at && $subscription->isTrialExpired()) {
            return redirect('/invoices')->with('error', 'Your trial period has ended.');
        }

        $mode = $request->input('mode', 'smart');
        $invoice->load('items', 'company');

        if ($mode === 'direct_mis') {
            if (!in_array(auth()->user()->role, ['company_admin', 'super_admin'])) {
                return redirect('/invoice/' . $invoice->id)->with('error', 'Only company admins can use Direct MIS mode.');
            }

            $request->validate(['override_reason' => 'required|string|min:10|max:500']);

            $invoice->status = 'submitted';
            $invoice->submission_mode = 'direct_mis';
            $invoice->override_reason = $request->override_reason;
            $invoice->override_by = auth()->id();
            $invoice->save();

            \App\Models\OverrideLog::create([
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'user_id' => auth()->id(),
                'action' => 'direct_mis_submission',
                'reason' => $request->override_reason,
                'metadata' => ['submission_mode' => 'direct_mis', 'user_role' => auth()->user()->role],
                'ip_address' => $request->ip(),
            ]);

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'override_submitted', [
                'mode' => 'direct_mis',
                'override_reason' => $request->override_reason,
                'override_by' => auth()->user()->name,
            ], request()->ip());

            AuditLogService::log('invoice_submitted', 'Invoice', $invoice->id, null, [
                'mode' => 'direct_mis',
                'override_reason' => $request->override_reason,
            ]);

            SendInvoiceToFbrJob::dispatch($invoice->id);

            if ($invoice->buyer_ntn) {
                $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
                VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
            }

            return redirect('/invoices')->with('success', 'Invoice submitted via Direct MIS mode (compliance check skipped).');
        }

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
        $invoice->submission_mode = 'smart';
        $invoice->save();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'submitted', [
            'mode' => 'smart',
            'compliance_score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
        ], request()->ip());

        AuditLogService::log('invoice_submitted', 'Invoice', $invoice->id, null, [
            'mode' => 'smart',
            'compliance_score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
        ]);

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

    public function download(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        $invoice->load('items', 'company');

        $showWatermark = false;
        $isDraft = $invoice->status === 'draft';

        $subscription = Subscription::where('company_id', $invoice->company_id)
            ->where('active', true)
            ->first();
        if (!$subscription || $subscription->isExpired()) {
            $showWatermark = true;
        }

        $subtotal = $invoice->items->sum(fn($item) => $item->price * $item->quantity);
        $totalTax = $invoice->items->sum('tax');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-professional', [
            'invoice' => $invoice,
            'showWatermark' => $showWatermark,
            'isDraft' => $isDraft,
            'subtotal' => $subtotal,
            'totalTax' => $totalTax,
        ]);

        $filename = 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';
        return $pdf->download($filename);
    }

    public function complianceCheck(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);

        $invoice = new Invoice([
            'company_id' => $companyId,
            'invoice_number' => 'PREVIEW',
            'buyer_name' => $request->buyer_name ?? '',
            'buyer_ntn' => $request->buyer_ntn ?? '',
            'total_amount' => 0,
            'status' => 'draft',
        ]);
        $invoice->id = 0;
        $invoice->setRelation('company', $company);

        $items = collect();
        $totalAmount = 0;
        foreach ($request->items ?? [] as $itemData) {
            $item = new InvoiceItem([
                'hs_code' => $itemData['hs_code'] ?? '',
                'description' => $itemData['description'] ?? '',
                'quantity' => floatval($itemData['quantity'] ?? 0),
                'price' => floatval($itemData['price'] ?? 0),
                'tax' => floatval($itemData['tax'] ?? 0),
            ]);
            $items->push($item);
            $totalAmount += ($item->price * $item->quantity) + $item->tax;
        }
        $invoice->total_amount = $totalAmount;
        $invoice->setRelation('items', $items);

        $result = ComplianceEngine::validate($invoice);
        $score = 100 - $result['total_deduction'];
        $riskLevel = HybridComplianceScorer::classifyRisk($score);
        $badge = HybridComplianceScorer::getRiskBadge($riskLevel);

        return response()->json([
            'score' => $score,
            'risk_level' => $riskLevel,
            'badge' => $badge,
            'flags' => $result['flags'],
            'details' => $result['details'],
        ]);
    }

    public function preview(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        $invoice->load('items', 'company');

        $complianceReport = ComplianceReport::where('invoice_id', $invoice->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $taxBreakdown = [];
        $subtotal = 0;
        $totalTax = 0;
        foreach ($invoice->items as $item) {
            $itemSubtotal = $item->price * $item->quantity;
            $subtotal += $itemSubtotal;
            $totalTax += $item->tax;
            $effectiveRate = $itemSubtotal > 0 ? round(($item->tax / $itemSubtotal) * 100, 2) : 0;
            $taxBreakdown[] = [
                'hs_code' => $item->hs_code,
                'description' => $item->description,
                'subtotal' => $itemSubtotal,
                'tax' => $item->tax,
                'rate' => $effectiveRate,
            ];
        }

        return view('invoice.preview', compact('invoice', 'complianceReport', 'taxBreakdown', 'subtotal', 'totalTax'));
    }

    public function validateInvoice(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) abort(403);

        $invoice->load('items', 'company');
        $scoreResult = HybridComplianceScorer::score($invoice);

        $validationResult = [
            'score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
            'rule_flags' => $scoreResult['rule_result']['flags'],
            'details' => $scoreResult['rule_result']['details'],
            'anomaly' => $scoreResult['anomaly_result'],
            'stability_bonus' => $scoreResult['stability_bonus'],
            'fbr_status' => 'ready',
        ];

        if ($scoreResult['risk_level'] === 'CRITICAL') {
            $validationResult['fbr_status'] = 'blocked';
        }

        return redirect('/invoice/' . $invoice->id . '/preview')
            ->with('validation_result', $validationResult);
    }

    public function apiStatus(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $invoice->load('items');
        $report = ComplianceReport::where('invoice_id', $invoice->id)
            ->orderBy('created_at', 'desc')->first();

        return response()->json([
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'submission_mode' => $invoice->submission_mode,
            'total_amount' => $invoice->total_amount,
            'fbr_invoice_id' => $invoice->fbr_invoice_id,
            'compliance_score' => $report ? $report->final_score : null,
            'risk_level' => $report ? $report->risk_level : null,
            'integrity_hash' => $invoice->integrity_hash,
            'qr_data' => $invoice->qr_data ? json_decode($invoice->qr_data) : null,
            'created_at' => $invoice->created_at->toIso8601String(),
        ]);
    }

    public function apiComplianceStatus()
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);

        $totalInvoices = Invoice::where('company_id', $companyId)->count();
        $lockedInvoices = Invoice::where('company_id', $companyId)->where('status', 'locked')->count();
        $latestReport = ComplianceReport::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')->first();

        return response()->json([
            'company_name' => $company->name,
            'ntn' => $company->ntn,
            'compliance_score' => $company->compliance_score,
            'total_invoices' => $totalInvoices,
            'locked_invoices' => $lockedInvoices,
            'latest_risk_level' => $latestReport ? $latestReport->risk_level : 'N/A',
        ]);
    }

    private function extractTaxRate(array $item): float
    {
        if (isset($item['tax']) && isset($item['price']) && isset($item['quantity'])) {
            $subtotal = floatval($item['price']) * floatval($item['quantity']);
            if ($subtotal > 0) {
                return round((floatval($item['tax']) / $subtotal) * 100, 2);
            }
        }
        return ScheduleEngine::getTaxRate($item['schedule_type'] ?? 'standard');
    }

}
