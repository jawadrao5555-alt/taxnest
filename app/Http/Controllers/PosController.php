<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Product;
use App\Models\CustomerProfile;
use App\Models\PosService;
use App\Models\PosTerminal;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\PosPayment;
use App\Models\PosTaxRule;
use App\Models\PraLog;
use App\Services\PraIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function dashboard()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $today = now()->startOfDay();

        $todayStats = PosTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue, COALESCE(AVG(total_amount),0) as avg_ticket')
            ->first();

        $monthStats = PosTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue')
            ->first();

        $recentTransactions = PosTransaction::where('company_id', $companyId)
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $paymentBreakdown = PosTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total")
            ->groupBy('payment_method')
            ->get();

        $praStatus = $company->pra_reporting_enabled;

        return view('pos.dashboard', compact(
            'company', 'todayStats', 'monthStats', 'recentTransactions', 'paymentBreakdown', 'praStatus'
        ));
    }

    public function createInvoice()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $products = Product::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $taxRules = PosTaxRule::where('is_active', true)->get()->keyBy('payment_method');
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        return view('pos.create-invoice', compact('company', 'products', 'services', 'taxRules', 'terminals'));
    }

    public function storeInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,debit_card,credit_card,qr_payment',
            'discount_type' => 'required|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0',
        ]);

        $subtotal = 0;
        foreach ($request->items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            $discountAmount = min($discountValue, $subtotal);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxRate = PosTaxRule::getRateForMethod($request->payment_method);
        $taxAmount = round($afterDiscount * $taxRate / 100, 2);
        $totalAmount = round($afterDiscount + $taxAmount, 2);

        if ($request->terminal_id) {
            $terminal = PosTerminal::where('company_id', $companyId)->where('id', $request->terminal_id)->where('is_active', true)->first();
            if (!$terminal) {
                return back()->withInput()->with('error', 'Invalid or inactive terminal selected.');
            }
        }

        DB::beginTransaction();
        try {
            $invoiceNumber = $this->generateInvoiceNumber($companyId);
            $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

            $transaction = PosTransaction::create([
                'company_id' => $companyId,
                'terminal_id' => $request->terminal_id,
                'invoice_number' => $invoiceNumber,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
                'pra_status' => 'pending',
                'submission_hash' => $submissionHash,
                'created_by' => auth()->id(),
            ]);

            foreach ($request->items as $item) {
                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $item['type'] ?? 'product',
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => round($item['quantity'] * $item['unit_price'], 2),
                ]);
            }

            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to create invoice: ' . $e->getMessage());
        }

        $praMessage = '';
        if ($company->pra_reporting_enabled) {
            try {
                $praService = new PraIntegrationService($company);
                $praResult = $praService->sendInvoice($transaction);
                $transaction->refresh();

                if ($praResult['success']) {
                    $praMessage = ' | PRA Fiscal Invoice Number: ' . ($transaction->pra_invoice_number ?? 'N/A');
                } else {
                    $praMessage = ' | PRA submission failed — saved locally for retry.';
                }
            } catch (\Exception $e) {
                $transaction->update(['pra_status' => 'failed']);
                $praMessage = ' | PRA submission failed — saved locally for retry.';
            }
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', 'Invoice Created Successfully! POS Invoice Number: ' . $invoiceNumber . $praMessage);
    }

    public function retryPra($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $transaction = PosTransaction::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return back()->with('error', 'This invoice has already been submitted to PRA. PRA Fiscal Invoice #: ' . $transaction->pra_invoice_number);
        }

        if ($transaction->pra_status === 'submitted') {
            return back()->with('error', 'This invoice has already been successfully submitted to PRA.');
        }

        if (!in_array($transaction->pra_status, ['pending', 'failed'])) {
            return back()->with('error', 'This invoice cannot be retried. Current status: ' . $transaction->pra_status);
        }

        if (!$company->pra_reporting_enabled) {
            return back()->with('error', 'PRA reporting is currently disabled. Enable it from PRA Settings first.');
        }

        try {
            $praService = new PraIntegrationService($company);
            $praResult = $praService->sendInvoice($transaction);
            $transaction->refresh();

            if ($praResult['success']) {
                return back()->with('success', 'PRA submission successful! PRA Fiscal Invoice Number: ' . ($transaction->pra_invoice_number ?? 'N/A'));
            } else {
                return back()->with('error', 'PRA submission failed: ' . ($praResult['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            return back()->with('error', 'PRA connection failed: ' . $e->getMessage());
        }
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');
        $query = PosTransaction::where('company_id', $companyId)->with('creator');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('pos.transactions', compact('transactions'));
    }

    public function transactionShow($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'payments', 'praLogs', 'creator', 'terminal'])
            ->findOrFail($id);

        return view('pos.transaction-show', compact('transaction'));
    }

    public function receipt($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'payments', 'creator', 'terminal'])
            ->findOrFail($id);

        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';

        return view($receiptView, compact('transaction', 'company'));
    }

    public function reports()
    {
        $companyId = app('currentCompanyId');

        $dailySales = PosTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date', 'desc')
            ->get();

        $paymentSummary = PosTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(tax_amount),0) as tax")
            ->groupBy('payment_method')
            ->get();

        $topItems = PosTransactionItem::whereHas('transaction', function ($q) use ($companyId) {
            $q->where('company_id', $companyId)->where('created_at', '>=', now()->startOfMonth());
        })
            ->selectRaw("item_name, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue")
            ->groupBy('item_name')
            ->orderByDesc('total_revenue')
            ->take(10)
            ->get();

        $monthlyTrend = PosTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw("TO_CHAR(created_at, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        return view('pos.reports', compact('dailySales', 'paymentSummary', 'topItems', 'monthlyTrend'));
    }

    public function services()
    {
        $companyId = app('currentCompanyId');
        $services = PosService::where('company_id', $companyId)->orderBy('name')->get();
        return view('pos.services', compact('services'));
    }

    public function storeService(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        PosService::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? 0,
            'is_active' => true,
        ]);

        return back()->with('success', 'Service added successfully.');
    }

    public function updateService(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $service = PosService::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        $service->update([
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? $service->tax_rate,
            'is_active' => $request->has('is_active'),
        ]);

        return back()->with('success', 'Service updated successfully.');
    }

    public function deleteService($id)
    {
        $companyId = app('currentCompanyId');
        PosService::where('company_id', $companyId)->findOrFail($id)->delete();
        return back()->with('success', 'Service deleted.');
    }

    public function getTaxRate(Request $request)
    {
        $method = $request->payment_method ?? 'cash';
        $rate = PosTaxRule::getRateForMethod($method);
        return response()->json(['tax_rate' => $rate]);
    }

    public function togglePra(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->pra_reporting_enabled = !$company->pra_reporting_enabled;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => $company->pra_reporting_enabled,
            'message' => $company->pra_reporting_enabled ? 'PRA Reporting enabled' : 'PRA Reporting disabled',
        ]);
    }

    public function terminals()
    {
        $companyId = app('currentCompanyId');
        $terminals = PosTerminal::where('company_id', $companyId)->orderBy('terminal_name')->get();
        return view('pos.terminals', compact('terminals'));
    }

    public function storeTerminal(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'terminal_name' => 'required|string|max:255',
            'terminal_code' => 'required|string|max:100|unique:pos_terminals,terminal_code',
            'location' => 'nullable|string|max:255',
        ]);

        PosTerminal::create([
            'company_id' => $companyId,
            'terminal_name' => $request->terminal_name,
            'terminal_code' => $request->terminal_code,
            'location' => $request->location,
            'is_active' => true,
        ]);

        return back()->with('success', 'Terminal added successfully.');
    }

    public function updateTerminal(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $terminal = PosTerminal::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'terminal_name' => 'required|string|max:255',
            'terminal_code' => 'required|string|max:100|unique:pos_terminals,terminal_code,' . $id,
            'location' => 'nullable|string|max:255',
        ]);

        $terminal->update([
            'terminal_name' => $request->terminal_name,
            'terminal_code' => $request->terminal_code,
            'location' => $request->location,
            'is_active' => $request->has('is_active'),
        ]);

        return back()->with('success', 'Terminal updated successfully.');
    }

    public function deleteTerminal($id)
    {
        $companyId = app('currentCompanyId');
        $terminal = PosTerminal::where('company_id', $companyId)->findOrFail($id);

        if ($terminal->transactions()->exists()) {
            return back()->with('error', 'Cannot delete terminal with existing transactions. Deactivate it instead.');
        }

        $terminal->delete();
        return back()->with('success', 'Terminal deleted.');
    }

    public function praSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $request->validate([
                'pra_environment' => 'required|in:sandbox,production',
                'pra_pos_id' => 'nullable|string',
                'pra_production_token' => 'nullable|string',
                'receipt_printer_size' => 'nullable|in:80mm,58mm',
            ]);

            $company->update([
                'pra_environment' => $request->pra_environment,
                'pra_pos_id' => $request->pra_pos_id,
                'pra_production_token' => $request->pra_production_token,
                'receipt_printer_size' => $request->receipt_printer_size ?? '80mm',
            ]);

            return back()->with('success', 'PRA settings updated successfully.');
        }

        $praLogs = PraLog::where('company_id', $companyId)->orderBy('created_at', 'desc')->take(20)->get();
        return view('pos.pra-settings', compact('company', 'praLogs'));
    }

    public function products()
    {
        $companyId = app('currentCompanyId');
        $products = Product::where('company_id', $companyId)->orderBy('name')->get();
        return view('pos.products', compact('products'));
    }

    public function customers()
    {
        $companyId = app('currentCompanyId');
        $customers = CustomerProfile::where('company_id', $companyId)->orderBy('name')->get();
        return view('pos.customers', compact('customers'));
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');

        $lastTransaction = PosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "POS-{$year}-%")
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction && preg_match('/POS-\d{4}-(\d+)/', $lastTransaction->invoice_number, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'POS-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
