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
use App\Services\FbrService;
use App\Services\ComplianceScoreService;
use App\Services\HsUsagePatternService;
use App\Models\FbrLog;
use App\Models\CustomerLedger;
use Illuminate\Http\Request;
use App\Services\AuditLogService;
use App\Services\InvoiceNumberingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        if ($tab === 'completed') {
            $baseQuery->whereIn('status', ['locked', 'pending_verification']);
        } elseif ($tab === 'failed') {
            $baseQuery->where('status', 'failed');
        } else {
            $baseQuery->where('status', 'draft');
        }

        $draftCount = Invoice::where('company_id', $companyId)
            ->where('status', 'draft')
            ->count();

        $failedCount = Invoice::where('company_id', $companyId)
            ->where('status', 'failed')
            ->count();
        
        $completedCount = Invoice::where('company_id', $companyId)
            ->whereIn('status', ['locked', 'pending_verification'])
            ->count();

        $query = $baseQuery;

        if ($search = $request->get('search')) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('internal_invoice_number', $like, "%{$search}%")
                  ->orWhere('fbr_invoice_number', $like, "%{$search}%")
                  ->orWhere('invoice_number', $like, "%{$search}%")
                  ->orWhere('buyer_name', $like, "%{$search}%")
                  ->orWhere('buyer_ntn', $like, "%{$search}%")
                  ->orWhereHas('items', function ($iq) use ($search, $like) {
                      $iq->where('hs_code', $like, "%{$search}%");
                  });
            });
        }

        if ($tab === 'completed') {
            $fbrStatusFilter = $request->get('fbr_status');
            if ($fbrStatusFilter && in_array($fbrStatusFilter, ['production', 'sandbox', 'validated', 'pending', 'failed'])) {
                $query->where('fbr_status', $fbrStatusFilter);
            }
            $dateFrom = $request->get('date_from');
            if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $query->whereDate('invoice_date', '>=', $dateFrom);
            }
            $dateTo = $request->get('date_to');
            if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $query->whereDate('invoice_date', '<=', $dateTo);
            }
            $month = $request->get('month');
            if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $dateExpr = \App\Helpers\DbCompat::dateFormat('invoice_date', 'YYYY-MM');
                $query->whereRaw("{$dateExpr} = ?", [$month]);
            }
            $docType = $request->get('doc_type');
            if ($docType && in_array($docType, ['Sale Invoice', 'Credit Note', 'Debit Note'])) {
                $query->where('document_type', $docType);
            }
        }

        $perPage = (int) $request->get('per_page', $tab === 'completed' ? 25 : 15);
        $perPage = in_array($perPage, [15, 25, 50, 100]) ? $perPage : 25;

        $sortBy = $request->get('sort', 'invoice_date');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['invoice_date', 'created_at', 'total_amount', 'buyer_name', 'invoice_number'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'invoice_date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $invoices = $query->orderBy($sortBy, $sortDir)->paginate($perPage)->appends($request->query());

        $completedStats = null;
        if ($tab === 'completed') {
            $statsBase = Invoice::where('company_id', $companyId)
                ->whereIn('status', ['locked', 'pending_verification']);
            
            $completedStats = [
                'total_amount' => (clone $statsBase)->sum('total_amount'),
                'total_tax' => (clone $statsBase)->sum('total_sales_tax'),
                'production_count' => (clone $statsBase)->where('fbr_status', 'production')->count(),
                'pending_count' => (clone $statsBase)->where('status', 'pending_verification')->count(),
                'this_month_count' => (clone $statsBase)->whereRaw(\App\Helpers\DbCompat::dateFormat('invoice_date', 'YYYY-MM') . " = ?", [now()->format('Y-m')])->count(),
                'this_month_amount' => (clone $statsBase)->whereRaw(\App\Helpers\DbCompat::dateFormat('invoice_date', 'YYYY-MM') . " = ?", [now()->format('Y-m')])->sum('total_amount'),
                'unique_buyers' => (clone $statsBase)->whereNotNull('buyer_name')->where('buyer_name', '!=', '')->distinct('buyer_name')->count('buyer_name'),
            ];
        }

        // Task 142: show/lock the AI Reader entry button
        $aiReaderAllowed = \App\Services\DiFeatureService::planAllows(
            \App\Models\Company::find($companyId),
            'ai_reader'
        );

        // A full ZIP of thousands of invoices takes many minutes, so keep the
        // panel attached to whatever build this company already has. The export
        // id otherwise lives only in the flash session, and a single refresh
        // orphans a running build or hides a file that is sitting there ready.
        $zipExportId = session('invoice_zip_export_id')
            ?: \App\Models\InvoiceZipExport::where('company_id', $companyId)
                ->where(function ($q) {
                    $q->whereIn('status', \App\Models\InvoiceZipExport::ACTIVE_STATUSES)
                        ->orWhere(function ($ready) {
                            $ready->where('status', 'ready')
                                ->where('created_at', '>', now()->subHours(\App\Services\InvoiceZipBuilderService::RETENTION_HOURS));
                        });
                })
                ->latest('id')
                ->value('id');

        return view('invoice.index', compact('invoices', 'tab', 'draftCount', 'failedCount', 'completedCount', 'completedStats', 'aiReaderAllowed', 'zipExportId'));
    }

    public function uniqueBuyers(Request $request)
    {
        $companyId = app('currentCompanyId');

        $buyers = Invoice::where('company_id', $companyId)
            ->whereIn('status', ['locked', 'pending_verification'])
            ->whereNotNull('buyer_name')
            ->where('buyer_name', '!=', '')
            ->selectRaw("buyer_name, MAX(buyer_ntn) as buyer_ntn, COUNT(*) as total_invoices, SUM(total_amount) as total_amount, MAX(invoice_date) as last_invoice_date")
            ->groupBy('buyer_name')
            ->orderByDesc('total_amount')
            ->get();

        return response()->json($buyers);
    }

    public function create(Request $request)
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

        // Task 142: AI Invoice Reader prefill — the review screen IS this form.
        $aiPrefill = null;
        $aiParseId = null;
        $aiPrefillJson = null;
        if ($request->query('ai_parse')) {
            $parse = \App\Models\AiInvoiceParse::where('company_id', $companyId)
                ->where('id', (int) $request->query('ai_parse'))
                ->where('status', 'success')
                ->first();
            if (!$parse) {
                return redirect('/invoices/ai-reader')->with('error', 'AI draft not found.');
            }
            if ($parse->invoice_id) {
                return redirect('/invoices')->with('error', 'This AI draft has already been saved as an invoice.');
            }
            $aiPrefill = $parse->payload_json;
            $aiParseId = $parse->id;
            // UTF-8-safe encode for inline <script> (HEX_TAG blocks </script> breakout)
            $aiPrefillJson = json_encode($aiPrefill, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            if ($aiPrefillJson === false) {
                $aiPrefill = null;
                $aiParseId = null;
                $aiPrefillJson = null;
            }
        }

        $servicesTaxRate = ScheduleEngine::servicesRateForProvince($company->province ?? null);
        $branchServicesRates = $branches->mapWithKeys(fn ($b) => [$b->id => ScheduleEngine::servicesRateForProvince($b->province ?: ($company->province ?? null))]);

        return view('invoice.create', compact('branches', 'standardTaxRate', 'nextInvoiceNumber', 'provinces', 'company', 'aiPrefill', 'aiPrefillJson', 'aiParseId', 'servicesTaxRate', 'branchServicesRates'));
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

        $buyerRegTypeInput = $request->input('buyer_registration_type');
        if (!$buyerRegTypeInput || !in_array($buyerRegTypeInput, ['Registered', 'Unregistered'])) {
            $buyerRegTypeInput = self::detectBuyerRegistrationType($request->buyer_ntn, $request->buyer_cnic);
        }

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'nullable|string|max:50',
            'buyer_cnic' => 'nullable|string|max:15',
            'buyer_address' => 'required|string|max:500',
            'branch_id' => 'nullable|exists:branches,id',
            'document_type' => 'required|string|in:Sale Invoice,Credit Note,Debit Note',
            'reference_invoice_number' => $request->input('document_type') !== 'Sale Invoice' ? 'required|string|max:255' : 'nullable|string|max:255',
            'destination_province' => 'required|string|max:100',
            'invoice_date' => 'nullable|date|before_or_equal:today',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0.01',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated,fed_services,services',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.tax_rate' => 'nullable|integer|min:0|max:100',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
            'items.*.default_uom' => 'nullable|string|max:100',
            'items.*.st_withheld_at_source' => 'nullable',
            'items.*.petroleum_levy' => 'nullable|numeric|min:0',
            'items.*.further_tax' => 'nullable|numeric|min:0',
        ], [
            'document_type.required' => 'Document type is required.',
            'destination_province.required' => 'Destination Province is required.',
            'reference_invoice_number.required' => 'Reference Invoice is required for Credit/Debit Notes.',
            'invoice_date.before_or_equal' => 'Invoice date cannot be in the future — FBR rejects future-dated invoices.',
            'items.*.price.min' => 'Item prices must be greater than Rs 0. FBR rejects free/bonus lines (error 0300). Note the free item in the description of a paid line or omit it.',
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

            $invoice = Invoice::create([
                'company_id' => $companyId,
                'invoice_number' => $invoiceNumber,
                'internal_invoice_number' => $invoiceNumber,
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $buyerNtn,
                'buyer_cnic' => $request->buyer_cnic,
                'buyer_address' => $request->buyer_address,
                'buyer_registration_type' => $buyerRegTypeInput,
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
                // The sale's own date — reports and the FBR payload both read
                // this, so a back-dated sale must not land on today.
                'invoice_date' => $request->filled('invoice_date')
                    ? \Illuminate\Support\Carbon::parse($request->input('invoice_date'))->toDateString()
                    : now()->toDateString(),
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
                    'tax_rate' => $this->extractTaxRate($item, $supplierProvince),
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
                    'default_uom' => $item['default_uom'] ?? ($hsResolved['default_uom'] ?? 'Numbers, pieces, units'),
                    'sale_type' => $saleType,
                    'st_withheld_at_source' => !empty($item['st_withheld_at_source']),
                    'petroleum_levy' => !empty($item['petroleum_levy']) ? floatval($item['petroleum_levy']) : null,
                    'further_tax' => !empty($item['further_tax']) ? floatval($item['further_tax']) : 0,
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

            \App\Services\HsUsagePatternService::recordFromInvoiceCreation($request->items);

            // Task 142: link AI Reader parse -> saved draft (marks the parse consumed)
            if ($request->filled('ai_parse_id')) {
                \App\Models\AiInvoiceParse::where('company_id', $companyId)
                    ->where('id', (int) $request->input('ai_parse_id'))
                    ->whereNull('invoice_id')
                    ->update(['invoice_id' => $invoice->id]);
            }

            DB::commit();
            return redirect('/invoices')->with('success', 'Invoice created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to create invoice: ' . $e->getMessage())->withInput();
        }
    }

    public static function detectBuyerRegistrationType(?string $buyerNtn, ?string $buyerCnic = null): string
    {
        if (!empty($buyerNtn)) {
            $clean = preg_replace('/[^0-9]/', '', $buyerNtn);
            if (strlen($clean) >= 7) return 'Registered';
        }
        if (!empty($buyerCnic)) {
            $clean = preg_replace('/[^0-9]/', '', $buyerCnic);
            if (strlen($clean) >= 13) return 'Registered';
        }
        return 'Unregistered';
    }

    public function show(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        $invoice->load('items', 'company', 'activityLogs.user', 'branch', 'deliveries.user');

        $complianceReport = ComplianceReport::where('invoice_id', $invoice->id)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($complianceReport
            && !$complianceReport->is_fbr_validated
            && $invoice->status === 'locked'
            && $invoice->fbr_status === 'production'
            && !empty($invoice->fbr_invoice_number)
        ) {
            HybridComplianceScorer::postFbrValidation($invoice);
            $complianceReport->refresh();
        }

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
        $servicesTaxRate = ScheduleEngine::servicesRateForProvince($invoice->supplier_province ?? $company->province ?? null);
        $branchServicesRates = $branches->mapWithKeys(fn ($b) => [$b->id => ScheduleEngine::servicesRateForProvince($b->province ?: ($company->province ?? null))]);
        return view('invoice.edit', compact('invoice', 'branches', 'standardTaxRate', 'provinces', 'servicesTaxRate', 'branchServicesRates'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Locked invoices cannot be edited.');
        }

        $buyerRegTypeInput = $request->input('buyer_registration_type');
        if (!$buyerRegTypeInput || !in_array($buyerRegTypeInput, ['Registered', 'Unregistered'])) {
            $buyerRegTypeInput = self::detectBuyerRegistrationType($request->buyer_ntn, $request->buyer_cnic);
        }

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'nullable|string|max:50',
            'buyer_cnic' => 'nullable|string|max:15',
            'buyer_address' => 'required|string|max:500',
            'branch_id' => 'nullable|exists:branches,id',
            'document_type' => 'required|string|in:Sale Invoice,Credit Note,Debit Note',
            'reference_invoice_number' => $request->input('document_type') !== 'Sale Invoice' ? 'required|string|max:255' : 'nullable|string|max:255',
            'destination_province' => 'required|string|max:100',
            'invoice_date' => 'nullable|date|before_or_equal:today',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0.01',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated,fed_services,services',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.tax_rate' => 'nullable|integer|min:0|max:100',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
            'items.*.default_uom' => 'nullable|string|max:100',
            'items.*.st_withheld_at_source' => 'nullable',
            'items.*.petroleum_levy' => 'nullable|numeric|min:0',
            'items.*.further_tax' => 'nullable|numeric|min:0',
        ], [
            'document_type.required' => 'Document type is required.',
            'destination_province.required' => 'Destination Province is required.',
            'reference_invoice_number.required' => 'Reference Invoice is required for Credit/Debit Notes.',
            'invoice_date.before_or_equal' => 'Invoice date cannot be in the future — FBR rejects future-dated invoices.',
            'items.*.price.min' => 'Item prices must be greater than Rs 0. FBR rejects free/bonus lines (error 0300). Note the free item in the description of a paid line or omit it.',
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

            $updateData = [
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'buyer_cnic' => $request->buyer_cnic,
                'buyer_address' => $request->buyer_address,
                'buyer_registration_type' => $buyerRegTypeInput,
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
                // Keep the date the invoice already carries unless the form
                // sent a new one — blanking it would push the sale onto
                // created_at in the FBR payload and in every report.
                'invoice_date' => $request->filled('invoice_date')
                    ? \Illuminate\Support\Carbon::parse($request->input('invoice_date'))->toDateString()
                    : ($invoice->invoice_date ?: now()->toDateString()),
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
                    'tax_rate' => $this->extractTaxRate($item, $supplierProvince),
                    'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                    'serial_no' => $item['serial_no'] ?? null,
                    'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
                    'default_uom' => $item['default_uom'] ?? ($hsResolved['default_uom'] ?? 'Numbers, pieces, units'),
                    'sale_type' => $saleType,
                    'st_withheld_at_source' => !empty($item['st_withheld_at_source']),
                    'petroleum_levy' => !empty($item['petroleum_levy']) ? floatval($item['petroleum_levy']) : null,
                    'further_tax' => !empty($item['further_tax']) ? floatval($item['further_tax']) : 0,
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

    /**
     * Task 1245: bulk-submit selected draft invoices to FBR.
     * Queues one BulkSubmitInvoiceJob per invoice; the list page polls
     * bulkSubmitStatus() for per-invoice results.
     */
    public function bulkSubmit(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'invoice_ids' => 'nullable|array|max:1000',
            'invoice_ids.*' => 'integer',
            'select_all_drafts' => 'nullable|boolean',
            'status' => 'nullable|in:draft,failed',
        ]);

        // Task 1250: the Failed tab reuses this endpoint to bulk-retry failed invoices.
        $status = $request->input('status', 'draft');
        $noun = $status === 'failed' ? 'failed' : 'draft';

        $selectAll = $request->boolean('select_all_drafts');
        $ids = $request->input('invoice_ids', []);
        if (!$selectAll && empty($ids)) {
            return response()->json(['status' => 'error', 'message' => "Select at least one {$noun} invoice."], 422);
        }

        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->first();
        if ($subscription && ($subscription->isExpired() || ($subscription->trial_ends_at && $subscription->isTrialExpired()))) {
            return response()->json(['status' => 'error', 'message' => 'Your subscription has expired. Invoices stay as drafts.'], 422);
        }

        $query = Invoice::where('company_id', $companyId)
            ->where('status', $status)
            ->where('is_fbr_processing', false)
            ->whereNull('fbr_invoice_number');
        if (!$selectAll) {
            $query->whereIn('id', $ids);
        }
        $invoiceIds = $query->orderBy('id')->limit(1000)->pluck('id')->all();

        if (empty($invoiceIds)) {
            return response()->json(['status' => 'error', 'message' => "No submittable {$noun} invoices in your selection."], 422);
        }

        // One bulk run per company at a time — a second click while a batch
        // is running just re-attaches to the running batch.
        $lockKey = \App\Jobs\BulkSubmitInvoiceJob::runningLockKey($companyId);
        $batchKey = $companyId . '-' . now()->format('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
        if (!Cache::add($lockKey, $batchKey, now()->addMinutes(60))) {
            $existing = Cache::get($lockKey);
            return response()->json([
                'status' => 'already_running',
                'message' => 'A bulk submit is already in progress.',
                'batch_key' => $existing,
            ], 409);
        }

        \App\Jobs\BulkSubmitInvoiceJob::startBatch($batchKey, $companyId, $invoiceIds);
        foreach ($invoiceIds as $id) {
            \App\Jobs\BulkSubmitInvoiceJob::dispatch($id, $batchKey, auth()->id());
        }

        AuditLogService::log('invoice_bulk_submit_started', 'Invoice', null, null, [
            'batch_key' => $batchKey,
            'count' => count($invoiceIds),
        ]);

        return response()->json([
            'status' => 'queued',
            'batch_key' => $batchKey,
            'total' => count($invoiceIds),
        ]);
    }

    /** Task 1245: progress/results of a bulk submit batch (polled by the list). */
    public function bulkSubmitStatus(Request $request)
    {
        $companyId = app('currentCompanyId');
        $batchKey = (string) $request->query('batch_key', '');
        if ($batchKey === '') {
            // Task 1249: no key = "is anything running for my company?" —
            // resolve the running lock so a reloaded page can re-attach.
            $batchKey = (string) (Cache::get(\App\Jobs\BulkSubmitInvoiceJob::runningLockKey($companyId)) ?? '');
        }
        $batch = $batchKey !== '' ? Cache::get(\App\Jobs\BulkSubmitInvoiceJob::cacheKey($batchKey)) : null;

        if (!$batch || (int) ($batch['company_id'] ?? 0) !== (int) $companyId) {
            return response()->json(['status' => 'not_found'], 404);
        }

        // Results are stored keyed by invoice id (write-once dedupe) — expose a plain list.
        $batch['results'] = array_values($batch['results'] ?? []);

        return response()->json(['status' => 'ok', 'batch_key' => $batchKey, 'batch' => $batch]);
    }

    public function submit(Request $request, Invoice $invoice)
    {
        if (in_array($invoice->status, ['locked', 'pending_verification']) || $invoice->is_fbr_processing) {
            $msg = match(true) {
                $invoice->status === 'locked' => 'Invoice already locked and submitted to FBR.',
                $invoice->status === 'pending_verification' => 'Invoice is pending FBR verification. Please wait.',
                $invoice->is_fbr_processing => 'Invoice is currently being processed. Please wait.',
                default => 'Invoice cannot be submitted.',
            };
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        $companyId = $invoice->company_id;
        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        if ($subscription && $subscription->isExpired()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Subscription expired', 'error_details' => 'Your subscription has expired.'], 422);
            }
            return redirect('/invoices')->with('error', 'Your subscription has expired.');
        }

        if ($subscription && $subscription->trial_ends_at && $subscription->isTrialExpired()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Trial ended', 'error_details' => 'Your trial period has ended.'], 422);
            }
            return redirect('/invoices')->with('error', 'Your trial period has ended.');
        }

        $mode = $request->input('mode', 'smart');
        $fbrEnvironment = $request->input('fbr_environment');
        $invoice->load('items', 'company');

        if (!empty($invoice->fbr_invoice_number)) {
            $msg = 'Invoice already has FBR number: ' . $invoice->fbr_invoice_number . '. Cannot resubmit.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Already submitted', 'error_details' => $msg], 422);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if ($invoice->status === 'pending_verification') {
            $msg = 'Invoice is pending FBR verification. Please check FBR portal first.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Pending verification', 'error_details' => $msg], 422);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if ($invoice->status === 'locked') {
            $msg = 'Invoice is already locked. Cannot resubmit.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Already locked', 'error_details' => $msg], 422);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

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
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Validation failed', 'error_details' => $errorHtml], 422);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $errorHtml);
        }

        $riskAnalysis = RiskIntelligenceEngine::analyzeForPreSubmission($invoice);
        $isInternalCompany = $invoice->company && $invoice->company->is_internal_account;

        if ($riskAnalysis['should_block'] && !$isInternalCompany) {
            $riskMessages = array_map(fn($r) => $r['message'], $riskAnalysis['risks']);
            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'intelligence_warning', [
                'risk_score' => $riskAnalysis['risk_score'],
                'risk_level' => $riskAnalysis['risk_level'],
                'risks' => $riskMessages,
                'note' => 'Proceeding with submission despite risk warning',
            ]);
        }

        IntelligenceProcessingJob::dispatch($invoice->id);

        if ($mode === 'direct_mis') {
            if (!in_array(auth()->user()->role, ['company_admin', 'super_admin'])) {
                return redirect('/invoice/' . $invoice->id)->with('error', 'Only company admins can use Direct MIS mode.');
            }

            $request->validate(['override_reason' => 'required|string|min:10|max:500']);

            $locked = DB::transaction(function () use ($invoice, $request) {
                $lockedInvoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
                if (!$lockedInvoice || !in_array($lockedInvoice->status, ['draft', 'failed']) || $lockedInvoice->is_fbr_processing) {
                    return false;
                }
                $lockedInvoice->status = 'draft';
                $lockedInvoice->is_fbr_processing = true;
                $lockedInvoice->submitted_at = now();
                $lockedInvoice->submission_mode = 'direct_mis';
                $lockedInvoice->override_reason = $request->override_reason;
                $lockedInvoice->override_by = auth()->id();
                $lockedInvoice->save();
                return true;
            });

            if (!$locked) {
                $lockMsg = 'Invoice is no longer in a submittable state. It may have been submitted by another request.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['status' => 'error', 'message' => 'Lock failed', 'error_details' => $lockMsg], 409);
                }
                return redirect('/invoice/' . $invoice->id)->with('error', $lockMsg);
            }

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

            if ($invoice->buyer_ntn) {
                $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
                VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
            }

            $invoice->refresh();
            $result = $this->submitToFbrSync($invoice, $fbrEnvironment);

            if ($result['status'] === 'success') {
                $fbrNum = $result['fbr_invoice_number'] ?? '';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'status' => 'success',
                        'invoice_id' => $invoice->id,
                        'fbr_invoice_number' => $fbrNum,
                        'pdf_url' => '/invoice/' . $invoice->id . '/pdf',
                        'execution_ms' => $result['execution_ms'],
                        'message' => 'Invoice submitted via Direct MIS mode',
                    ]);
                }
                return redirect('/invoice/' . $invoice->id)->with('success', 'FBR submission successful! Invoice Number: ' . $fbrNum . ' (' . $result['execution_ms'] . 'ms)');
            }

            if ($result['status'] === 'pending_verification') {
                $warningMsg = 'FBR returned an ambiguous response. Please verify on FBR portal.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'status' => 'pending_verification',
                        'invoice_id' => $invoice->id,
                        'message' => $warningMsg,
                    ]);
                }
                return redirect('/invoice/' . $invoice->id)->with('warning', $warningMsg);
            }

            $errorMsg = 'FBR Direct MIS submission failed';
            if (!empty($result['errors'])) {
                $errorMsg .= ': ' . implode(' | ', array_slice($result['errors'], 0, 3));
            }
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'invoice_id' => $invoice->id,
                    'message' => 'FBR submission failed',
                    'error_details' => $errorMsg,
                ], 422);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $errorMsg);
        }

        $scoreResult = HybridComplianceScorer::score($invoice);

        if ($scoreResult['risk_level'] === 'CRITICAL') {
            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'compliance_warning', [
                'reason' => 'CRITICAL risk level (proceeding with submission)',
                'score' => $scoreResult['final_score'],
                'rule_flags' => $scoreResult['rule_result']['flags'],
            ]);
        }

        $locked = DB::transaction(function () use ($invoice, $scoreResult) {
            $lockedInvoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
            if (!$lockedInvoice || !in_array($lockedInvoice->status, ['draft', 'failed']) || $lockedInvoice->is_fbr_processing) {
                return false;
            }
            $lockedInvoice->status = 'draft';
            $lockedInvoice->is_fbr_processing = true;
            $lockedInvoice->submitted_at = now();
            $lockedInvoice->submission_mode = 'smart';
            $lockedInvoice->save();
            return true;
        });

        if (!$locked) {
            $lockMsg = 'Invoice is no longer in a submittable state. It may have been submitted by another request.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => 'error', 'message' => 'Lock failed', 'error_details' => $lockMsg], 409);
            }
            return redirect('/invoice/' . $invoice->id)->with('error', $lockMsg);
        }

        $invoice->refresh();

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

        if ($invoice->buyer_ntn) {
            $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
            VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
        }

        $result = $this->submitToFbrSync($invoice, $fbrEnvironment);

        if ($result['status'] === 'success') {
            $fbrNum = $result['fbr_invoice_number'] ?? '';
            $successMsg = 'FBR submission successful! Invoice Number: ' . $fbrNum . ' (Score: ' . $scoreResult['final_score'] . ', ' . $result['execution_ms'] . 'ms)';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'success',
                    'invoice_id' => $invoice->id,
                    'fbr_invoice_number' => $fbrNum,
                    'pdf_url' => '/invoice/' . $invoice->id . '/pdf',
                    'execution_ms' => $result['execution_ms'],
                    'compliance_score' => $scoreResult['final_score'],
                    'message' => 'Invoice successfully submitted to FBR',
                ]);
            }
            return redirect('/invoice/' . $invoice->id)->with('success', $successMsg);
        }

        if ($result['status'] === 'pending_verification') {
            $warningMsg = 'FBR returned an ambiguous response (' . $result['execution_ms'] . 'ms). Please verify on FBR portal and confirm.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'pending_verification',
                    'invoice_id' => $invoice->id,
                    'message' => $warningMsg,
                ]);
            }
            return redirect('/invoice/' . $invoice->id)->with('warning', $warningMsg);
        }

        $errorMsg = 'FBR submission failed';
        if (!empty($result['errors'])) {
            $errorMsg .= ': ' . implode(' | ', array_slice($result['errors'], 0, 3));
        }
        $errorMsg .= ' (' . $result['execution_ms'] . 'ms)';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'error',
                'invoice_id' => $invoice->id,
                'message' => 'FBR submission failed',
                'error_details' => $errorMsg,
            ], 422);
        }
        return redirect('/invoice/' . $invoice->id)->with('error', $errorMsg);
    }

    public function retry(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            abort(403);
        }

        $jsonResponse = $request->expectsJson() || $request->ajax();

        if (!in_array($invoice->status, ['draft', 'failed'])) {
            $msg = 'Only draft or failed invoices can be retried.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if ($invoice->is_fbr_processing) {
            $msg = 'Invoice is currently being processed. Please wait.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        $dispatched = \DB::transaction(function () use ($invoice) {
            $locked = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
            if (!in_array($locked->status, ['draft', 'failed']) || $locked->is_fbr_processing) {
                return false;
            }
            $locked->status = 'draft';
            $locked->is_fbr_processing = true;
            $locked->fbr_status = 'pending';
            $locked->submitted_at = now();
            $locked->save();
            return true;
        });

        if (!$dispatched) {
            $msg = 'Invoice status changed. Cannot retry.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        $invoice->refresh();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'retry_submitted', [
            'retried_by' => auth()->user()->name,
        ], request()->ip());

        AuditLogService::log('invoice_retry', 'Invoice', $invoice->id, null, [
            'retried_by' => auth()->user()->name,
        ]);

        $result = $this->submitToFbrSync($invoice);
        $jsonResponse = $request->expectsJson() || $request->ajax();

        if ($result['status'] === 'success') {
            $fbrNum = $result['fbr_invoice_number'] ?? '';
            if ($jsonResponse) {
                return response()->json([
                    'status' => 'success',
                    'invoice_id' => $invoice->id,
                    'fbr_invoice_number' => $fbrNum,
                    'pdf_url' => '/invoice/' . $invoice->id . '/pdf',
                    'execution_ms' => $result['execution_ms'],
                    'message' => 'Invoice successfully submitted to FBR',
                ]);
            }
            return redirect('/invoice/' . $invoice->id)->with('success', 'FBR retry successful! Invoice Number: ' . $fbrNum . ' (' . $result['execution_ms'] . 'ms)');
        }

        if ($result['status'] === 'pending_verification') {
            $warningMsg = 'FBR returned an ambiguous response. Please verify on FBR portal.';
            if ($jsonResponse) {
                return response()->json([
                    'status' => 'pending_verification',
                    'invoice_id' => $invoice->id,
                    'message' => $warningMsg,
                ]);
            }
            return redirect('/invoice/' . $invoice->id)->with('warning', $warningMsg);
        }

        $errorMsg = 'FBR retry failed';
        if (!empty($result['errors'])) {
            $errorMsg .= ': ' . implode(' | ', array_slice($result['errors'], 0, 3));
        }
        if ($jsonResponse) {
            return response()->json([
                'status' => 'error',
                'invoice_id' => $invoice->id,
                'message' => 'FBR retry failed',
                'error_details' => $errorMsg,
            ], 422);
        }
        return redirect('/invoice/' . $invoice->id)->with('error', $errorMsg);
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

        $jsonResponse = $request->expectsJson() || $request->ajax();

        if ($invoice->status === 'locked') {
            $msg = 'Invoice already submitted to FBR' . (!empty($invoice->fbr_invoice_number) ? ' with number: ' . $invoice->fbr_invoice_number : '') . '. Cannot resubmit.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if ($invoice->status === 'pending_verification') {
            $msg = 'Invoice is pending FBR verification. Please check FBR portal first before resubmitting.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if (!in_array($invoice->status, ['draft', 'failed'])) {
            $msg = 'Invoice cannot be submitted in current status.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if ($invoice->is_fbr_processing) {
            $msg = 'Invoice is currently being processed. Please wait.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if (!empty($invoice->fbr_invoice_number)) {
            $msg = 'Invoice already has FBR number: ' . $invoice->fbr_invoice_number . '. Cannot resubmit.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        $locked = DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
            if (!$lockedInvoice || !in_array($lockedInvoice->status, ['draft', 'failed']) || $lockedInvoice->is_fbr_processing) {
                return false;
            }
            $lockedInvoice->status = 'draft';
            $lockedInvoice->is_fbr_processing = true;
            $lockedInvoice->submitted_at = now();
            $lockedInvoice->save();
            return true;
        });

        if (!$locked) {
            $msg = 'Invoice is no longer in a submittable state.';
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        $invoice->refresh();
        $invoice->load('items', 'company');
        $company = $invoice->company;

        $fbrService = new \App\Services\FbrService();
        try {
            $response = $fbrService->submitInvoice($invoice, 0);
        } catch (\Exception $e) {
            \Log::error("FBR Resubmit: Invoice #{$invoice->id} blocked: " . $e->getMessage());
            $invoice->status = 'draft';
            $invoice->is_fbr_processing = false;
            $invoice->save();
            $msg = 'FBR submission blocked: ' . $e->getMessage();
            if ($jsonResponse) return response()->json(['status' => 'error', 'message' => $msg, 'error_details' => $msg], 422);
            return redirect('/invoice/' . $invoice->id)->with('error', $msg);
        }

        if ($response['status'] === 'success') {
            $fbrNum = $response['fbr_invoice_number'] ?? null;
            if ($fbrNum) {
                $invoice->fbr_invoice_number = $fbrNum;
                $invoice->fbr_invoice_id = $fbrNum;
                $invoice->fbr_submission_date = now();
            }
            $invoice->status = 'locked';
            $invoice->fbr_status = 'production';
            $invoice->is_fbr_processing = false;
            $invoice->integrity_hash = \App\Services\IntegrityHashService::generate($invoice);
            $invoice->qr_data = json_encode([
                'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $company->fbr_registration_no ?: ($company->ntn ?? '')),
                'fbr_invoice_number' => $fbrNum ?? $invoice->invoice_number,
                'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                'totalValues' => $invoice->total_amount,
            ]);
            $invoice->save();

            $company->update(['last_successful_submission' => now()]);
            $this->createLedgerEntry($invoice);

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

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'success',
                    'invoice_id' => $invoice->id,
                    'fbr_invoice_number' => $fbrNum,
                    'pdf_url' => '/invoice/' . $invoice->id . '/pdf',
                    'message' => 'Invoice successfully submitted to FBR',
                ]);
            }
            return redirect('/invoice/' . $invoice->id)->with('success', 'FBR submission successful! Invoice Number: ' . $fbrNum);
        }

        if ($response['status'] === 'pending_verification') {
            $invoice->status = 'pending_verification';
            $invoice->fbr_status = 'pending_verification';
            $invoice->is_fbr_processing = false;
            $invoice->save();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'pending_verification',
                    'invoice_id' => $invoice->id,
                    'message' => 'FBR response was ambiguous. Invoice marked for manual verification. Check FBR portal.',
                ]);
            }
            return redirect('/invoice/' . $invoice->id)->with('warning', 'FBR response was ambiguous. Invoice marked for manual verification. Check FBR portal.');
        }

        $invoice->status = 'failed';
        $invoice->fbr_status = 'failed';
        $invoice->is_fbr_processing = false;
        $invoice->save();

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

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'error',
                'invoice_id' => $invoice->id,
                'message' => 'FBR submission failed',
                'error_details' => $errorMsg,
            ], 422);
        }
        return redirect('/invoice/' . $invoice->id)->with('error', $errorMsg);
    }

    public function confirmFbrStatus(Request $request, Invoice $invoice)
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

        if ($invoice->status !== 'pending_verification') {
            return redirect('/invoice/' . $invoice->id)->with('error', 'This invoice is not pending verification.');
        }

        $action = $request->input('action');

        if ($action === 'confirm') {
            $fbrInvoiceNumber = $request->input('fbr_invoice_number');

            $invoice->status = 'locked';
            $invoice->fbr_status = 'production';
            $invoice->fbr_submission_date = $invoice->fbr_submission_date ?? now();

            if ($fbrInvoiceNumber) {
                $invoice->fbr_invoice_number = $fbrInvoiceNumber;
                $invoice->fbr_invoice_id = $fbrInvoiceNumber;
                $invoice->qr_data = json_encode([
                    'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $invoice->company->fbr_registration_no ?: ($invoice->company->ntn ?? '')),
                    'fbr_invoice_number' => $fbrInvoiceNumber,
                    'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                    'totalValues' => $invoice->total_amount,
                ]);
            }

            $invoice->integrity_hash = IntegrityHashService::generate($invoice);
            $invoice->save();

            $this->createLedgerEntry($invoice);

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'manually_confirmed', [
                'confirmed_by' => $user->name,
                'action' => 'confirmed_on_fbr_portal',
                'fbr_invoice_number' => $fbrInvoiceNumber,
            ], request()->ip());

            AuditLogService::log('invoice_manually_confirmed', 'Invoice', $invoice->id, null, [
                'confirmed_by' => $user->name,
                'fbr_invoice_number' => $fbrInvoiceNumber,
            ]);

            $msg = 'Invoice confirmed as submitted to FBR. Status updated to Locked.';
            if ($fbrInvoiceNumber) {
                $msg .= ' FBR Invoice #: ' . $fbrInvoiceNumber;
            }
            return redirect('/invoice/' . $invoice->id)->with('success', $msg);
        }

        if ($action === 'reject') {
            $invoice->status = 'draft';
            $invoice->fbr_status = null;
            $invoice->fbr_invoice_number = null;
            $invoice->submitted_at = null;
            $invoice->save();

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'verification_rejected', [
                'rejected_by' => $user->name,
                'action' => 'not_found_on_fbr_portal',
            ], request()->ip());

            AuditLogService::log('invoice_verification_rejected', 'Invoice', $invoice->id, null, [
                'rejected_by' => $user->name,
            ]);

            return redirect('/invoice/' . $invoice->id)->with('success', 'Invoice reset to Draft. You can edit and resubmit.');
        }

        return redirect('/invoice/' . $invoice->id)->with('error', 'Invalid action.');
    }

    public function updateFbrNumber(Request $request, Invoice $invoice)
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

        if (!in_array($invoice->status, ['locked', 'pending_verification', 'draft'])) {
            return redirect('/invoice/' . $invoice->id)->with('error', 'FBR number can only be updated on draft, locked or pending invoices.');
        }

        $request->validate(['fbr_invoice_number' => 'required|string|min:5|max:100']);

        $oldNumber = $invoice->fbr_invoice_number;
        $oldStatus = $invoice->status;
        $newNumber = $request->input('fbr_invoice_number');

        $existingInvoice = Invoice::where('fbr_invoice_number', $newNumber)
            ->where('id', '!=', $invoice->id)
            ->first();

        if ($existingInvoice) {
            return redirect('/invoice/' . $invoice->id)->with('error', 'This FBR Invoice Number is already assigned to Invoice #' . $existingInvoice->internal_invoice_number . '. Each invoice must have a unique FBR number.');
        }

        $invoice->fbr_invoice_number = $newNumber;
        $invoice->fbr_invoice_id = $newNumber;

        $company = $invoice->company;
        $invoice->qr_data = json_encode([
            'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $company->fbr_registration_no ?: ($company->ntn ?? '')),
            'fbr_invoice_number' => $newNumber,
            'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
            'totalValues' => $invoice->total_amount,
        ]);

        $invoice->status = 'locked';
        $invoice->fbr_status = 'production';
        $invoice->fbr_submission_date = $invoice->fbr_submission_date ?? now();

        $invoice->integrity_hash = IntegrityHashService::generate($invoice);

        try {
            $invoice->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return redirect('/invoice/' . $invoice->id)->with('error', 'This FBR Invoice Number is already in use. Please enter a unique number.');
        } catch (\Exception $e) {
            \Log::error('FBR number update failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            return redirect('/invoice/' . $invoice->id)->with('error', 'Failed to update FBR number. Please try again.');
        }

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'fbr_number_updated', [
            'old_number' => $oldNumber,
            'new_number' => $newNumber,
            'updated_by' => $user->name,
        ], request()->ip());

        AuditLogService::log('fbr_number_updated', 'Invoice', $invoice->id, null, [
            'old_number' => $oldNumber,
            'new_number' => $newNumber,
            'updated_by' => $user->name,
        ]);

        return redirect('/invoice/' . $invoice->id)->with('success', 'FBR Invoice Number updated: ' . $newNumber);
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

    private function buildPdfData(Invoice $invoice): array
    {
        // Extracted to InvoicePdfService (shared with share links, buyer
        // email attachments and the FBR Audit Pack builder). The ?wht_rate=
        // query fallback is preserved.
        $q = request()->query('wht_rate');
        return \App\Services\InvoicePdfService::buildData($invoice, is_numeric($q) ? floatval($q) : null);
    }

    public function pdf(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $data = $this->buildPdfData($invoice);
        $pdf = \App\Services\InvoicePdfService::make('invoice.pdf-bw', $data);
        $pdf->setPaper('A4', 'portrait');
        $filename = 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';

        return $pdf->stream($filename)->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function pdfBwPreview(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $data = $this->buildPdfData($invoice);
        $pdf = \App\Services\InvoicePdfService::make('invoice.pdf-bw', $data);
        $pdf->setPaper('A4', 'portrait');
        $filename = 'invoice-bw-preview-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';

        return $pdf->stream($filename)->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function download(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $data = $this->buildPdfData($invoice);
        $pdf = \App\Services\InvoicePdfService::make('invoice.pdf-bw', $data);
        $pdf->setPaper('A4', 'portrait');
        $filename = 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Bulk download all invoices in a date range (or month) as a single ZIP of PDFs.
     * Accepts: from=YYYY-MM-DD & to=YYYY-MM-DD  OR  month=YYYY-MM
     * Optional: fbr_status, doc_type, status (default: locked + pending_verification)
     */
    public function bulkDownloadPdf(Request $request)
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');

        $companyId = app('currentCompanyId');

        $from = $request->get('from') ?: $request->get('date_from');
        $to   = $request->get('to')   ?: $request->get('date_to');
        $month = $request->get('month');
        $all = $request->boolean('all');

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $from = $month . '-01';
            $to   = date('Y-m-t', strtotime($from));
        }

        // Validate date format only if provided (no longer required — empty = no date filter)
        $hasFrom = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
        $hasTo   = $to   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);
        if ($hasFrom && $hasTo && strtotime($from) > strtotime($to)) {
            return back()->with('error', 'Bulk PDF: "from" date must be on or before "to" date.');
        }

        $query = Invoice::where('company_id', $companyId)
            ->whereIn('status', ['locked', 'pending_verification']);

        // Apply date filter whenever provided — regardless of $all flag.
        // $all only means "no date required + auto-cap to latest 500".
        if (!$all || $hasFrom || $hasTo) {
            if ($hasFrom) $query->whereDate('invoice_date', '>=', $from);
            if ($hasTo)   $query->whereDate('invoice_date', '<=', $to);
        }

        if ($fs = $request->get('fbr_status')) {
            if (in_array($fs, ['production', 'sandbox', 'validated', 'pending', 'failed'])) {
                $query->where('fbr_status', $fs);
            }
        }
        if ($dt = $request->get('doc_type')) {
            if (in_array($dt, ['Sale Invoice', 'Credit Note', 'Debit Note'])) {
                $query->where('document_type', $dt);
            }
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            return back()->with('error', 'Bulk PDF: no completed invoices found for the selected filters.');
        }

        // Batch system: 500 invoices per ZIP. If total > 500 and no explicit batch,
        // show user a list of batch links so they can download all of them in chunks.
        $MAX = 500;
        $batch = max(1, (int) $request->get('batch', 0));
        $totalBatches = (int) ceil($total / $MAX);

        if ($total > $MAX && !$request->has('batch')) {
            // No explicit batch requested → tell user how to grab all of them.
            // Build batch URLs preserving current filter params.
            $params = $request->only(['from','to','date_from','date_to','month','fbr_status','doc_type','all']);
            $batchLinks = [];
            for ($b = 1; $b <= $totalBatches; $b++) {
                $batchLinks[] = [
                    'n'    => $b,
                    'from' => ($b - 1) * $MAX + 1,
                    'to'   => min($b * $MAX, $total),
                    'url'  => route('invoices.bulk-pdf', array_merge($params, ['batch' => $b])),
                ];
            }
            return back()
                ->with('bulk_pdf_batches', [
                    'total'    => $total,
                    'per_zip'  => $MAX,
                    'batches'  => $batchLinks,
                ])
                ->with('error', "Bulk PDF: filter ne {$total} invoices return ki hain. {$totalBatches} ZIP files mein download karein (500 per ZIP).");
        }

        // Order newest first, then offset/limit by batch
        $query = (clone $query)->orderByDesc('invoice_date')->orderByDesc('id');
        if ($total > $MAX) {
            $offset = ($batch - 1) * $MAX;
            $query = $query->offset($offset)->limit($MAX);
        }

        if (!class_exists(\ZipArchive::class)) {
            return back()->with('error', 'Bulk PDF: server is missing the PHP zip extension. Contact your hoster.');
        }

        $tmpDir = storage_path('app/tmp-bulk-pdf');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $rangeBase = ($hasFrom && $hasTo) ? ($from . '_to_' . $to) : ('all-' . date('Ymd'));
        $batchTag  = ($total > $MAX) ? ('-batch' . $batch . 'of' . $totalBatches) : '';
        $rangeTag  = $rangeBase . $batchTag;
        $zipPath = $tmpDir . '/bulk-invoices-' . $companyId . '-' . $rangeTag . '-' . uniqid() . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Bulk PDF: could not create zip file on server.');
        }

        $invoices = $all ? $query->get() : $query->orderBy('invoice_date')->orderBy('id')->get();
        $failed = [];
        $usedNames = [];

        foreach ($invoices as $invoice) {
            try {
                $data = $this->buildPdfData($invoice);
                $pdf = \App\Services\InvoicePdfService::make('invoice.pdf-bw', $data);
                $pdf->setPaper('A4', 'portrait');

                $base = $invoice->fbr_invoice_number
                    ?: ($invoice->internal_invoice_number
                        ?: ($invoice->invoice_number ?: ('invoice-' . $invoice->id)));
                $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $base);
                $name = $safe . '.pdf';
                $n = 1;
                while (isset($usedNames[$name])) {
                    $name = $safe . '__' . (++$n) . '.pdf';
                }
                $usedNames[$name] = true;

                $zip->addFromString($name, $pdf->output());
            } catch (\Throwable $e) {
                $failed[] = ($invoice->internal_invoice_number ?: $invoice->id) . ': ' . $e->getMessage();
            }
        }

        // Manifest CSV inside the zip
        $manifest = "Internal No,FBR No,Date,Buyer,NTN,Subtotal,Sales Tax,WHT,Total,Status\n";
        foreach ($invoices as $inv) {
            $manifest .= implode(',', [
                '"' . ($inv->internal_invoice_number ?? '') . '"',
                '"' . ($inv->fbr_invoice_number ?? '') . '"',
                '"' . $inv->invoice_date . '"',
                '"' . str_replace('"', '""', $inv->buyer_name ?? '') . '"',
                '"' . ($inv->buyer_ntn ?? '') . '"',
                number_format((float)($inv->total_value_excluding_st ?? ($inv->total_amount - $inv->total_sales_tax)), 2, '.', ''),
                number_format((float)$inv->total_sales_tax, 2, '.', ''),
                number_format((float)$inv->wht_amount, 2, '.', ''),
                number_format((float)$inv->total_amount, 2, '.', ''),
                '"' . $inv->status . '"',
            ]) . "\n";
        }
        $zip->addFromString('_manifest.csv', $manifest);
        if (!empty($failed)) {
            $zip->addFromString('_failed.txt', implode("\n", $failed));
        }

        $zip->close();

        $company = \App\Models\Company::find($companyId);
        $companySlug = $company ? preg_replace('/[^A-Za-z0-9._-]+/', '_', $company->name) : 'company';
        $downloadName = "invoices_{$companySlug}_{$rangeTag}.zip";

        // Load full ZIP into memory string, delete temp file, return as single response.
        // This avoids streaming/chunking truncation seen on php artisan serve / cPanel proxies.
        // Memory: ini_set('memory_limit', '1024M') is set at top of method — safe up to ~500MB ZIPs.
        $content = file_get_contents($zipPath);
        @unlink($zipPath);

        if ($content === false || $content === '') {
            return back()->with('error', 'Bulk PDF: failed to read generated ZIP file.');
        }

        $size = strlen($content);

        // Kill any prior output buffering so headers + binary body go cleanly
        while (ob_get_level() > 0) { @ob_end_clean(); }

        return response($content, 200, [
            'Content-Type'              => 'application/zip',
            'Content-Disposition'       => 'attachment; filename="' . $downloadName . '"',
            'Content-Length'            => (string) $size,
            'Content-Transfer-Encoding' => 'binary',
            'Cache-Control'             => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'                    => 'public',
            'X-Accel-Buffering'         => 'no',
        ]);
    }

    public function updateWht(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) abort(403);

        if ($invoice->status !== 'draft') {
            return redirect()->back()->with('error', 'WHT rate can only be modified on draft invoices.');
        }

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
            'wht_locked' => true,
        ]);

        return redirect('/invoice/' . $invoice->id . '/download');
    }

    public function updateWhtAjax(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($invoice->wht_locked) {
            return response()->json(['status' => 'error', 'message' => 'WHT rate is already locked on this invoice.'], 422);
        }

        if ($invoice->status === 'pending_verification') {
            return response()->json(['status' => 'error', 'message' => 'Cannot modify WHT while invoice is pending FBR verification.'], 422);
        }

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
            'wht_locked' => true,
        ]);

        return response()->json([
            'status' => 'ok',
            'wht_rate' => $whtRate,
            'wht_amount' => $whtAmount,
            'net_receivable' => $netReceivable,
        ]);
    }

    public function correctWhtAjax(Request $request, Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if (!$invoice->wht_locked) {
            return response()->json(['status' => 'error', 'message' => 'WHT is not locked yet. Use the lock flow first.'], 422);
        }

        if ($invoice->status === 'pending_verification') {
            return response()->json(['status' => 'error', 'message' => 'Cannot modify WHT while invoice is pending FBR verification.'], 422);
        }

        $request->validate([
            'wht_rate' => 'required|numeric|min:0|max:100',
        ]);

        $newRate = floatval($request->wht_rate);
        $oldRate = floatval($invoice->wht_rate);
        $oldAmount = floatval($invoice->wht_amount ?? 0);

        $subtotal = $invoice->items->sum(fn($item) => $item->price * $item->quantity);
        $totalTax = $invoice->items->sum('tax');
        $whtAmount = round($subtotal * ($newRate / 100), 2);
        $netReceivable = round(($subtotal + $totalTax) + $whtAmount, 2);

        try {
            \App\Models\AuditLog::create([
                'company_id' => $companyId,
                'user_id' => auth()->id(),
                'action' => 'wht_rate_corrected',
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                'details' => json_encode([
                    'old_rate' => $oldRate,
                    'new_rate' => $newRate,
                    'old_amount' => $oldAmount,
                    'new_amount' => $whtAmount,
                    'invoice_number' => $invoice->internal_invoice_number ?? $invoice->invoice_number,
                ]),
                'ip_address' => $request->ip(),
                'hash' => hash('sha256', $invoice->id . $oldRate . $newRate . now()->timestamp),
            ]);
        } catch (\Exception $e) {
        }

        $invoice->update([
            'wht_rate' => $newRate,
            'wht_amount' => $whtAmount,
            'net_receivable' => $netReceivable,
        ]);

        return response()->json([
            'status' => 'ok',
            'wht_rate' => $newRate,
            'wht_amount' => $whtAmount,
            'net_receivable' => $netReceivable,
            'message' => 'WHT rate corrected from ' . $oldRate . '% to ' . $newRate . '%',
        ]);
    }

    public function whtManagement(Request $request)
    {
        $companyId = app('currentCompanyId');

        $query = Invoice::where('company_id', $companyId)
            ->where('wht_locked', true)
            ->with('items')
            ->orderBy('created_at', 'desc');

        if ($request->filled('filter')) {
            $filter = $request->filter;
            if ($filter === 'with_wht') {
                $query->where('wht_rate', '>', 0);
            } elseif ($filter === 'no_wht') {
                $query->where('wht_rate', 0);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $like = \App\Helpers\DbCompat::like();
            $query->where(function($q) use ($search, $like) {
                $q->where('buyer_name', $like, "%{$search}%")
                  ->orWhere('internal_invoice_number', $like, "%{$search}%")
                  ->orWhere('fbr_invoice_number', $like, "%{$search}%")
                  ->orWhere('invoice_number', $like, "%{$search}%");
            });
        }

        $invoices = $query->paginate(25);

        $stats = [
            'total_locked' => Invoice::where('company_id', $companyId)->where('wht_locked', true)->count(),
            'with_wht' => Invoice::where('company_id', $companyId)->where('wht_locked', true)->where('wht_rate', '>', 0)->count(),
            'no_wht' => Invoice::where('company_id', $companyId)->where('wht_locked', true)->where('wht_rate', 0)->count(),
            'total_wht_amount' => Invoice::where('company_id', $companyId)->where('wht_locked', true)->sum('wht_amount'),
        ];

        return view('invoice.wht-management', compact('invoices', 'stats'));
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
            $validationResult['fbr_status'] = 'warning';
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

    public function statusJson(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => $invoice->status,
            'fbr_status' => $invoice->fbr_status,
            'fbr_invoice_number' => $invoice->fbr_invoice_number,
            'share_uuid' => $invoice->share_uuid,
            'display_invoice_number' => $invoice->display_invoice_number,
            'wht_rate' => $invoice->wht_rate ?? 0,
            'wht_locked' => (bool) $invoice->wht_locked,
            'updated_at' => $invoice->updated_at?->toIso8601String(),
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

    public function duplicate(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            abort(403);
        }

        $invoice->load('items');

        DB::beginTransaction();
        try {
            $newInvoiceNumber = InvoiceNumberingService::generateNextNumber($companyId);

            $newInvoice = Invoice::create([
                'company_id' => $invoice->company_id,
                'invoice_number' => $newInvoiceNumber,
                'internal_invoice_number' => $newInvoiceNumber,
                'buyer_name' => $invoice->buyer_name,
                'buyer_ntn' => $invoice->buyer_ntn,
                'buyer_cnic' => $invoice->buyer_cnic,
                'buyer_address' => $invoice->buyer_address,
                'buyer_registration_type' => $invoice->buyer_registration_type,
                'total_amount' => $invoice->total_amount,
                'total_value_excluding_st' => $invoice->total_value_excluding_st,
                'total_sales_tax' => $invoice->total_sales_tax,
                'wht_rate' => $invoice->wht_rate,
                'wht_amount' => $invoice->wht_amount,
                'wht_locked' => false,
                'net_receivable' => $invoice->net_receivable,
                'branch_id' => $invoice->branch_id,
                'document_type' => $invoice->document_type,
                'reference_invoice_number' => $invoice->reference_invoice_number,
                'supplier_province' => $invoice->supplier_province,
                'destination_province' => $invoice->destination_province,
                'invoice_date' => now()->toDateString(),
                'status' => 'draft',
                'fbr_status' => null,
                'fbr_invoice_number' => null,
                'fbr_invoice_id' => null,
                'fbr_submission_date' => null,
                'qr_data' => null,
                'integrity_hash' => null,
                'submitted_at' => null,
                'override_reason' => null,
                'override_by' => null,
                'submission_mode' => null,
                'fbr_submission_hash' => null,
            ]);

            foreach ($invoice->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $newInvoice->id,
                    'hs_code' => $item->hs_code,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'tax' => $item->tax,
                    'schedule_type' => $item->schedule_type,
                    'pct_code' => $item->pct_code,
                    'tax_rate' => $item->tax_rate,
                    'sro_schedule_no' => $item->sro_schedule_no,
                    'serial_no' => $item->serial_no,
                    'mrp' => $item->mrp,
                    'default_uom' => $item->default_uom,
                    'sale_type' => $item->sale_type,
                    'st_withheld_at_source' => $item->st_withheld_at_source,
                    'petroleum_levy' => $item->petroleum_levy,
                ]);
            }

            AuditLogService::log('invoice_duplicated', 'Invoice', $newInvoice->id, null, [
                'source_invoice_id' => $invoice->id,
                'source_invoice_number' => $invoice->internal_invoice_number,
                'new_invoice_number' => $newInvoiceNumber,
                'user' => auth()->user()->name,
            ]);

            InvoiceActivityService::log($newInvoice->id, $companyId, 'duplicated', [
                'source_invoice_id' => $invoice->id,
                'source_invoice_number' => $invoice->internal_invoice_number,
                'items_count' => $invoice->items->count(),
            ]);

            DB::commit();
            return redirect('/invoice/' . $newInvoice->id)->with('success', 'Invoice duplicated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to duplicate invoice: ' . $e->getMessage());
        }
    }

    public function destroy(Invoice $invoice)
    {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId) {
            abort(403);
        }

        if (!in_array($invoice->status, ['draft', 'failed'])) {
            return back()->with('error', 'Only draft or failed invoices can be deleted.');
        }

        DB::beginTransaction();
        try {
            AuditLogService::log('invoice_deleted', 'Invoice', $invoice->id, null, [
                'invoice_number' => $invoice->internal_invoice_number,
                'buyer_name' => $invoice->buyer_name,
                'total_amount' => $invoice->total_amount,
                'user' => auth()->user()->name,
            ]);

            $invoice->items()->delete();
            $invoice->deliveries()->delete();
            $invoice->delete();

            DB::commit();
            $tab = $invoice->status === 'failed' ? 'failed' : 'draft';
            return redirect('/invoices?tab=' . $tab)->with('success', 'Invoice deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to delete invoice: ' . $e->getMessage());
        }
    }

    /** Public: shared with the DI invoice push API (Task 1231). */
    public function extractTaxRate(array $item, ?string $supplierProvince = null): float
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
        return ScheduleEngine::getTaxRate($item['schedule_type'] ?? 'standard', $supplierProvince);
    }

    /**
     * Public: also invoked by the DI invoice push API (Task 1231) so API
     * submissions share every panel side effect (FBR log, ledger entry,
     * integrity hash, compliance recalcs). Caller must have set
     * is_fbr_processing under lock first.
     */
    public function submitToFbrSync(Invoice $invoice, ?string $fbrEnvironment = null): array
    {
        $invoice->load(['company', 'items']);
        $company = $invoice->company;

        if (!$invoice->is_fbr_processing) {
            return ['status' => 'failed', 'errors' => ['Invoice is not in processing state'], 'execution_ms' => 0];
        }

        if ($fbrEnvironment && in_array($fbrEnvironment, ['sandbox', 'production'])) {
            $company->fbr_environment = $fbrEnvironment;
        }

        $environment = $company->fbr_environment ?? 'sandbox';
        $startTime = microtime(true);

        try {
            $fbrService = new FbrService();

            $payload = $fbrService->buildPayload($invoice);
            $preErrors = $fbrService->validatePayloadPreSubmission($payload, $company);
            if (!empty($preErrors)) {
                $executionMs = round((microtime(true) - $startTime) * 1000);
                $errorMessages = array_map(fn($e) => "[{$e['code']}] {$e['message']}", $preErrors);
                Log::warning("FBR Pre-Validation Failed: Invoice #{$invoice->id}", $preErrors);

                $invoice->status = 'failed';
                $invoice->fbr_status = 'validation_failed';
                $invoice->is_fbr_processing = false;
                $invoice->save();

                return ['status' => 'failed', 'errors' => $errorMessages, 'execution_ms' => $executionMs, 'failure_type' => 'pre_validation'];
            }

            $response = $fbrService->submitInvoice($invoice, 0);
        } catch (\Exception $e) {
            $executionMs = round((microtime(true) - $startTime) * 1000);
            Log::error("FBR Sync Submit: Invoice #{$invoice->id} exception: " . $e->getMessage());

            if (str_contains($e->getMessage(), 'previous success in fbr_logs')) {
                $successLog = FbrLog::where('invoice_id', $invoice->id)
                    ->where('status', 'success')
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($successLog) {
                    $responseData = json_decode($successLog->response_payload, true);
                    $fbrNum = $responseData['invoiceNumber']
                        ?? $responseData['fbr_invoice_number']
                        ?? ($responseData['validationResponse']['invoiceStatuses'][0]['invoiceNo'] ?? null);

                    if (!$fbrNum && $successLog->response_payload) {
                        if (preg_match('/"invoiceNumber"\s*:\s*"([^"]+)"/', $successLog->response_payload, $m)) {
                            $fbrNum = $m[1];
                        }
                    }

                    Log::info("Auto-recovering Invoice #{$invoice->id} from success log #{$successLog->id}, FBR number: {$fbrNum}");

                    $invoice->status = 'locked';
                    $invoice->fbr_status = 'production';
                    $invoice->is_fbr_processing = false;
                    if ($fbrNum) {
                        $invoice->fbr_invoice_number = $fbrNum;
                        $invoice->fbr_invoice_id = $fbrNum;
                        $invoice->fbr_submission_date = $successLog->created_at;
                    }
                    $invoice->save();

                    $this->createLedgerEntry($invoice);

                    InvoiceActivityService::log($invoice->id, $invoice->company_id, 'auto_recovered', [
                        'fbr_invoice_number' => $fbrNum,
                        'success_log_id' => $successLog->id,
                        'mode' => 'sync',
                    ]);

                    HybridComplianceScorer::postFbrValidation($invoice);

                    return ['status' => 'success', 'fbr_invoice_number' => $fbrNum, 'execution_ms' => $executionMs, 'auto_recovered' => true];
                }
            }

            $invoice->status = 'failed';
            $invoice->fbr_status = 'failed';
            $invoice->is_fbr_processing = false;
            $invoice->save();

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'fbr_failed', [
                'error' => $e->getMessage(),
                'execution_ms' => $executionMs,
                'mode' => 'sync',
            ]);

            AuditLogService::log('invoice_fbr_failed', 'Invoice', $invoice->id, null, [
                'error' => $e->getMessage(),
                'mode' => 'sync',
            ]);

            ComplianceScoreService::recalculate($invoice->company_id);

            return ['status' => 'failed', 'errors' => [$e->getMessage()], 'execution_ms' => $executionMs];
        }

        $executionMs = round((microtime(true) - $startTime) * 1000);
        Log::info("FBR Sync Submit: Invoice #{$invoice->id} completed in {$executionMs}ms, result: {$response['status']}");

        try {
            $latestLog = FbrLog::where('invoice_id', $invoice->id)->orderBy('created_at', 'desc')->first();
            if ($latestLog) {
                $failureCategory = null;
                if ($response['status'] !== 'success') {
                    $ft = $response['failure_type'] ?? '';
                    $failureCategory = match(true) {
                        str_contains($ft, 'auth') || str_contains($ft, 'token') => 'authentication',
                        str_contains($ft, 'timeout') || str_contains($ft, 'connection') => 'network',
                        str_contains($ft, 'validation') || str_contains($ft, 'payload') => 'validation',
                        str_contains($ft, 'rate_limit') => 'rate_limit',
                        str_contains($ft, 'server') || str_contains($ft, '500') => 'server_error',
                        str_contains($ft, 'duplicate') => 'duplicate',
                        default => 'unknown',
                    };
                }
                $latestLog->update([
                    'submission_latency_ms' => $executionMs,
                    'environment_used' => $environment,
                    'failure_category' => $failureCategory,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to update FBR log for invoice #{$invoice->id}: " . $e->getMessage());
        }

        if ($response['status'] === 'success') {
            $fbrNum = $response['fbr_invoice_number'] ?? null;
            if ($fbrNum) {
                $invoice->fbr_invoice_number = $fbrNum;
                $invoice->fbr_invoice_id = $fbrNum;
                $invoice->fbr_submission_date = now();
            }
            $invoice->status = 'locked';
            $invoice->fbr_status = 'production';
            $invoice->is_fbr_processing = false;
            $invoice->integrity_hash = IntegrityHashService::generate($invoice);
            $invoice->qr_data = json_encode([
                'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $company->fbr_registration_no ?: ($company->ntn ?? '')),
                'fbr_invoice_number' => $fbrNum ?? $invoice->invoice_number,
                'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                'totalValues' => $invoice->total_amount,
            ]);
            $invoice->save();

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'locked', [
                'fbr_invoice_number' => $fbrNum,
                'execution_ms' => $executionMs,
                'mode' => 'sync',
            ]);

            AuditLogService::log('invoice_fbr_success', 'Invoice', $invoice->id, null, [
                'fbr_invoice_number' => $fbrNum,
                'environment' => $environment,
                'mode' => 'sync',
            ]);

            $this->createLedgerEntry($invoice);

            $company->update(['last_successful_submission' => now()]);
            HsUsagePatternService::recordSuccess($invoice);
            ComplianceScoreService::recalculate($invoice->company_id);
            HybridComplianceScorer::postFbrValidation($invoice);

            return ['status' => 'success', 'fbr_invoice_number' => $fbrNum, 'execution_ms' => $executionMs];
        }

        if ($response['status'] === 'pending_verification') {
            $invoice->status = 'pending_verification';
            $invoice->fbr_status = 'pending_verification';
            $invoice->is_fbr_processing = false;
            $invoice->save();

            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'pending_verification', [
                'reason' => 'FBR response ambiguous',
                'execution_ms' => $executionMs,
                'mode' => 'sync',
            ]);

            return ['status' => 'pending_verification', 'execution_ms' => $executionMs];
        }

        $invoice->status = 'failed';
        $invoice->fbr_status = 'failed';
        $invoice->is_fbr_processing = false;
        $invoice->save();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'fbr_failed', [
            'failure_type' => $response['failure_type'] ?? 'unknown',
            'errors' => $response['errors'] ?? [],
            'execution_ms' => $executionMs,
            'mode' => 'sync',
        ]);

        AuditLogService::log('invoice_fbr_failed', 'Invoice', $invoice->id, null, [
            'failure_type' => $response['failure_type'] ?? 'unknown',
            'mode' => 'sync',
        ]);

        try {
            foreach ($invoice->items as $item) {
                if (!empty($item->hs_code)) {
                    \App\Services\HsIntelligenceService::recordFbrRejection(
                        $item->hs_code, $response['failure_type'] ?? null,
                        is_array($response['errors'] ?? null) ? implode('; ', array_slice($response['errors'], 0, 3)) : ($response['failure_type'] ?? 'FBR submission failed'),
                        $item->schedule_type ?? 'standard', $item->tax_rate ?? 18,
                        $item->sro_schedule_no ?? null, $environment
                    );
                    HsUsagePatternService::recordRejection($item->hs_code, $item->schedule_type ?? 'standard', $item->tax_rate ?? 18);
                }
            }
        } catch (\Exception $e) {
            Log::warning("HS rejection capture failed for invoice #{$invoice->id}: " . $e->getMessage());
        }

        ComplianceScoreService::recalculate($invoice->company_id);

        return [
            'status' => 'failed',
            'errors' => $response['errors'] ?? [],
            'failure_type' => $response['failure_type'] ?? 'unknown',
            'execution_ms' => $executionMs,
        ];
    }

    private function createLedgerEntry(Invoice $invoice): void
    {
        try {
            $ledgerNtn = $invoice->buyer_ntn ?: ('WALK-IN-' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $invoice->buyer_name)));
            $exists = CustomerLedger::where('company_id', $invoice->company_id)
                ->where('invoice_id', $invoice->id)
                ->where('type', 'invoice')
                ->exists();
            if ($exists) return;

            $lastEntry = CustomerLedger::where('company_id', $invoice->company_id)
                ->where('customer_ntn', $ledgerNtn)
                ->orderBy('id', 'desc')->first();
            $lastBalance = $lastEntry ? $lastEntry->balance_after : 0;
            CustomerLedger::create([
                'company_id' => $invoice->company_id,
                'customer_name' => $invoice->buyer_name,
                'customer_ntn' => $ledgerNtn,
                'invoice_id' => $invoice->id,
                'debit' => $invoice->total_amount,
                'credit' => 0,
                'balance_after' => $lastBalance + $invoice->total_amount,
                'type' => 'invoice',
                'notes' => 'Invoice ' . ($invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-'.$invoice->id) . ' locked',
            ]);
        } catch (\Exception $e) {
            \Log::warning("Ledger entry failed for invoice #{$invoice->id}: " . $e->getMessage());
        }
    }

}
