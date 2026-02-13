<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Subscription;
use App\Models\ComplianceReport;
use App\Jobs\SendInvoiceToFbrJob;
use App\Jobs\ComplianceScoringJob;
use App\Jobs\IntelligenceProcessingJob;
use App\Services\InvoiceActivityService;
use App\Services\IntegrityHashService;
use App\Services\ComplianceEngine;
use App\Services\HybridComplianceScorer;
use App\Services\VendorRiskEngine;
use App\Services\RiskIntelligenceEngine;
use App\Services\SroSuggestionService;
use App\Services\ScheduleEngine;
use App\Services\GlobalHsService;
use Illuminate\Http\Request;
use App\Services\AuditLogService;
use App\Services\InvoiceNumberingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $tab = $request->get('tab', 'draft');
        
        $baseQuery = Invoice::where('company_id', $companyId)
            ->with(['items', 'branch', 'fbrLogs' => function ($q) {
                $q->orderBy('created_at', 'desc')->limit(1);
            }]);

        // Filter by tab
        if ($tab === 'completed') {
            $baseQuery->whereIn('status', ['locked', 'failed']);
        } else {
            $baseQuery->whereIn('status', ['draft', 'submitted']);
        }

        // Get counts for both tabs
        $draftCount = Invoice::where('company_id', $companyId)
            ->whereIn('status', ['draft', 'submitted'])
            ->count();
        
        $completedCount = Invoice::where('company_id', $companyId)
            ->whereIn('status', ['locked', 'failed'])
            ->count();

        $query = $baseQuery;

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('internal_invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('fbr_invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('buyer_name', 'ilike', "%{$search}%")
                  ->orWhere('buyer_ntn', 'ilike', "%{$search}%")
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->where('hs_code', 'ilike', "%{$search}%");
                  });
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(15)->appends($request->query());

        return view('invoice.index', compact('invoices', 'tab', 'draftCount', 'completedCount'));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $limitCheck = \App\Services\PlanLimitService::canCreateInvoice($companyId);
        if (!$limitCheck['allowed']) {
            return redirect('/invoices')->with('error', $limitCheck['reason']);
        }
        $branches = \App\Models\Branch::where('company_id', $companyId)->orderBy('name')->get();
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;
        $nextInvoiceNumber = InvoiceNumberingService::peekNextNumber($companyId);
        $provinces = self::getPakistanProvinces();
        return view('invoice.create', compact('branches', 'standardTaxRate', 'nextInvoiceNumber', 'provinces'));
    }

    public static function getPakistanProvinces(): array
    {
        return [
            'Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan',
            'Islamabad', 'Azad Kashmir', 'Gilgit-Baltistan', 'FATA',
        ];
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        $limitCheck = \App\Services\PlanLimitService::canCreateInvoice($companyId);
        if (!$limitCheck['allowed']) {
            return back()->with('error', $limitCheck['reason']);
        }

        $isRegistered = self::detectBuyerRegistrationType($request->buyer_ntn) === 'Registered';

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => $isRegistered ? 'required|string|max:50' : 'nullable|string|max:50',
            'buyer_cnic' => $isRegistered ? 'required|string|max:15' : 'nullable|string|max:15',
            'buyer_address' => 'required|string|max:500',
            'branch_id' => 'nullable|exists:branches,id',
            'document_type' => 'required|string|in:Sale Invoice,Credit Note,Debit Note',
            'reference_invoice_number' => $request->input('document_type') !== 'Sale Invoice' ? 'required|string|max:255' : 'nullable|string|max:255',
            'destination_province' => 'required|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.tax_rate' => 'nullable|integer|min:0|max:100',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
            'items.*.default_uom' => 'nullable|string|max:100',
            'items.*.st_withheld_at_source' => 'nullable',
            'items.*.petroleum_levy' => 'nullable|numeric|min:0',
        ], [
            'document_type.required' => 'Document type is required.',
            'destination_province.required' => 'Destination Province is required.',
            'reference_invoice_number.required' => 'Reference Invoice is required for Credit/Debit Notes.',
            'buyer_cnic.required' => 'CNIC is required for registered buyers.',
            'buyer_ntn.required' => 'NTN is required for registered buyers.',
        ]);

        $itemsWithTaxRate = collect($request->items)->map(function ($item) {
            $item['tax_rate'] = isset($item['tax_rate']) && is_numeric($item['tax_rate']) ? intval($item['tax_rate']) : null;
            return $item;
        })->toArray();

        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;

        $scheduleErrors = ScheduleEngine::validateItems($itemsWithTaxRate, $standardTaxRate);
        if (!empty($scheduleErrors)) {
            return back()->withErrors($scheduleErrors)->withInput();
        }

        $selectedBranch = null;
        if ($request->branch_id) {
            $selectedBranch = \App\Models\Branch::where('id', $request->branch_id)->where('company_id', $companyId)->first();
            if (!$selectedBranch) {
                return back()->with('error', 'Invalid branch selected.')->withInput();
            }
        }

        $documentType = $request->input('document_type', 'Sale Invoice');
        if (in_array($documentType, ['Credit Note', 'Debit Note']) && empty($request->reference_invoice_number)) {
            return back()->with('error', 'Reference invoice number is required for Credit/Debit Notes.')->withInput();
        }

        DB::beginTransaction();
        try {
            $totalValueExcludingST = 0;
            $totalSalesTax = 0;
            foreach ($request->items as $item) {
                $itemValue = floatval($item['price']) * floatval($item['quantity']);
                $totalValueExcludingST += $itemValue;
                $totalSalesTax += floatval($item['tax']);
            }
            $totalAmount = round($totalValueExcludingST + $totalSalesTax, 2);

            $whtRate = 0;
            $whtAmount = 0;
            $netReceivable = $totalAmount;

            $invoiceNumber = InvoiceNumberingService::generateNextNumber($companyId);

            $supplierProvince = $selectedBranch?->province ?? $company->province ?? null;
            $buyerNtn = $request->buyer_ntn;
            $buyerRegType = self::detectBuyerRegistrationType($buyerNtn);

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'invoice_number' => $invoiceNumber,
                'internal_invoice_number' => $invoiceNumber,
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $buyerNtn,
                'buyer_cnic' => $request->buyer_cnic,
                'buyer_address' => $request->buyer_address,
                'buyer_registration_type' => $buyerRegType,
                'total_amount' => $totalAmount,
                'total_value_excluding_st' => round($totalValueExcludingST, 2),
                'total_sales_tax' => round($totalSalesTax, 2),
                'wht_rate' => $whtRate,
                'wht_amount' => $whtAmount,
                'net_receivable' => $netReceivable,
                'status' => 'draft',
                'fbr_status' => null,
                'branch_id' => $request->branch_id,
                'document_type' => $documentType,
                'reference_invoice_number' => $request->reference_invoice_number,
                'supplier_province' => $supplierProvince,
                'destination_province' => $request->destination_province,
                'invoice_date' => now()->toDateString(),
            ]);

            $manualOverrides = [];
            foreach ($request->items as $idx => $item) {
                $scheduleType = $item['schedule_type'] ?? 'standard';
                $saleType = ScheduleEngine::mapSaleType($scheduleType);

                $hsResolved = GlobalHsService::resolveForInvoiceItem(
                    $item['hs_code'], $standardTaxRate, $companyId, $invoice->id
                );

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'schedule_type' => $scheduleType,
                    'pct_code' => $item['pct_code'] ?? ($hsResolved['pct_code'] ?? null),
                    'tax_rate' => $this->extractTaxRate($item),
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
                    'default_uom' => $item['default_uom'] ?? ($hsResolved['default_uom'] ?? 'Numbers, pieces, units'),
                    'sale_type' => $saleType,
                    'st_withheld_at_source' => !empty($item['st_withheld_at_source']),
                    'petroleum_levy' => !empty($item['petroleum_levy']) ? floatval($item['petroleum_levy']) : null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'tax' => $item['tax'],
                ]);

                if (!empty($item['tax_rate_override']) || !empty($item['sro_override']) || !empty($item['mrp_override'])) {
                    $manualOverrides[] = [
                        'item_index' => $idx + 1,
                        'hs_code' => $item['hs_code'],
                        'tax_rate' => $item['tax_rate'] ?? null,
                        'sro' => $item['sro_schedule_no'] ?? null,
                        'mrp' => $item['mrp'] ?? null,
                    ];
                }
            }

            if (!empty($manualOverrides)) {
                AuditLogService::log('manual_tax_override', 'Invoice', $invoice->id, null, [
                    'overrides' => $manualOverrides,
                    'user' => auth()->user()->name,
                    'action' => 'invoice_creation',
                ]);
            }

            InvoiceActivityService::log($invoice->id, $companyId, 'created', [
                'buyer_name' => $request->buyer_name,
                'total_amount' => $totalAmount,
                'items_count' => count($request->items),
                'document_type' => $documentType,
            ]);

            AuditLogService::log('invoice_created', 'Invoice', $invoice->id, null, [
                'invoice_number' => $invoiceNumber,
                'buyer_name' => $request->buyer_name,
                'total_amount' => $totalAmount,
                'document_type' => $documentType,
            ]);

            ComplianceScoringJob::dispatch($invoice->id);

            DB::commit();
            return redirect('/invoices')->with('success', 'Invoice created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to create invoice: ' . $e->getMessage())->withInput();
        }
    }

    public static function detectBuyerRegistrationType(?string $buyerNtn): string
    {
        if (empty($buyerNtn)) return 'Unregistered';
        $clean = preg_replace('/[^0-9]/', '', $buyerNtn);
        if (strlen($clean) >= 7) return 'Registered';
        return 'Unregistered';
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

        $riskAnalysis = null;
        if ($invoice->status === 'draft') {
            $riskAnalysis = RiskIntelligenceEngine::analyzeInvoice($invoice);
        }

        $sroSuggestions = [];
        if ($invoice->status === 'draft') {
            $itemsData = $invoice->items->map(fn($item) => [
                'schedule_type' => $item->schedule_type ?? 'standard',
                'tax_rate' => $item->tax_rate,
                'hs_code' => $item->hs_code,
            ])->toArray();
            $sroSuggestions = SroSuggestionService::suggestForItems($itemsData);
        }

        $vendorRisk = null;
        if ($invoice->buyer_ntn) {
            $vendorRisk = \App\Models\VendorRiskProfile::where('company_id', $companyId)
                ->where('vendor_ntn', $invoice->buyer_ntn)
                ->first();
        }

        return view('invoice.show', compact('invoice', 'complianceReport', 'riskAnalysis', 'sroSuggestions', 'vendorRisk'));
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
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;
        $provinces = self::getPakistanProvinces();
        return view('invoice.edit', compact('invoice', 'branches', 'standardTaxRate', 'provinces'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Locked invoices cannot be edited.');
        }

        $isRegistered = self::detectBuyerRegistrationType($request->buyer_ntn) === 'Registered';

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => $isRegistered ? 'required|string|max:50' : 'nullable|string|max:50',
            'buyer_cnic' => $isRegistered ? 'required|string|max:15' : 'nullable|string|max:15',
            'buyer_address' => 'required|string|max:500',
            'branch_id' => 'nullable|exists:branches,id',
            'document_type' => 'required|string|in:Sale Invoice,Credit Note,Debit Note',
            'reference_invoice_number' => $request->input('document_type') !== 'Sale Invoice' ? 'required|string|max:255' : 'nullable|string|max:255',
            'destination_province' => 'required|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.tax_rate' => 'nullable|integer|min:0|max:100',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
            'items.*.default_uom' => 'nullable|string|max:100',
            'items.*.st_withheld_at_source' => 'nullable',
            'items.*.petroleum_levy' => 'nullable|numeric|min:0',
        ], [
            'document_type.required' => 'Document type is required.',
            'destination_province.required' => 'Destination Province is required.',
            'reference_invoice_number.required' => 'Reference Invoice is required for Credit/Debit Notes.',
            'buyer_cnic.required' => 'CNIC is required for registered buyers.',
            'buyer_ntn.required' => 'NTN is required for registered buyers.',
        ]);

        $itemsWithTaxRate = collect($request->items)->map(function ($item) {
            $item['tax_rate'] = isset($item['tax_rate']) && is_numeric($item['tax_rate']) ? intval($item['tax_rate']) : null;
            return $item;
        })->toArray();

        $company = \App\Models\Company::find($invoice->company_id);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;

        $scheduleErrors = ScheduleEngine::validateItems($itemsWithTaxRate, $standardTaxRate);
        if (!empty($scheduleErrors)) {
            return back()->withErrors($scheduleErrors)->withInput();
        }

        $selectedBranch = null;
        if ($request->branch_id) {
            $selectedBranch = \App\Models\Branch::where('id', $request->branch_id)->where('company_id', $invoice->company_id)->first();
            if (!$selectedBranch) {
                return back()->with('error', 'Invalid branch selected.')->withInput();
            }
        }

        $documentType = $request->input('document_type', $invoice->document_type ?? 'Sale Invoice');
        if (in_array($documentType, ['Credit Note', 'Debit Note']) && empty($request->reference_invoice_number)) {
            return back()->with('error', 'Reference invoice number is required for Credit/Debit Notes.')->withInput();
        }

        $oldData = [
            'buyer_name' => $invoice->buyer_name,
            'buyer_ntn' => $invoice->buyer_ntn,
            'total_amount' => $invoice->total_amount,
        ];

        DB::beginTransaction();
        try {
            $totalValueExcludingST = 0;
            $totalSalesTax = 0;
            foreach ($request->items as $item) {
                $itemValue = floatval($item['price']) * floatval($item['quantity']);
                $totalValueExcludingST += $itemValue;
                $totalSalesTax += floatval($item['tax']);
            }
            $totalAmount = round($totalValueExcludingST + $totalSalesTax, 2);

            $whtRate = 0;
            $whtAmount = 0;
            $netReceivable = $totalAmount;

            $supplierProvince = $selectedBranch?->province ?? $company->province ?? $invoice->supplier_province;
            $buyerRegType = self::detectBuyerRegistrationType($request->buyer_ntn);

            $updateData = [
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'buyer_cnic' => $request->buyer_cnic,
                'buyer_address' => $request->buyer_address,
                'buyer_registration_type' => $buyerRegType,
                'total_amount' => $totalAmount,
                'total_value_excluding_st' => round($totalValueExcludingST, 2),
                'total_sales_tax' => round($totalSalesTax, 2),
                'wht_rate' => $whtRate,
                'wht_amount' => $whtAmount,
                'net_receivable' => $netReceivable,
                'branch_id' => $request->branch_id,
                'document_type' => $documentType,
                'reference_invoice_number' => $request->reference_invoice_number,
                'supplier_province' => $supplierProvince,
                'destination_province' => $request->destination_province,
            ];

            if ($invoice->status === 'failed') {
                $updateData['status'] = 'draft';
                $updateData['fbr_status'] = 'pending';
            }

            $invoice->update($updateData);

            $invoice->items()->delete();
            $manualOverrides = [];
            foreach ($request->items as $idx => $item) {
                $scheduleType = $item['schedule_type'] ?? 'standard';
                $saleType = ScheduleEngine::mapSaleType($scheduleType);

                $hsResolved = GlobalHsService::resolveForInvoiceItem(
                    $item['hs_code'], $standardTaxRate, $companyId, $invoice->id
                );

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'schedule_type' => $scheduleType,
                    'pct_code' => $item['pct_code'] ?? ($hsResolved['pct_code'] ?? null),
                    'tax_rate' => $this->extractTaxRate($item),
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
                    'default_uom' => $item['default_uom'] ?? ($hsResolved['default_uom'] ?? 'Numbers, pieces, units'),
                    'sale_type' => $saleType,
                    'st_withheld_at_source' => !empty($item['st_withheld_at_source']),
                    'petroleum_levy' => !empty($item['petroleum_levy']) ? floatval($item['petroleum_levy']) : null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'tax' => $item['tax'],
                ]);

                if (!empty($item['tax_rate_override']) || !empty($item['sro_override']) || !empty($item['mrp_override'])) {
                    $manualOverrides[] = [
                        'item_index' => $idx + 1,
                        'hs_code' => $item['hs_code'],
                        'tax_rate' => $item['tax_rate'] ?? null,
                        'sro' => $item['sro_schedule_no'] ?? null,
                        'mrp' => $item['mrp'] ?? null,
                    ];
                }
            }

            if (!empty($manualOverrides)) {
                AuditLogService::log('manual_tax_override', 'Invoice', $invoice->id, $oldData, [
                    'overrides' => $manualOverrides,
                    'user' => auth()->user()->name,
                    'action' => 'invoice_update',
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
            return redirect('/invoices')->with('error', 'Invoice already locked and submitted to FBR.');
        }

        if ($invoice->status === 'submitted') {
            return redirect('/invoice/' . $invoice->id)->with('error', 'Invoice is already being processed by FBR. Please wait.');
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
        $fbrEnvironment = $request->input('fbr_environment');
        $invoice->load('items', 'company');

        $itemsForValidation = $invoice->items->map(function ($item) {
            return [
                'schedule_type' => $item->schedule_type ?? 'standard',
                'tax_rate' => $item->tax_rate,
                'sro_schedule_no' => $item->sro_schedule_no,
                'serial_no' => $item->serial_no,
                'mrp' => $item->mrp,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'tax' => $item->tax,
            ];
        })->toArray();

        $company = \App\Models\Company::find($invoice->company_id);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;
        $submissionCheck = ScheduleEngine::validateForSubmission($itemsForValidation, $standardTaxRate);
        if (!$submissionCheck['valid']) {
            $errorHtml = $submissionCheck['message'] . ' ' . implode(' | ', $submissionCheck['errors']);
            return redirect('/invoice/' . $invoice->id)->with('error', $errorHtml);
        }

        $riskAnalysis = RiskIntelligenceEngine::analyzeForPreSubmission($invoice);
        $isInternalCompany = $invoice->company && $invoice->company->is_internal_account;

        if ($riskAnalysis['should_block'] && !$isInternalCompany) {
            $riskMessages = array_map(fn($r) => $r['message'], $riskAnalysis['risks']);
            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'intelligence_blocked', [
                'risk_score' => $riskAnalysis['risk_score'],
                'risk_level' => $riskAnalysis['risk_level'],
                'risks' => $riskMessages,
            ]);
            $message = 'FBR submission blocked - CRITICAL intelligence risk (Score: ' . $riskAnalysis['risk_score'] . '/100). Issues: ' . implode(' | ', array_slice($riskMessages, 0, 3)) . '. Please resolve and resubmit.';
            return redirect('/invoice/' . $invoice->id)->with('error', $message);
        }

        IntelligenceProcessingJob::dispatch($invoice->id);

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

            SendInvoiceToFbrJob::dispatch($invoice->id, $fbrEnvironment);

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

        SendInvoiceToFbrJob::dispatch($invoice->id, $fbrEnvironment);

        if ($invoice->buyer_ntn) {
            $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
            VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
        }

        return redirect('/invoices')->with('success', 'Invoice submitted to FBR (Compliance Score: ' . $scoreResult['final_score'] . ' - ' . $scoreResult['risk_level'] . ' risk).');
    }

    public function retry(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            abort(403);
        }

        if ($invoice->status !== 'failed') {
            return redirect('/invoice/' . $invoice->id)->with('error', 'Only failed invoices can be retried.');
        }

        $invoice->status = 'submitted';
        $invoice->fbr_status = 'pending';
        $invoice->save();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'retry_submitted', [
            'retried_by' => auth()->user()->name,
        ], request()->ip());

        AuditLogService::log('invoice_retry', 'Invoice', $invoice->id, null, [
            'retried_by' => auth()->user()->name,
        ]);

        SendInvoiceToFbrJob::dispatch($invoice->id);

        return redirect('/invoice/' . $invoice->id)->with('success', 'Invoice resubmitted to FBR. You will be notified of the result.');
    }

    public function resubmitToFbr(Request $request, Invoice $invoice)
    {
        $user = auth()->user();
        if (!in_array($user->role, ['company_admin', 'super_admin'])) {
            abort(403);
        }

        if ($user->role !== 'super_admin') {
            $companyId = app('currentCompanyId');
            if ($invoice->company_id !== $companyId) {
                abort(403);
            }
        }

        if ($invoice->status === 'locked') {
            return redirect('/invoice/' . $invoice->id)->with('error', 'Invoice already submitted to FBR' . (!empty($invoice->fbr_invoice_number) ? ' with number: ' . $invoice->fbr_invoice_number : '') . '. Cannot resubmit.');
        }

        if (!in_array($invoice->status, ['draft', 'failed'])) {
            return redirect('/invoice/' . $invoice->id)->with('error', 'Invoice cannot be submitted in current status.');
        }

        $invoice->load('items', 'company');
        $company = $invoice->company;

        $fbrService = new \App\Services\FbrService();
        $response = $fbrService->submitInvoice($invoice, 0);

        if ($response['status'] === 'success') {
            $fbrNum = $response['fbr_invoice_number'] ?? null;
            if ($fbrNum) {
                $invoice->fbr_invoice_number = $fbrNum;
                $invoice->fbr_invoice_id = $fbrNum;
                $invoice->fbr_submission_date = now();
            }
            $invoice->status = 'locked';
            $invoice->fbr_status = 'success';
            $invoice->integrity_hash = \App\Services\IntegrityHashService::generate($invoice);
            $invoice->qr_data = json_encode([
                'ntn' => $company->ntn ?? '',
                'invoice_number' => $invoice->internal_invoice_number ?? $invoice->invoice_number,
                'fbr_invoice_id' => $fbrNum ?? $invoice->invoice_number,
                'date' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                'total' => $invoice->total_amount,
            ]);
            $invoice->save();

            $company->update(['last_successful_submission' => now()]);

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'resubmitted_success', [
                'fbr_invoice_number' => $fbrNum,
                'environment' => $company->fbr_environment,
                'resubmitted_by' => $user->name,
            ], request()->ip());

            AuditLogService::log('invoice_resubmitted', 'Invoice', $invoice->id, null, [
                'fbr_invoice_number' => $fbrNum,
                'environment' => $company->fbr_environment,
            ]);

            \App\Services\ComplianceScoreService::recalculate($invoice->company_id);

            return redirect('/invoice/' . $invoice->id)->with('success', 'FBR submission successful! Invoice Number: ' . $fbrNum);
        }

        $errors = $response['errors'] ?? [];
        $failureType = $response['failure_type'] ?? 'unknown';
        $errorMsg = 'FBR submission failed (' . $failureType . ')';
        if (!empty($errors)) {
            $errorMsg .= ': ' . implode(' | ', array_slice($errors, 0, 5));
        }

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'resubmit_failed', [
            'failure_type' => $failureType,
            'errors' => $errors,
            'environment' => $company->fbr_environment,
        ], request()->ip());

        return redirect('/invoice/' . $invoice->id)->with('error', $errorMsg);
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

        $whtRate = floatval(request()->query('wht_rate', $invoice->wht_rate ?? 0));
        $whtAmount = round($subtotal * ($whtRate / 100), 2);
        $netReceivable = round(($subtotal + $totalTax) + $whtAmount, 2);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-professional', [
            'invoice' => $invoice,
            'showWatermark' => $showWatermark,
            'isDraft' => $isDraft,
            'subtotal' => $subtotal,
            'totalTax' => $totalTax,
            'wht_rate' => $whtRate,
            'wht_amount' => $whtAmount,
            'net_receivable' => $netReceivable,
        ]);

        $filename = 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';
        return $pdf->download($filename);
    }

    public function updateWht(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) abort(403);

        $request->validate([
            'wht_rate' => 'required|numeric|min:0|max:100',
        ]);

        $whtRate = floatval($request->wht_rate);
        $subtotal = $invoice->items->sum(fn($item) => $item->price * $item->quantity);
        $totalTax = $invoice->items->sum('tax');
        $whtAmount = round($subtotal * ($whtRate / 100), 2);
        $netReceivable = round(($subtotal + $totalTax) + $whtAmount, 2);

        $invoice->update([
            'wht_rate' => $whtRate,
            'wht_amount' => $whtAmount,
            'net_receivable' => $netReceivable,
        ]);

        return redirect()->back()->with('success', 'WHT rate updated to ' . $whtRate . '%. Invoice and PDF updated.');
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

    public function validateFbrPayload(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $invoice->load('items', 'company');
        $fbrService = new \App\Services\FbrService();
        $result = $fbrService->validateOnly($invoice);

        AuditLogService::log('fbr_payload_validation', 'Invoice', $invoice->id, null, [
            'status' => $result['status'],
            'errors' => $result['errors'] ?? [],
        ]);

        return response()->json($result);
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
        if (isset($item['tax_rate']) && is_numeric($item['tax_rate'])) {
            return floatval($item['tax_rate']);
        }
        if (isset($item['tax']) && isset($item['price']) && isset($item['quantity'])) {
            $subtotal = floatval($item['price']) * floatval($item['quantity']);
            if ($subtotal > 0) {
                return round((floatval($item['tax']) / $subtotal) * 100, 2);
            }
        }
        return ScheduleEngine::getTaxRate($item['schedule_type'] ?? 'standard');
    }

}
