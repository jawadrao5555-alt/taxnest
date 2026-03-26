<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FbrPosController extends Controller
{
    public function dashboard()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        $fbrSubmitted = FbrPosTransaction::where('company_id', $companyId)
            ->whereNotNull('fbr_invoice_number')
            ->count();

        $fbrPending = FbrPosTransaction::where('company_id', $companyId)
            ->where('fbr_status', 'pending')
            ->count();

        $recentTransactions = FbrPosTransaction::where('company_id', $companyId)
            ->with('creator')
            ->latest()
            ->take(10)
            ->get();

        return view('fbr-pos.dashboard', compact(
            'company', 'todayStats', 'monthStats',
            'fbrSubmitted', 'fbrPending', 'recentTransactions'
        ));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        return view('fbr-pos.create', compact('company', 'products'));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'nullable|string|max:20',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.is_tax_exempt' => 'nullable|boolean',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_ntn' => 'nullable|string|max:30',
            'payment_method' => 'required|in:cash,card,bank_transfer,online',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
        ]);

        try {
            $transaction = DB::transaction(function () use ($request, $companyId, $company) {
                $subtotal = 0;
                $totalTax = 0;
                $itemsData = [];

                $defaultTaxRate = 18;

                foreach ($request->items as $item) {
                    $qty = (int) $item['quantity'];
                    $price = (float) $item['unit_price'];
                    $isExempt = !empty($item['is_tax_exempt']);
                    $taxRate = $isExempt ? 0 : (float) ($item['tax_rate'] ?? $defaultTaxRate);
                    $lineSubtotal = round($price * $qty, 2);
                    $lineTax = round($lineSubtotal * $taxRate / 100, 2);
                    $lineTotal = $lineSubtotal + $lineTax;

                    $subtotal += $lineSubtotal;
                    $totalTax += $lineTax;

                    $itemsData[] = [
                        'item_name' => $item['item_name'],
                        'hs_code' => $item['hs_code'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount' => 0,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'subtotal' => $lineSubtotal,
                        'total' => $lineTotal,
                        'is_tax_exempt' => $isExempt,
                    ];
                }

                $discountType = $request->discount_type;
                $discountValue = (float) ($request->discount_value ?? 0);
                $discountAmount = 0;
                if ($discountType === 'percentage' && $discountValue > 0) {
                    $discountAmount = round($subtotal * $discountValue / 100, 2);
                } elseif ($discountType === 'fixed' && $discountValue > 0) {
                    $discountAmount = min($discountValue, $subtotal);
                }

                $totalAmount = round($subtotal - $discountAmount + $totalTax, 2);

                $invoiceNumber = $this->generateInvoiceNumber($companyId);

                $transaction = FbrPosTransaction::create([
                    'company_id' => $companyId,
                    'invoice_number' => $invoiceNumber,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'customer_ntn' => $request->customer_ntn,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $defaultTaxRate,
                    'tax_amount' => $totalTax,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'status' => 'completed',
                    'fbr_status' => 'pending',
                    'created_by' => auth()->id(),
                ]);

                foreach ($itemsData as $itemData) {
                    $transaction->items()->create($itemData);
                }

                return $transaction;
            });

            return redirect()->route('fbrpos.transactions')
                ->with('success', "Sale #{$transaction->invoice_number} created successfully! Total: PKR " . number_format($transaction->total_amount, 2));

        } catch (\Exception $e) {
            Log::error('FBR POS Store Error', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Failed to create sale: ' . $e->getMessage());
        }
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');

        $query = FbrPosTransaction::where('company_id', $companyId)->with('creator');

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%")
                    ->orWhere('fbr_invoice_number', 'ilike', "%{$search}%");
            });
        }

        if ($request->status) {
            $query->where('fbr_status', $request->status);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->latest()->paginate(20)->withQueryString();

        $stats = FbrPosTransaction::where('company_id', $companyId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN fbr_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN fbr_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN fbr_status = 'failed' THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        return view('fbr-pos.transactions', compact('transactions', 'stats'));
    }

    public function show($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator', 'fbrLogs'])
            ->findOrFail($id);

        return view('fbr-pos.show', compact('transaction'));
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "FPOS-{$year}-";

        $lastInvoice = FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('invoice_number');

        if ($lastInvoice) {
            $lastNum = (int) str_replace($prefix, '', $lastInvoice);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        return $prefix . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }
}
