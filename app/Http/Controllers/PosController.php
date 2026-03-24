<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Models\PosProduct;
use App\Models\PosCustomer;
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
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue, COALESCE(AVG(total_amount),0) as avg_ticket')
            ->first();

        $monthStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue')
            ->first();

        $recentTransactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $paymentBreakdown = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total")
            ->groupBy('payment_method')
            ->get();

        $praStatus = $company->pra_reporting_enabled;

        $drafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get();

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        return view('pos.dashboard', compact(
            'company', 'todayStats', 'monthStats', 'recentTransactions', 'paymentBreakdown', 'praStatus', 'drafts', 'isCashier'
        ));
    }

    public function createInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::where('is_active', true)->get()->keyBy('payment_method');
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        $draftInvoice = null;
        if ($request->has('draft_id')) {
            $draftInvoice = PosTransaction::where('company_id', $companyId)
                ->where('id', $request->draft_id)
                ->where('status', 'draft')
                ->with('items')
                ->first();

            if ($draftInvoice) {
                $currentTerminalId = $request->input('terminal_id');
                if ($draftInvoice->isLocked() && $currentTerminalId && $draftInvoice->locked_by_terminal_id != $currentTerminalId) {
                    $lockedTerminal = PosTerminal::find($draftInvoice->locked_by_terminal_id);
                    $terminalName = $lockedTerminal ? $lockedTerminal->terminal_name : 'Unknown';
                    return redirect()->route('pos.invoice.create')
                        ->with('error', "This invoice is currently being edited on another terminal ({$terminalName}).");
                }

                if ($currentTerminalId) {
                    $draftInvoice->acquireLock((int) $currentTerminalId);
                }
            }
        }

        $pendingDrafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->where('created_by', auth('pos')->id())
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        return view('pos.create-invoice', compact('company', 'products', 'services', 'taxRules', 'terminals', 'draftInvoice', 'pendingDrafts', 'posCustomers'));
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

        $companyItems = $this->resolveItemExemptions($request->items, $companyId);
        $subtotal = array_sum(array_column($companyItems, 'lineTotal'));
        $taxableSubtotal = array_sum(array_map(fn($i) => $i['isExempt'] ? 0 : $i['lineTotal'], $companyItems));
        $exemptSubtotal = $subtotal - $taxableSubtotal;

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            $discountAmount = min($discountValue, $subtotal);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
        $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method);
        $taxAmount = round($taxableAfterDiscount * $taxRate / 100, 2);
        $totalAmount = round($afterDiscount + $taxAmount, 2);

        if ($request->terminal_id) {
            $terminal = PosTerminal::where('company_id', $companyId)->where('id', $request->terminal_id)->where('is_active', true)->first();
            if (!$terminal) {
                return back()->withInput()->with('error', 'Invalid or inactive terminal selected.');
            }
        }

        $praEnabled = (bool) $company->pra_reporting_enabled;
        $invoiceMode = $praEnabled ? 'pra' : 'local';
        $initialPraStatus = $praEnabled ? 'pending' : 'local';

        DB::beginTransaction();
        try {
            $draftId = $request->input('draft_id');
            $transaction = null;

            if ($draftId) {
                $transaction = PosTransaction::where('company_id', $companyId)
                    ->where('id', $draftId)
                    ->where('status', 'draft')
                    ->lockForUpdate()
                    ->first();
            }

            if ($transaction) {
                $invoiceNumber = $transaction->invoice_number;
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction->update([
                    'terminal_id' => $request->terminal_id,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'exempt_amount' => $exemptAfterDiscount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'status' => 'completed',
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    'locked_by_terminal_id' => null,
                    'lock_time' => null,
                ]);

                $transaction->items()->delete();
            } else {
                $invoiceNumber = $invoiceMode === 'local'
                    ? $this->generateLocalInvoiceNumber($companyId)
                    : $this->generateInvoiceNumber($companyId);
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction = PosTransaction::create([
                    'company_id' => $companyId,
                    'terminal_id' => $request->terminal_id,
                    'invoice_number' => $invoiceNumber,
                    'invoice_mode' => $invoiceMode,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'exempt_amount' => $exemptAfterDiscount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'status' => 'completed',
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    'created_by' => auth('pos')->id(),
                ]);
            }

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                $itemTaxAmount = round($itemTaxableAmount * $itemTaxRate / 100, 2);

                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'item_name' => $ri['name'],
                    'quantity' => $ri['quantity'],
                    'unit_price' => $ri['price'],
                    'subtotal' => $ri['lineTotal'],
                    'is_tax_exempt' => $ri['isExempt'],
                    'tax_rate' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount,
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

        $inventoryResult = PosInventoryController::deductStockForInvoice(
            $companyId,
            $request->items,
            $transaction->id,
            $invoiceNumber,
            auth('pos')->id()
        );

        $praMessage = '';
        if ($praEnabled) {
            try {
                $praService = new PraIntegrationService($company);
                $praResult = $praService->sendInvoice($transaction);
                $transaction->refresh();

                if ($praResult['success']) {
                    $praMessage = ' | PRA Fiscal Invoice Number: ' . ($transaction->pra_invoice_number ?? 'N/A');
                } else {
                    $transaction->update(['pra_status' => 'offline']);
                    $praMessage = ' | Offline Mode: Invoice saved locally and will sync automatically.';
                }
            } catch (\Exception $e) {
                $transaction->update(['pra_status' => 'offline']);
                $praMessage = ' | Offline Mode: Invoice saved locally and will sync automatically.';
            }
        } else {
            $praMessage = ' | Local invoice (PRA reporting is off).';
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', 'Invoice Created Successfully! POS Invoice Number: ' . $invoiceNumber . $praMessage);
    }

    public function editTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot edit — this invoice has been submitted to PRA. PRA Fiscal #: ' . $transaction->pra_invoice_number);
        }

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::where('is_active', true)->get()->keyBy('payment_method');
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        $transactionItems = $transaction->items->map(fn($item) => [
            'type' => $item->item_type ?? 'product',
            'item_id' => $item->item_id ?? '',
            'name' => $item->item_name ?? '',
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->values();

        return view('pos.edit-transaction', compact('company', 'transaction', 'transactionItems', 'products', 'services', 'taxRules', 'terminals', 'posCustomers'));
    }

    public function updateTransaction(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot edit — this invoice has been submitted to PRA.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,debit_card,credit_card,qr_payment',
            'discount_type' => 'required|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0',
        ]);

        $companyItems = $this->resolveItemExemptions($request->items, $companyId);
        $subtotal = array_sum(array_column($companyItems, 'lineTotal'));
        $taxableSubtotal = array_sum(array_map(fn($i) => $i['isExempt'] ? 0 : $i['lineTotal'], $companyItems));

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            $discountAmount = min($discountValue, $subtotal);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
        $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method);
        $taxAmount = round($taxableAfterDiscount * $taxRate / 100, 2);
        $totalAmount = round($afterDiscount + $taxAmount, 2);

        DB::beginTransaction();
        try {
            $transaction->update([
                'terminal_id' => $request->terminal_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'exempt_amount' => $exemptAfterDiscount,
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
                'pra_status' => $company->pra_reporting_enabled ? 'pending' : ($transaction->pra_status ?? 'local'),
            ]);

            $transaction->items()->delete();

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                $itemTaxAmount = round($itemTaxableAmount * $itemTaxRate / 100, 2);

                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'item_name' => $ri['name'],
                    'quantity' => $ri['quantity'],
                    'unit_price' => $ri['price'],
                    'subtotal' => $ri['lineTotal'],
                    'is_tax_exempt' => $ri['isExempt'],
                    'tax_rate' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount,
                ]);
            }

            $transaction->payments()->delete();
            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to update invoice: ' . $e->getMessage());
        }

        $praMessage = '';
        if ($company->pra_reporting_enabled) {
            try {
                $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
                $praService = new PraIntegrationService($company);
                $praResult = $praService->sendInvoice($transaction);
                $transaction->refresh();

                if ($praResult['success']) {
                    $praMessage = ' | PRA Fiscal #: ' . ($transaction->pra_invoice_number ?? 'N/A');
                } else {
                    $transaction->update(['pra_status' => 'offline']);
                    $praMessage = ' | Offline: Will sync automatically.';
                }
            } catch (\Exception $e) {
                $transaction->update(['pra_status' => 'offline']);
                $praMessage = ' | Offline: Will sync automatically.';
            }
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', 'Invoice updated successfully!' . $praMessage);
    }

    public function deleteTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot delete — this invoice has been submitted to PRA. PRA Fiscal #: ' . $transaction->pra_invoice_number);
        }

        DB::beginTransaction();
        try {
            $transaction->items()->delete();
            $transaction->payments()->delete();
            $transaction->praLogs()->delete();
            $transaction->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to delete invoice: ' . $e->getMessage());
        }

        return redirect()->route('pos.transactions')
            ->with('success', 'Invoice ' . $transaction->invoice_number . ' deleted successfully.');
    }

    public function retryPra($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return back()->with('error', 'This invoice has already been submitted to PRA. PRA Fiscal Invoice #: ' . $transaction->pra_invoice_number);
        }

        if ($transaction->pra_status === 'submitted') {
            return back()->with('error', 'This invoice has already been successfully submitted to PRA.');
        }

        if ($transaction->pra_status === 'local') {
            return back()->with('error', 'This is a local invoice created while PRA reporting was off. It cannot be synced to PRA.');
        }

        if (!in_array($transaction->pra_status, ['pending', 'failed', 'offline'])) {
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
            $transaction->update(['pra_status' => 'offline']);
            return back()->with('error', 'PRA connection failed — invoice will sync automatically when connection is restored.');
        }
    }

    public function bulkRetryPra()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company->pra_reporting_enabled) {
            return back()->with('error', 'PRA reporting is currently disabled. Enable it from PRA Settings first.');
        }

        $pendingInvoices = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'asc')
            ->get();

        if ($pendingInvoices->isEmpty()) {
            return back()->with('info', 'No failed or offline invoices to retry.');
        }

        $praService = new PraIntegrationService($company);
        $successCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($pendingInvoices as $transaction) {
            try {
                $result = $praService->sendInvoice($transaction);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                    $errors[] = $transaction->invoice_number . ': ' . ($result['message'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                $failCount++;
                $transaction->update(['pra_status' => 'offline']);
                $errors[] = $transaction->invoice_number . ': Connection failed';
            }
        }

        $message = '';
        if ($successCount > 0) {
            $message = $successCount . ' invoice(s) successfully submitted to PRA.';
        }
        if ($failCount > 0) {
            $errorDetail = $failCount . ' invoice(s) failed.';
            if ($successCount > 0) {
                return back()->with('warning', $message . ' ' . $errorDetail);
            }
            return back()->with('error', $errorDetail . ' ' . implode(' | ', array_slice($errors, 0, 3)));
        }

        return back()->with('success', $message);
    }

    private function verifyPinSession(): bool
    {
        return session('confidential_pin_verified', false) === true;
    }

    private function clearPinSession(): void
    {
        session()->forget(['confidential_pin_verified', 'confidential_pin_verified_at']);
    }

    private function requirePinForLocalTab(string $tab, Company $company)
    {
        if ($tab !== 'local') {
            $this->clearPinSession();
            return null;
        }

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        if ($isCashier) {
            if (empty($company->confidential_pin)) {
                return redirect()->back()->with('error', 'Local data access is restricted. Admin must set a PIN first.');
            }
            if (!$this->verifyPinSession()) {
                return redirect()->back()->with('error', 'PIN verification required to access local data.');
            }
        } elseif (!empty($company->confidential_pin) && !$this->verifyPinSession()) {
            return redirect()->back()->with('error', 'PIN verification required to access local data.');
        }

        $this->clearPinSession();

        return null;
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $query = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->with('creator');

        if ($tab === 'local') {
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            });
        }

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

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->where('invoice_mode', 'local')->count();
        $user = auth('pos')->user();

        return view('pos.transactions', compact('transactions', 'tab', 'hasPinSet', 'localCount', 'user'));
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

    public function downloadInvoicePdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'terminal'])
            ->findOrFail($id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("Invoice-{$transaction->invoice_number}.pdf");
    }

    public function generateShareLink($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if (!$transaction->share_token) {
            $transaction->update([
                'share_token' => bin2hex(random_bytes(32)),
                'share_token_created_at' => now(),
            ]);
        }

        $shareUrl = url("/pos/invoice/share/{$transaction->share_token}");

        return response()->json(['url' => $shareUrl, 'token' => $transaction->share_token]);
    }

    public function publicInvoicePdf($token)
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            abort(404);
        }

        $transaction = PosTransaction::where('share_token', $token)
            ->with(['items', 'terminal'])
            ->firstOrFail();

        if ($transaction->share_token_created_at && $transaction->share_token_created_at < now()->subDays(30)) {
            abort(410, 'This share link has expired.');
        }

        $company = Company::find($transaction->company_id);
        if (!$company) {
            abort(404);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("Invoice-{$transaction->invoice_number}.pdf");
    }

    private function applyReportFilters($query, $tab, $cashierFilter = null)
    {
        if ($tab === 'local') {
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($sub) {
                $sub->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            });
        }

        if ($cashierFilter && $cashierFilter !== 'all') {
            $query->where('created_by', $cashierFilter);
        }

        return $query;
    }

    public function reports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        $teamMembers = User::where('company_id', $companyId)
            ->whereNotNull('pos_role')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role']);

        $modeFilter = function ($q) use ($tab, $cashierFilter) {
            $this->applyReportFilters($q, $tab, $cashierFilter);
        };

        $dailySales = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->tap($modeFilter)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date', 'desc')
            ->get();

        $paymentSummary = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->tap($modeFilter)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(tax_amount),0) as tax")
            ->groupBy('payment_method')
            ->get();

        $topItems = PosTransactionItem::whereHas('transaction', function ($q) use ($companyId, $tab, $cashierFilter) {
            $q->where('company_id', $companyId)->where('status', 'completed')->where('created_at', '>=', now()->startOfMonth());
            $this->applyReportFilters($q, $tab, $cashierFilter);
        })
            ->selectRaw("item_name, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue")
            ->groupBy('item_name')
            ->orderByDesc('total_revenue')
            ->take(10)
            ->get();

        $monthlyTrend = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->tap($modeFilter)
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw("TO_CHAR(created_at, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->where('invoice_mode', 'local')->count();
        $selectedCashier = $cashierFilter;

        return view('pos.reports', compact('dailySales', 'paymentSummary', 'topItems', 'monthlyTrend', 'tab', 'hasPinSet', 'localCount', 'user', 'teamMembers', 'isCashier', 'selectedCashier'));
    }

    public function exportReportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->when($tab === 'local', fn($q) => $q->where('invoice_mode', 'local'))
            ->when($tab !== 'local', fn($q) => $q->where(function ($s) { $s->where('invoice_mode', 'pra')->orWhereNull('invoice_mode'); }))
            ->when($cashierFilter && $cashierFilter !== 'all', fn($q) => $q->where('created_by', $cashierFilter))
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->get();

        $filterLabel = $cashierFilter === 'all' ? 'All Staff' : ($transactions->first()?->creator?->name ?? 'Staff #' . $cashierFilter);
        $filename = 'POS_Report_' . ($cashierFilter === 'all' ? 'AllStaff' : 'Staff') . '_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $filterLabel, $tab, $company) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['POS Sales Report — ' . $company->name]);
            fputcsv($file, ['Mode: ' . strtoupper($tab), 'Filter: ' . $filterLabel, 'Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Invoice #', 'Customer', 'Payment Method', 'Subtotal', 'Tax', 'Total', 'Staff']);

            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->created_at->format('d M Y H:i'),
                    $t->invoice_number,
                    $t->customer_name ?: 'Walk-in',
                    ucwords(str_replace('_', ' ', $t->payment_method)),
                    number_format($t->subtotal, 2),
                    number_format($t->tax_amount, 2),
                    number_format($t->total_amount, 2),
                    $t->creator?->name ?? '-',
                ]);
            }

            $totalRevenue = $transactions->sum('total_amount');
            $totalTax = $transactions->sum('tax_amount');
            fputcsv($file, []);
            fputcsv($file, ['', '', '', 'TOTALS', '', number_format($totalTax, 2), number_format($totalRevenue, 2), '']);
            fputcsv($file, ['Total Transactions: ' . $transactions->count()]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function buildTaxReportQuery(Request $request, $tab = 'pra')
    {
        $companyId = app('currentCompanyId');
        $query = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->with('terminal');

        if ($tab === 'local') {
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($q) { $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode'); });
        }

        if ($request->filled('tax_rate')) {
            if ($request->tax_rate === 'exempt') {
                $query->where('exempt_amount', '>', 0);
            } else {
                $rate = (float) $request->tax_rate;
                $query->where('tax_rate', $rate);
            }
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('customer')) {
            $query->where('customer_name', 'ilike', '%' . $request->customer . '%');
        }

        if ($request->filled('period')) {
            switch ($request->period) {
                case 'today':
                    $query->where('created_at', '>=', now()->startOfDay());
                    break;
                case 'weekly':
                    $query->where('created_at', '>=', now()->startOfWeek());
                    break;
                case 'monthly':
                    $query->where('created_at', '>=', now()->startOfMonth());
                    break;
            }
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $query->orderBy('created_at', 'desc');
        return $query;
    }

    private function getReportDateLabel(Request $request): string
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return $request->date_from . ' to ' . $request->date_to;
        }
        if ($request->filled('date_from')) {
            return $request->date_from . ' to Present';
        }
        if ($request->filled('date_to')) {
            return 'Up to ' . $request->date_to;
        }
        if ($request->filled('period')) {
            return match ($request->period) {
                'today' => 'Today (' . now()->format('d M Y') . ')',
                'weekly' => 'This Week (' . now()->startOfWeek()->format('d M') . ' - ' . now()->endOfWeek()->format('d M Y') . ')',
                'monthly' => 'This Month (' . now()->format('M Y') . ')',
                default => 'All Time',
            };
        }
        return 'All Time';
    }

    public function taxReports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $query = $this->buildTaxReportQuery($request, $tab);
        $transactions = $query->paginate(50)->appends($request->all());

        $summaryQuery = $this->buildTaxReportQuery($request, $tab);
        $summary = $summaryQuery->reorder()->selectRaw('
            COUNT(*) as total_invoices,
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(SUM(discount_amount), 0) as total_discount,
            COALESCE(SUM(subtotal - discount_amount - COALESCE(exempt_amount, 0)), 0) as total_taxable,
            COALESCE(SUM(tax_amount), 0) as total_tax,
            COALESCE(SUM(exempt_amount), 0) as total_exempt
        ')->first();

        $dateLabel = $this->getReportDateLabel($request);

        $taxRateLabel = 'All Taxes';
        if ($request->filled('tax_rate')) {
            $taxRateLabel = $request->tax_rate === 'exempt' ? 'Exempt Items Only' : $request->tax_rate . '% Tax Only';
        }

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->where('invoice_mode', 'local')->count();
        $user = auth('pos')->user();

        return view('pos.tax-reports', compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'tab', 'hasPinSet', 'localCount', 'user'));
    }

    public function exportTaxReportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $query = $this->buildTaxReportQuery($request, $tab);
        $transactions = $query->get();

        $dateLabel = $this->getReportDateLabel($request);
        $taxRateLabel = 'All Taxes';
        if ($request->filled('tax_rate')) {
            $taxRateLabel = $request->tax_rate === 'exempt' ? 'Exempt Items' : $request->tax_rate . '% Tax';
        }

        $filename = 'NestPOS_Tax_Report_' . str_replace([' ', '/', '(', ')'], '_', $taxRateLabel) . '_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, [
                'POS Invoice Number',
                'PRA Fiscal Invoice Number',
                'Invoice Date',
                'Customer Name',
                'Payment Method',
                'Subtotal (PKR)',
                'Discount Amount (PKR)',
                'Taxable Amount (PKR)',
                'Tax Exempt Amount (PKR)',
                'Tax Rate (%)',
                'Tax Amount (PKR)',
                'Total Amount (PKR)',
                'Terminal Name',
                'PRA Status',
            ]);

            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->invoice_number,
                    $t->pra_invoice_number ?? 'N/A',
                    $t->created_at->format('d/m/Y H:i'),
                    $t->customer_name ?? 'Walk-in',
                    ucwords(str_replace('_', ' ', $t->payment_method)),
                    number_format($t->subtotal, 2, '.', ''),
                    number_format($t->discount_amount, 2, '.', ''),
                    number_format($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0), 2, '.', ''),
                    number_format($t->exempt_amount ?? 0, 2, '.', ''),
                    number_format($t->tax_rate, 2, '.', ''),
                    number_format($t->tax_amount, 2, '.', ''),
                    number_format($t->total_amount, 2, '.', ''),
                    $t->terminal?->terminal_name ?? 'N/A',
                    strtoupper($t->pra_status ?? 'N/A'),
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, ['SUMMARY']);
            fputcsv($file, ['Total Invoices', $transactions->count()]);
            fputcsv($file, ['Total Sales Amount (PKR)', number_format($transactions->sum('total_amount'), 2, '.', '')]);
            fputcsv($file, ['Total Discount Amount (PKR)', number_format($transactions->sum('discount_amount'), 2, '.', '')]);
            fputcsv($file, ['Total Taxable Amount (PKR)', number_format($transactions->sum(fn($t) => $t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0)), 2, '.', '')]);
            fputcsv($file, ['Total Tax Exempt Amount (PKR)', number_format($transactions->sum('exempt_amount'), 2, '.', '')]);
            fputcsv($file, ['Total Tax Amount (PKR)', number_format($transactions->sum('tax_amount'), 2, '.', '')]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportTaxReportPdf(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $query = $this->buildTaxReportQuery($request, $tab);
        $transactions = $query->get();

        $summaryQuery = $this->buildTaxReportQuery($request, $tab);
        $summary = $summaryQuery->reorder()->selectRaw('
            COUNT(*) as total_invoices,
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(SUM(discount_amount), 0) as total_discount,
            COALESCE(SUM(subtotal - discount_amount - COALESCE(exempt_amount, 0)), 0) as total_taxable,
            COALESCE(SUM(tax_amount), 0) as total_tax,
            COALESCE(SUM(exempt_amount), 0) as total_exempt
        ')->first();

        $dateLabel = $this->getReportDateLabel($request);
        $taxRateLabel = 'All Taxes';
        if ($request->filled('tax_rate')) {
            $taxRateLabel = $request->tax_rate === 'exempt' ? 'Exempt Items Only' : $request->tax_rate . '% Tax Only';
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.tax-report-pdf', compact(
            'company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel'
        ));

        $pdf->setPaper('a4', 'landscape');

        $filename = 'NestPOS_Tax_Report_' . str_replace([' ', '/', '(', ')'], '_', $taxRateLabel) . '_' . now()->format('Ymd_His') . '.pdf';

        return $pdf->download($filename);
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
            'is_tax_exempt' => $request->has('is_tax_exempt'),
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
            'is_tax_exempt' => $request->has('is_tax_exempt'),
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
        $user = auth('pos')->user();

        if ($request->isMethod('post')) {
            if ($user->isPosCashier()) {
                return back()->with('error', 'Only company admin can change settings.');
            }

            $request->validate([
                'pra_environment' => 'required|in:sandbox,production',
                'pra_pos_id' => 'nullable|string',
                'pra_production_token' => 'nullable|string',
                'receipt_printer_size' => 'nullable|in:80mm,58mm',
            ]);

            $updateData = [
                'pra_environment' => $request->pra_environment,
                'receipt_printer_size' => $request->receipt_printer_size ?? '80mm',
            ];

            if ($request->filled('pra_pos_id')) {
                $updateData['pra_pos_id'] = $request->pra_pos_id;
            }

            if ($request->filled('pra_production_token')) {
                $updateData['pra_production_token'] = $request->pra_production_token;
            }

            $company->update($updateData);

            if ($request->filled('confidential_pin')) {
                $request->validate([
                    'confidential_pin' => 'string|min:4|max:6|regex:/^\d+$/',
                ]);
                $company->update([
                    'confidential_pin' => bcrypt($request->confidential_pin),
                ]);
                return back()->with('success', 'Settings & Confidential PIN updated.');
            }

            if ($request->has('remove_pin') && $request->remove_pin) {
                $company->update(['confidential_pin' => null]);
                return back()->with('success', 'Settings updated. Confidential PIN removed.');
            }

            return back()->with('success', 'PRA settings updated successfully.');
        }

        $praLogs = PraLog::where('company_id', $companyId)->orderBy('created_at', 'desc')->take(20)->get();
        $proxyEnabled = !empty(env('PRA_PROXY_URL'));
        $hasPinSet = !empty($company->confidential_pin);
        return view('pos.pra-settings', compact('company', 'praLogs', 'proxyEnabled', 'hasPinSet'));
    }

    public function verifyPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $lockKey = 'pin_lockout_' . $companyId;
        $attemptsKey = 'pin_attempts_' . $companyId;

        if (cache()->get($lockKey)) {
            $remaining = cache()->get($lockKey) - now()->timestamp;
            return response()->json([
                'success' => false,
                'message' => 'Too many wrong attempts. Try again in ' . ceil($remaining / 60) . ' minutes.',
                'locked' => true,
            ], 429);
        }

        if (empty($company->confidential_pin)) {
            return response()->json(['success' => false, 'message' => 'Confidential PIN not set. Admin must set it from Settings.'], 400);
        }

        $pin = $request->input('pin', '');
        if (\Hash::check($pin, $company->confidential_pin)) {
            cache()->forget($attemptsKey);
            session(['confidential_pin_verified' => true, 'confidential_pin_verified_at' => now()->timestamp]);
            return response()->json(['success' => true, 'message' => 'PIN verified.']);
        }

        $attempts = (int) cache()->get($attemptsKey, 0) + 1;
        cache()->put($attemptsKey, $attempts, 900);

        if ($attempts >= 5) {
            cache()->put($lockKey, now()->addMinutes(15)->timestamp, 900);
            cache()->forget($attemptsKey);
            return response()->json([
                'success' => false,
                'message' => 'Too many wrong attempts. Locked for 15 minutes.',
                'locked' => true,
            ], 429);
        }

        return response()->json([
            'success' => false,
            'message' => 'Wrong PIN. ' . (5 - $attempts) . ' attempts remaining.',
            'remaining' => 5 - $attempts,
        ], 401);
    }

    public function checkPinSession()
    {
        $verified = session('confidential_pin_verified', false);
        $verifiedAt = session('confidential_pin_verified_at', 0);
        $isValid = $verified && (now()->timestamp - $verifiedAt) < 1800;

        return response()->json(['verified' => $isValid]);
    }

    public function posTeam(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return redirect()->route('pos.dashboard')->with('error', 'Access denied.');
        }

        $team = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_cashier'])
            ->orderByRaw("CASE WHEN pos_role = 'pos_admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return view('pos.team', compact('team'));
    }

    public function storeCashier(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return back()->with('error', 'Access denied.');
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'company_id' => $companyId,
            'role' => 'employee',
            'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);

        return back()->with('success', 'Cashier account created successfully.');
    }

    public function updateCashier(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return back()->with('error', 'Access denied.');
        }

        $cashier = User::where('company_id', $companyId)->where('pos_role', 'pos_cashier')->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $cashier->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $cashier->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);

        if ($request->filled('password')) {
            $cashier->update(['password' => bcrypt($request->password)]);
        }

        return back()->with('success', 'Cashier updated.');
    }

    public function toggleCashier($id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return back()->with('error', 'Access denied.');
        }

        $cashier = User::where('company_id', $companyId)->where('pos_role', 'pos_cashier')->findOrFail($id);
        $cashier->update(['is_active' => !$cashier->is_active]);

        return back()->with('success', $cashier->is_active ? 'Cashier activated.' : 'Cashier deactivated.');
    }

    public function products()
    {
        $companyId = app('currentCompanyId');
        $products = PosProduct::where('company_id', $companyId)->orderBy('name')->get();
        return view('pos.products', compact('products'));
    }

    public function storeProduct(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'uom' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
        ]);

        PosProduct::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? 0,
            'category' => $request->category,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'uom' => $request->uom ?? 'NOS',
            'is_tax_exempt' => $request->has('is_tax_exempt'),
        ]);

        return back()->with('success', 'Product added successfully.');
    }

    public function downloadProductTemplate()
    {
        $companyId = app('currentCompanyId');
        $existingProducts = PosProduct::where('company_id', $companyId)->orderBy('name')->get();

        $headers = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)'];

        $callback = function() use ($headers, $existingProducts) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $headers);

            if ($existingProducts->isEmpty()) {
                fputcsv($file, ['Chicken Biryani', '450', 'Full plate biryani with raita', 'Food', 'CB-001', '8901234567890', '16', 'NOS']);
                fputcsv($file, ['Pepsi 500ml', '120', 'Cold drink bottle', 'Beverages', 'PEP-500', '8901234567891', '5', 'NOS']);
                fputcsv($file, ['Naan', '30', 'Tandoori naan', 'Food', 'NAN-001', '', '0', 'NOS']);
            } else {
                foreach ($existingProducts as $p) {
                    fputcsv($file, [
                        $p->name,
                        $p->price,
                        $p->description ?? '',
                        $p->category ?? '',
                        $p->sku ?? '',
                        $p->barcode ?? '',
                        $p->tax_rate ?? 0,
                        $p->uom ?? 'NOS',
                    ]);
                }
            }

            fclose($file);
        };

        $filename = $existingProducts->isEmpty() ? 'pos_products_template.csv' : 'pos_products_export.csv';

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function importProducts(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return back()->with('error', 'Could not read file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->with('error', 'Empty file or invalid format.');
        }

        $header = array_map(function($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', $h)));
        }, $header);

        $nameIdx = array_search('name', $header);
        $priceIdx = array_search('price', $header);

        if ($nameIdx === false || $priceIdx === false) {
            fclose($handle);
            return back()->with('error', 'CSV must have "Name" and "Price" columns. Download the template for the correct format.');
        }

        $descIdx = $this->findColumn($header, ['description']);
        $catIdx = $this->findColumn($header, ['category']);
        $skuIdx = $this->findColumn($header, ['sku']);
        $barcodeIdx = $this->findColumn($header, ['barcode']);
        $taxIdx = $this->findColumn($header, ['tax rate %', 'tax rate', 'tax_rate', 'tax']);
        $uomIdx = $this->findColumn($header, ['unit (uom)', 'unit', 'uom']);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            $name = trim($data[$nameIdx] ?? '');
            $price = trim($data[$priceIdx] ?? '');

            if ($name === '' || $price === '') {
                $skipped++;
                continue;
            }

            if (!is_numeric($price) || $price < 0) {
                $errors[] = "Row {$row}: Invalid price for '{$name}'";
                $skipped++;
                continue;
            }

            $existing = PosProduct::where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->first();

            if ($existing) {
                $existing->update([
                    'price' => (float) $price,
                    'description' => $descIdx !== false ? trim($data[$descIdx] ?? '') ?: $existing->description : $existing->description,
                    'category' => $catIdx !== false ? trim($data[$catIdx] ?? '') ?: $existing->category : $existing->category,
                    'sku' => $skuIdx !== false ? trim($data[$skuIdx] ?? '') ?: $existing->sku : $existing->sku,
                    'barcode' => $barcodeIdx !== false ? trim($data[$barcodeIdx] ?? '') ?: $existing->barcode : $existing->barcode,
                    'tax_rate' => $taxIdx !== false && is_numeric(trim($data[$taxIdx] ?? '')) ? (float) trim($data[$taxIdx]) : $existing->tax_rate,
                    'uom' => $uomIdx !== false && trim($data[$uomIdx] ?? '') !== '' ? strtoupper(trim($data[$uomIdx])) : $existing->uom,
                ]);
                $imported++;
            } else {
                PosProduct::create([
                    'company_id' => $companyId,
                    'name' => $name,
                    'price' => (float) $price,
                    'description' => $descIdx !== false ? trim($data[$descIdx] ?? '') : null,
                    'category' => $catIdx !== false ? trim($data[$catIdx] ?? '') : null,
                    'sku' => $skuIdx !== false ? trim($data[$skuIdx] ?? '') : null,
                    'barcode' => $barcodeIdx !== false ? trim($data[$barcodeIdx] ?? '') : null,
                    'tax_rate' => $taxIdx !== false && is_numeric(trim($data[$taxIdx] ?? '')) ? (float) trim($data[$taxIdx]) : 0,
                    'uom' => $uomIdx !== false && trim($data[$uomIdx] ?? '') !== '' ? strtoupper(trim($data[$uomIdx])) : 'NOS',
                    'is_active' => true,
                ]);
                $imported++;
            }
        }

        fclose($handle);

        $msg = "{$imported} products imported successfully.";
        if ($skipped > 0) $msg .= " {$skipped} rows skipped.";
        if (!empty($errors)) $msg .= " Issues: " . implode('; ', array_slice($errors, 0, 3));

        return back()->with('success', $msg);
    }

    private function findColumn(array $header, array $names): int|false
    {
        foreach ($names as $name) {
            $idx = array_search($name, $header);
            if ($idx !== false) return $idx;
        }
        return false;
    }

    public function updateProduct(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'uom' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
        ]);

        $product->update(array_merge(
            $request->only(['name', 'description', 'price', 'tax_rate', 'category', 'sku', 'barcode', 'uom']),
            ['is_tax_exempt' => $request->has('is_tax_exempt')]
        ));
        return back()->with('success', 'Product updated successfully.');
    }

    public function deleteProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->delete();
        return back()->with('success', 'Product deleted successfully.');
    }

    public function toggleProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        return back()->with('success', $product->is_active ? 'Product activated.' : 'Product deactivated.');
    }

    public function customers()
    {
        $companyId = app('currentCompanyId');
        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();
        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        return view('pos.customers', compact('customers', 'isCashier'));
    }

    public function storeCustomer(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        PosCustomer::create(array_merge($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']), [
            'company_id' => $companyId,
        ]));

        return back()->with('success', 'Customer added successfully.');
    }

    public function updateCustomer(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer->update($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']));
        return back()->with('success', 'Customer updated successfully.');
    }

    public function deleteCustomer($id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->delete();
        return back()->with('success', 'Customer deleted successfully.');
    }

    public function toggleCustomer($id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->update(['is_active' => !$customer->is_active]);
        return back()->with('success', $customer->is_active ? 'Customer activated.' : 'Customer deactivated.');
    }

    public function saveDraft(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'draft_data' => 'required|array',
        ]);

        $draftId = $request->input('draft_id');
        $draftData = $request->input('draft_data');

        if ($draftId) {
            $draft = PosTransaction::where('company_id', $companyId)
                ->where('id', $draftId)
                ->where('status', 'draft')
                ->first();

            if ($draft) {
                if ($draft->isLocked() && $draft->locked_by_terminal_id != $request->input('terminal_id')) {
                    return response()->json(['success' => false, 'message' => 'This invoice is currently being edited on another terminal.'], 423);
                }

                $draft->update([
                    'customer_name' => $draftData['customer_name'] ?? null,
                    'customer_phone' => $draftData['customer_phone'] ?? null,
                    'terminal_id' => $draftData['terminal_id'] ?? null,
                    'subtotal' => $draftData['subtotal'] ?? 0,
                    'discount_type' => $draftData['discount_type'] ?? 'percentage',
                    'discount_value' => $draftData['discount_value'] ?? 0,
                    'discount_amount' => $draftData['discount_amount'] ?? 0,
                    'tax_rate' => $draftData['tax_rate'] ?? 0,
                    'tax_amount' => $draftData['tax_amount'] ?? 0,
                    'total_amount' => $draftData['total_amount'] ?? 0,
                    'payment_method' => $draftData['payment_method'] ?? 'cash',
                ]);

                $draft->items()->delete();
                foreach (($draftData['items'] ?? []) as $item) {
                    PosTransactionItem::create([
                        'transaction_id' => $draft->id,
                        'item_type' => $item['type'] ?? 'product',
                        'item_id' => $item['item_id'] ?? null,
                        'item_name' => $item['name'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'subtotal' => round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2),
                    ]);
                }

                return response()->json(['success' => true, 'draft_id' => $draft->id]);
            }
        }

        DB::beginTransaction();
        try {
            $company = Company::find($companyId);
            $praEnabled = (bool) $company->pra_reporting_enabled;
            $invoiceMode = $praEnabled ? 'pra' : 'local';
            $invoiceNumber = $invoiceMode === 'local'
                ? $this->generateLocalInvoiceNumber($companyId)
                : $this->generateInvoiceNumber($companyId);

            $draft = PosTransaction::create([
                'company_id' => $companyId,
                'terminal_id' => $draftData['terminal_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_name' => $draftData['customer_name'] ?? null,
                'customer_phone' => $draftData['customer_phone'] ?? null,
                'subtotal' => $draftData['subtotal'] ?? 0,
                'discount_type' => $draftData['discount_type'] ?? 'percentage',
                'discount_value' => $draftData['discount_value'] ?? 0,
                'discount_amount' => $draftData['discount_amount'] ?? 0,
                'tax_rate' => $draftData['tax_rate'] ?? 0,
                'tax_amount' => $draftData['tax_amount'] ?? 0,
                'total_amount' => $draftData['total_amount'] ?? 0,
                'payment_method' => $draftData['payment_method'] ?? 'cash',
                'status' => 'draft',
                'pra_status' => $praEnabled ? 'pending' : 'local',
                'created_by' => auth('pos')->id(),
                'locked_by_terminal_id' => $draftData['terminal_id'] ?? null,
                'lock_time' => now(),
            ]);

            foreach (($draftData['items'] ?? []) as $item) {
                PosTransactionItem::create([
                    'transaction_id' => $draft->id,
                    'item_type' => $item['type'] ?? 'product',
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['name'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'subtotal' => round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2),
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'draft_id' => $draft->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDrafts()
    {
        $companyId = app('currentCompanyId');

        $drafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(10)
            ->get();

        return response()->json(['drafts' => $drafts]);
    }

    public function deleteDraft($id)
    {
        $companyId = app('currentCompanyId');
        $draft = PosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found.'], 404);
        }

        $draft->items()->delete();
        $draft->payments()->delete();
        $draft->delete();

        return response()->json(['success' => true]);
    }

    public function lockInvoice(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $terminalId = $request->input('terminal_id');

        if (!$terminalId) {
            return response()->json(['success' => false, 'message' => 'Terminal ID required.'], 400);
        }

        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->isLocked() && $transaction->locked_by_terminal_id != $terminalId) {
            $lockedTerminal = PosTerminal::find($transaction->locked_by_terminal_id);
            $terminalName = $lockedTerminal ? $lockedTerminal->terminal_name : 'Unknown';
            return response()->json([
                'success' => false,
                'message' => "This invoice is currently being edited on another terminal ({$terminalName}).",
                'locked_by' => $terminalName,
                'lock_time' => $transaction->lock_time?->toISOString(),
            ], 423);
        }

        $transaction->acquireLock((int) $terminalId);
        return response()->json(['success' => true]);
    }

    public function unlockInvoice($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);
        $transaction->releaseLock();
        return response()->json(['success' => true]);
    }

    private function resolveItemExemptions(array $requestItems, int $companyId): array
    {
        $resolved = [];
        foreach ($requestItems as $item) {
            $itemType = $item['type'] ?? 'product';
            $itemId = $item['item_id'] ?? null;
            $itemName = trim($item['name'] ?? '');
            $itemPrice = (float) ($item['unit_price'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $isExempt = !empty($item['is_tax_exempt']);

            if ($itemId) {
                if ($itemType === 'product') {
                    $obj = PosProduct::where('company_id', $companyId)->where('id', $itemId)->first();
                    if ($obj) {
                        $isExempt = (bool) $obj->is_tax_exempt;
                    } else {
                        $itemId = null;
                    }
                } else {
                    $obj = PosService::where('company_id', $companyId)->where('id', $itemId)->first();
                    if ($obj) {
                        $isExempt = (bool) $obj->is_tax_exempt;
                    } else {
                        $itemId = null;
                    }
                }
            }

            if (!$itemId && $itemType === 'product' && $itemName !== '') {
                $existing = PosProduct::where('company_id', $companyId)
                    ->whereRaw('LOWER(name) = ?', [strtolower($itemName)])
                    ->first();
                if ($existing) {
                    $itemId = $existing->id;
                    $isExempt = (bool) $existing->is_tax_exempt;
                } else {
                    $userExempt = !empty($item['is_tax_exempt']);
                    $newProduct = PosProduct::create([
                        'company_id' => $companyId,
                        'name' => $itemName,
                        'price' => $itemPrice,
                        'tax_rate' => 0,
                        'uom' => 'NOS',
                        'is_active' => true,
                        'is_tax_exempt' => $userExempt,
                    ]);
                    $itemId = $newProduct->id;
                    $isExempt = $userExempt;
                }
            }

            $resolved[] = [
                'type' => $itemType,
                'item_id' => $itemId,
                'name' => $itemName,
                'price' => $itemPrice,
                'quantity' => $qty,
                'lineTotal' => round($qty * $itemPrice, 2),
                'isExempt' => $isExempt,
            ];
        }
        return $resolved;
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

    private function generateLocalInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');

        $lastTransaction = PosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "LOCAL-{$year}-%")
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction && preg_match('/LOCAL-\d{4}-(\d+)/', $lastTransaction->invoice_number, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'LOCAL-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $plans = \App\Models\PricingPlan::where('is_trial', false)->orderBy('price')->get();
        $currentSubscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        return view('pos.billing', compact('company', 'plans', 'currentSubscription'));
    }

    public function businessProfile(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $rules = [
                'name' => 'required|string|max:255',
                'owner_name' => 'nullable|string|max:255',
                'ntn' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:30',
                'mobile' => 'nullable|string|max:30',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'business_activity' => 'nullable|string|max:255',
                'website' => 'nullable|url|max:255',
                'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            ];

            $request->validate($rules);

            $data = $request->only(['name', 'owner_name', 'ntn', 'email', 'phone', 'mobile', 'address', 'city', 'business_activity', 'website']);
            $data['inventory_enabled'] = $request->has('inventory_enabled');

            if ($request->hasFile('logo')) {
                if ($company->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
                }
                $path = $request->file('logo')->store('company-logos', 'public');
                $data['logo_path'] = $path;
            }

            if ($request->has('remove_logo') && $request->remove_logo === '1') {
                if ($company->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
                }
                $data['logo_path'] = null;
            }

            $company->update($data);
            return back()->with('success', 'Business profile updated successfully.');
        }

        return view('pos.business-profile', compact('company'));
    }

    public function userProfile(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();

        if ($request->isMethod('post')) {
            $action = $request->input('action');

            if ($action === 'update_profile') {
                $request->validate([
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|max:255|unique:users,email,' . $user->id,
                    'phone' => 'nullable|string|max:30',
                ]);

                $user->update($request->only(['name', 'email', 'phone']));
                return back()->with('success', 'Profile updated successfully.');
            }

            if ($action === 'change_password') {
                $request->validate([
                    'current_password' => 'required',
                    'new_password' => 'required|string|min:8|confirmed',
                ]);

                if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
                    return back()->withErrors(['current_password' => 'Current password is incorrect.']);
                }

                $user->update([
                    'password' => \Illuminate\Support\Facades\Hash::make($request->new_password),
                ]);
                return back()->with('success', 'Password changed successfully.');
            }
        }

        return view('pos.user-profile', compact('user'));
    }
}
