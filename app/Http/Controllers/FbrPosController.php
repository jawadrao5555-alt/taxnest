<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosLog;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\Product;
use App\Services\FbrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
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
            ->where('invoice_mode', 'fbr')
            ->whereNotNull('fbr_invoice_number')
            ->count();

        $fbrPending = FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'fbr')
            ->where('fbr_status', 'pending')
            ->count();

        $recentTransactions = FbrPosTransaction::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->latest()
            ->take(10)
            ->get();

        $fbrReportingStatus = (bool) $company->fbr_reporting_enabled;

        return view('fbr-pos.dashboard', compact(
            'company', 'todayStats', 'monthStats',
            'fbrSubmitted', 'fbrPending', 'recentTransactions', 'fbrReportingStatus'
        ));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $fbrReportingEnabled = (bool) $company->fbr_reporting_enabled;

        return view('fbr-pos.create', compact('company', 'products', 'fbrReportingEnabled'));
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

        $fbrEnabled = (bool) $company->fbr_reporting_enabled;
        $invoiceMode = $fbrEnabled ? 'fbr' : 'local';

        try {
            $transaction = DB::transaction(function () use ($request, $companyId, $company, $invoiceMode) {
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

                $invoiceNumber = $invoiceMode === 'local'
                    ? $this->generateLocalInvoiceNumber($companyId)
                    : $this->generateInvoiceNumber($companyId);

                $transaction = FbrPosTransaction::create([
                    'company_id' => $companyId,
                    'invoice_number' => $invoiceNumber,
                    'invoice_mode' => $invoiceMode,
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
                    'fbr_status' => $invoiceMode === 'local' ? 'local' : 'pending',
                    'created_by' => Auth::guard('fbrpos')->id(),
                ]);

                foreach ($itemsData as $itemData) {
                    $transaction->items()->create($itemData);
                }

                return $transaction;
            });

            if ($invoiceMode === 'local') {
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', "Local sale #{$transaction->invoice_number} created (PKR " . number_format($transaction->total_amount, 2) . "). FBR Reporting is OFF — invoice saved locally.");
            }

            $transaction->load(['items', 'company']);
            $fbrService = new FbrService();
            $fbrResult = $fbrService->submitFbrPosTransaction($transaction);

            if ($fbrResult['status'] === 'success') {
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', "Sale #{$transaction->invoice_number} created and submitted to FBR successfully! FBR Invoice: {$fbrResult['fbr_invoice_number']}");
            }

            $fbrErrors = implode(', ', $fbrResult['errors'] ?? ['Unknown error']);
            return redirect()->route('fbrpos.show', $transaction->id)
                ->with('success', "Sale #{$transaction->invoice_number} created (PKR " . number_format($transaction->total_amount, 2) . ").")
                ->with('error', "FBR submission failed: {$fbrErrors}. You can retry from the transaction detail page.");

        } catch (\Exception $e) {
            Log::error('FBR POS Store Error', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Failed to create sale: ' . $e->getMessage());
        }
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'fbr');

        $query = FbrPosTransaction::where('company_id', $companyId)->with('creator');

        if ($tab === 'local') {
            if (!empty($company->confidential_pin) && !$this->isPinSessionValid()) {
                return redirect()->route('fbrpos.transactions', ['tab' => 'fbr'])
                    ->with('error', 'PIN verification required to access local invoices.');
            }
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            });
        }

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
            ->where(function ($q) use ($tab) {
                if ($tab === 'local') {
                    $q->where('invoice_mode', 'local');
                } else {
                    $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
                }
            })
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN fbr_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN fbr_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN fbr_status = 'failed' THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        $localCount = FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->count();

        $localRevenue = 0;
        if ($tab === 'local') {
            $localRevenue = FbrPosTransaction::where('company_id', $companyId)
                ->where('invoice_mode', 'local')
                ->sum('total_amount');
        }

        $hasPinSet = !empty($company->confidential_pin);

        return view('fbr-pos.transactions', compact('transactions', 'stats', 'tab', 'localCount', 'localRevenue', 'hasPinSet', 'company'));
    }

    public function show($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator', 'fbrLogs'])
            ->findOrFail($id);

        if ($transaction->invoice_mode === 'local') {
            $company = Company::find($companyId);
            if (!empty($company->confidential_pin) && !$this->isPinSessionValid()) {
                return redirect()->route('fbrpos.transactions')
                    ->with('error', 'PIN verification required to view local invoices.');
            }
        }

        return view('fbr-pos.show', compact('transaction'));
    }

    public function retryFbr($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->fbr_status === 'submitted') {
            return redirect()->route('fbrpos.show', $id)->with('error', 'This transaction is already submitted to FBR.');
        }

        $transaction->fbr_submission_hash = null;
        $transaction->save();

        $transaction->load(['items', 'company']);
        $fbrService = new FbrService();
        $fbrResult = $fbrService->submitFbrPosTransaction($transaction);

        if ($fbrResult['status'] === 'success') {
            return redirect()->route('fbrpos.show', $id)
                ->with('success', "FBR submission successful! FBR Invoice: {$fbrResult['fbr_invoice_number']}");
        }

        $fbrErrors = implode(', ', $fbrResult['errors'] ?? ['Unknown error']);
        return redirect()->route('fbrpos.show', $id)
            ->with('error', "FBR retry failed: {$fbrErrors}");
    }

    public function fbrSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('fbrpos')->user();

        if ($user->role !== 'company_admin') {
            return back()->with('error', 'Only company admin can access FBR settings.');
        }

        if ($request->isMethod('post')) {
            $request->validate([
                'fbr_pos_environment' => 'required|in:sandbox,production',
                'fbr_pos_id' => 'nullable|string|max:100',
                'fbr_pos_token' => 'nullable|string|max:255',
            ]);

            $updateData = [
                'fbr_pos_environment' => $request->fbr_pos_environment,
            ];

            if ($request->filled('fbr_pos_id')) {
                $updateData['fbr_pos_id'] = $request->fbr_pos_id;
            }

            if ($request->filled('fbr_pos_token')) {
                $updateData['fbr_pos_token'] = Crypt::encryptString($request->fbr_pos_token);
            }

            $company->update($updateData);

            return back()->with('success', 'FBR POS settings updated successfully.');
        }

        $fbrLogs = FbrPosLog::where('company_id', $companyId)->orderBy('created_at', 'desc')->take(20)->get();

        $posToken = '';
        if ($company->fbr_pos_token) {
            try { $posToken = Crypt::decryptString($company->fbr_pos_token); } catch (\Exception $e) { $posToken = $company->fbr_pos_token; }
        }
        $maskedPosToken = $posToken ? substr($posToken, 0, 8) . '****' . substr($posToken, -4) : '';

        $hasSandboxFallback = !empty($company->fbr_sandbox_token);
        $hasProductionFallback = !empty($company->fbr_production_token);

        return view('fbr-pos.settings', compact('company', 'fbrLogs', 'maskedPosToken', 'hasSandboxFallback', 'hasProductionFallback'));
    }

    public function testConnection()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => 'Only company admin can test connection.']);
        }

        $env = $company->fbr_pos_environment ?? 'sandbox';
        $fbrService = new FbrService();

        $ref = new \ReflectionMethod($fbrService, 'getFbrPosToken');
        $ref->setAccessible(true);
        $token = $ref->invoke($fbrService, $company);

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => "No {$env} token configured. Please set your FBR token first.",
            ]);
        }

        $urlRef = new \ReflectionMethod($fbrService, 'getFbrPosUrl');
        $urlRef->setAccessible(true);
        $url = $urlRef->invoke($fbrService, $company);

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return response()->json([
                    'success' => false,
                    'message' => "Connection failed: {$curlError}",
                ]);
            }

            if ($httpCode === 401) {
                return response()->json([
                    'success' => false,
                    'message' => "Authentication failed (401). Token may be invalid or expired for {$env} environment.",
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Connected to FBR {$env} server successfully (HTTP {$httpCode}). Token is valid.",
                'environment' => $env,
                'http_code' => $httpCode,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ]);
        }
    }

    public function toggleFbrReporting()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => 'Only company admin can toggle FBR reporting.'], 403);
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->fbr_reporting_enabled = !$company->fbr_reporting_enabled;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => $company->fbr_reporting_enabled,
            'message' => $company->fbr_reporting_enabled ? 'FBR Reporting enabled' : 'FBR Reporting disabled',
        ]);
    }

    public function verifyPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (empty($company->confidential_pin)) {
            session(['fbr_pos_pin_verified' => true, 'fbr_pos_pin_verified_at' => now()->timestamp]);
            return response()->json(['success' => true, 'message' => 'No PIN set — access granted.']);
        }

        $cacheKey = "fbrpos_pin_lockout_{$companyId}";
        $attemptsKey = "fbrpos_pin_attempts_{$companyId}";

        if (cache()->get($cacheKey)) {
            $remaining = (int) ceil((cache()->get($cacheKey) - now()->timestamp) / 60);
            return response()->json([
                'success' => false,
                'message' => "Account locked. Try again in {$remaining} minute(s).",
            ], 429);
        }

        $pin = $request->input('pin', '');

        if (!\Hash::check($pin, $company->confidential_pin)) {
            $attempts = (int) cache()->get($attemptsKey, 0) + 1;
            cache()->put($attemptsKey, $attempts, 900);

            if ($attempts >= 5) {
                cache()->put($cacheKey, now()->addMinutes(15)->timestamp, 900);
                cache()->forget($attemptsKey);
                return response()->json([
                    'success' => false,
                    'message' => 'Too many failed attempts. Locked for 15 minutes.',
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => 'Incorrect PIN. ' . (5 - $attempts) . ' attempt(s) remaining.',
            ]);
        }

        cache()->forget($attemptsKey);
        session(['fbr_pos_pin_verified' => true, 'fbr_pos_pin_verified_at' => now()->timestamp]);

        return response()->json(['success' => true, 'message' => 'PIN verified.']);
    }

    public function checkPinSession()
    {
        return response()->json(['verified' => $this->isPinSessionValid()]);
    }

    private function isPinSessionValid(): bool
    {
        $verified = session('fbr_pos_pin_verified', false);
        $verifiedAt = session('fbr_pos_pin_verified_at', 0);
        return $verified && (now()->timestamp - $verifiedAt) < 1800;
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

    private function generateLocalInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "FLOCAL-{$year}-";

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

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'fbrpos')->orderBy('price')->get();
        $currentSubscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        return view('fbr-pos.billing', compact('company', 'plans', 'currentSubscription'));
    }
}
