<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrDayCloseReport;
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
    public function updateDashboardStyle(Request $request)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => 'Only company admin can change dashboard style.'], 403);
        }
        $style = $request->json('style') ?? $request->input('style', 'default');
        $allowed = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify'];
        if (!in_array($style, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Invalid style'], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_dashboard_style' => $style]);
        return response()->json(['success' => true, 'style' => $style]);
    }

    public function dashboard()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $branchSvc = app(\App\Services\BranchContextService::class);
        $branchScope = fn ($q) => $branchSvc->applyToQuery($q);

        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        $fbrSubmitted = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where('invoice_mode', 'fbr')
            ->whereNotNull('fbr_invoice_number')
            ->count();

        $fbrPending = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where('invoice_mode', 'fbr')
            ->where('fbr_status', 'pending')
            ->count();

        $recentTransactions = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->latest()
            ->take(10)
            ->get();

        $fbrReportingStatus = (bool) $company->fbr_reporting_enabled;

        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify'];
        $dashboardStyle = in_array($company->pos_dashboard_style, $allowedStyles) ? $company->pos_dashboard_style : 'default';

        return view('fbr-pos.dashboard', compact(
            'company', 'todayStats', 'monthStats',
            'fbrSubmitted', 'fbrPending', 'recentTransactions', 'fbrReportingStatus',
            'dashboardStyle'
        ));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $fbrReportingEnabled = (bool) $company->fbr_reporting_enabled;

        // Phase 2: terminals, shift, loyalty, promotions
        $terminals = \App\Models\FbrPosTerminal::where('company_id', $companyId)->where('is_active', true)->orderBy('terminal_name')->get();
        $currentShift = \App\Models\FbrPosShift::where('company_id', $companyId)
            ->where('user_id', Auth::guard('fbrpos')->id())
            ->where('status', 'open')->latest('id')->first();
        $loyaltySettings = \App\Models\FbrPosLoyaltySetting::forCompany($companyId);
        $heldCount = \App\Models\FbrPosHeldSale::where('company_id', $companyId)->count();
        $activePromos = \App\Models\FbrPosPromotion::where('company_id', $companyId)
            ->where('is_active', true)->orderByDesc('id')->limit(20)->get();

        return view('fbr-pos.create', compact(
            'company', 'products', 'fbrReportingEnabled',
            'terminals', 'currentShift', 'loyaltySettings', 'heldCount', 'activePromos'
        ));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'nullable|string|max:20',
            'items.*.uom' => 'nullable|string|in:U,KG,GM,LTR,ML,MTR,SQM,PCS,PKT,DOZ,BOX,SET,BAG,BTL,CTN,ROL,FT,IN,YDS,TIN,CAN,BUN',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.is_tax_exempt' => 'nullable|boolean',
            'items.*.item_discount' => 'nullable|numeric|min:0',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_ntn' => 'nullable|string|max:30',
            'payment_method' => 'required|in:cash,card,bank_transfer,online',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'terminal_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'promotion_id' => 'nullable|integer',
            'promotion_code' => 'nullable|string|max:50',
            'loyalty_points_redeemed' => 'nullable|integer|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            'payment_breakdown' => 'nullable|array',
            'payment_breakdown.*.method' => 'required_with:payment_breakdown|string',
            'payment_breakdown.*.amount' => 'required_with:payment_breakdown|numeric|min:0',
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
                    $qty = round((float) $item['quantity'], 3);
                    if ($qty <= 0) { $qty = 1; }
                    $price = (float) $item['unit_price'];
                    $isExempt = !empty($item['is_tax_exempt']);
                    $taxRate = $isExempt ? 0 : (float) ($item['tax_rate'] ?? $defaultTaxRate);
                    $itemDiscount = round((float) ($item['item_discount'] ?? 0), 2);
                    $grossLine = round($price * $qty, 2);
                    if ($itemDiscount > $grossLine) { $itemDiscount = $grossLine; }
                    $lineSubtotal = round($grossLine - $itemDiscount, 2);
                    $lineTax = round($lineSubtotal * $taxRate / 100, 2);
                    $lineTotal = round($lineSubtotal + $lineTax, 2);

                    $subtotal += $lineSubtotal;
                    $totalTax += $lineTax;

                    $itemsData[] = [
                        'item_name' => $item['item_name'],
                        'hs_code' => $item['hs_code'] ?? null,
                        'uom' => $item['uom'] ?? 'U',
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount' => 0,
                        'item_discount' => $itemDiscount,
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

                $fbrServiceCharge = $invoiceMode === 'fbr' ? 1.00 : 0.00;

                // Phase 2: Promotion discount (cart-level, separate from manual discount)
                $promotionDiscount = 0;
                $promo = null;
                if ($request->promotion_id) {
                    $promo = \App\Models\FbrPosPromotion::where('company_id', $companyId)
                        ->where('id', $request->promotion_id)->where('is_active', true)->first();
                    if ($promo) {
                        $check = $promo->isUsable($subtotal);
                        if ($check['ok']) {
                            $promotionDiscount = $promo->calcDiscount($subtotal);
                            $discountAmount += $promotionDiscount;
                        }
                    }
                }

                // Phase 2: Loyalty redemption
                $loyaltyRedemptionAmount = 0;
                $loyaltyPointsRedeemed = (int) ($request->loyalty_points_redeemed ?? 0);
                $loyaltySettings = \App\Models\FbrPosLoyaltySetting::forCompany($companyId);
                if ($loyaltyPointsRedeemed > 0 && $loyaltySettings->is_enabled && $request->customer_id) {
                    $customer = \App\Models\PosCustomer::where('company_id', $companyId)
                        ->where('id', $request->customer_id)->first();
                    if ($customer && $customer->loyalty_points >= $loyaltyPointsRedeemed
                        && $loyaltyPointsRedeemed >= $loyaltySettings->min_redeem_points) {
                        $loyaltyRedemptionAmount = round($loyaltyPointsRedeemed * $loyaltySettings->point_value, 2);
                        // cap to remaining total
                        $maxRedeem = max(0, $subtotal - $discountAmount + $totalTax);
                        $loyaltyRedemptionAmount = min($loyaltyRedemptionAmount, $maxRedeem);
                    } else {
                        $loyaltyPointsRedeemed = 0;
                    }
                }

                $totalAmount = round($subtotal - $discountAmount + $totalTax + $fbrServiceCharge - $loyaltyRedemptionAmount, 2);
                if ($totalAmount < 0) $totalAmount = 0;

                // Loyalty earn (1 point per rs_per_point on net total)
                $loyaltyPointsEarned = 0;
                if ($loyaltySettings->is_enabled && $request->customer_id && $loyaltySettings->rs_per_point > 0) {
                    $loyaltyPointsEarned = (int) floor($totalAmount / (float) $loyaltySettings->rs_per_point);
                }

                // Cash received & change
                $cashReceived = (float) ($request->cash_received ?? 0);
                $changeDue = max(0, $cashReceived - $totalAmount);

                // Payment breakdown
                $paymentBreakdown = $request->payment_breakdown;
                if (!$paymentBreakdown) {
                    $paymentBreakdown = [['method' => $request->payment_method, 'amount' => $totalAmount]];
                }

                // Active shift
                $shift = \App\Models\FbrPosShift::where('company_id', $companyId)
                    ->where('user_id', Auth::guard('fbrpos')->id())
                    ->where('status', 'open')->latest('id')->first();

                $invoiceNumber = $invoiceMode === 'local'
                    ? $this->generateLocalInvoiceNumber($companyId)
                    : $this->generateInvoiceNumber($companyId);

                $transaction = FbrPosTransaction::create([
                    'company_id' => $companyId,
                    'branch_id' => app()->bound('currentBranchId') ? app('currentBranchId') : null,
                    'terminal_id' => $request->terminal_id,
                    'shift_id' => $shift?->id,
                    'invoice_number' => $invoiceNumber,
                    'invoice_mode' => $invoiceMode,
                    'transaction_type' => 'sale',
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'customer_ntn' => $request->customer_ntn,
                    'customer_id' => $request->customer_id,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'promotion_id' => $promo?->id,
                    'promotion_code' => $promo?->code,
                    'tax_rate' => $defaultTaxRate,
                    'tax_amount' => $totalTax,
                    'fbr_service_charge' => $fbrServiceCharge,
                    'loyalty_points_earned' => $loyaltyPointsEarned,
                    'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                    'loyalty_redemption_amount' => $loyaltyRedemptionAmount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'payment_breakdown' => $paymentBreakdown,
                    'cash_received' => $cashReceived,
                    'change_due' => $changeDue,
                    'status' => 'completed',
                    'fbr_status' => $invoiceMode === 'local' ? 'local' : 'pending',
                    'created_by' => Auth::guard('fbrpos')->id(),
                ]);

                // Update promotion usage
                if ($promo && $promotionDiscount > 0) {
                    $promo->increment('usage_count');
                }

                // Update shift totals
                if ($shift) {
                    $cashTotal = 0; $cardTotal = 0; $otherTotal = 0;
                    foreach ($paymentBreakdown as $pb) {
                        $m = strtolower($pb['method'] ?? '');
                        $a = (float) ($pb['amount'] ?? 0);
                        if ($m === 'cash') $cashTotal += $a;
                        elseif (in_array($m, ['card','credit_card','debit_card'])) $cardTotal += $a;
                        else $otherTotal += $a;
                    }
                    $shift->sales_count = (int) $shift->sales_count + 1;
                    $shift->total_sales = (float) $shift->total_sales + $totalAmount;
                    $shift->total_cash = (float) $shift->total_cash + $cashTotal;
                    $shift->total_card = (float) $shift->total_card + $cardTotal;
                    $shift->total_other = (float) $shift->total_other + $otherTotal;
                    $shift->save();
                }

                // Update customer loyalty + stats
                if ($request->customer_id) {
                    $customer = \App\Models\PosCustomer::find($request->customer_id);
                    if ($customer) {
                        $netPoints = $loyaltyPointsEarned - $loyaltyPointsRedeemed;
                        $customer->loyalty_points = max(0, (int) $customer->loyalty_points + $netPoints);
                        $customer->total_spent = (float) $customer->total_spent + $totalAmount;
                        $customer->total_orders = (int) $customer->total_orders + 1;
                        $customer->save();

                        if ($loyaltyPointsRedeemed > 0) {
                            \App\Models\FbrPosLoyaltyLedger::create([
                                'company_id' => $companyId, 'customer_id' => $customer->id,
                                'transaction_id' => $transaction->id, 'type' => 'redeem',
                                'points' => -$loyaltyPointsRedeemed,
                                'balance_after' => $customer->loyalty_points,
                                'note' => "Redeemed on invoice {$invoiceNumber}",
                            ]);
                        }
                        if ($loyaltyPointsEarned > 0) {
                            \App\Models\FbrPosLoyaltyLedger::create([
                                'company_id' => $companyId, 'customer_id' => $customer->id,
                                'transaction_id' => $transaction->id, 'type' => 'earn',
                                'points' => $loyaltyPointsEarned,
                                'balance_after' => $customer->loyalty_points,
                                'note' => "Earned on invoice {$invoiceNumber}",
                            ]);
                        }
                    }
                }

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

            // ============ AUTO-RETRY ENGINE ============
            // For transient failures (curl/network/empty 200/timeout), schedule auto-retry job (10s, 20s, 30s, max 3 tries)
            // For hard failures (token missing, validation), no retry — manual fix needed
            $errorString = strtolower($fbrErrors);
            $isTransient = $fbrResult['status'] === 'retry'
                || str_contains($errorString, 'connection failed')
                || str_contains($errorString, 'timeout')
                || str_contains($errorString, 'empty response')
                || str_contains($errorString, 'unexpected response')
                || (isset($fbrResult['http_status']) && $fbrResult['http_status'] >= 500);

            if ($isTransient) {
                \App\Jobs\RetryFbrPosSubmissionJob::dispatch($transaction->id)->delay(now()->addSeconds(10));
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', "Sale #{$transaction->invoice_number} saved (PKR " . number_format($transaction->total_amount, 2) . ").")
                    ->with('warning', "FBR submission temporarily failed: {$fbrErrors}. Auto-retry scheduled (3 attempts, 10s apart).");
            }

            // ✅ Sale was saved locally. FBR is a separate retry-able step — don't scare the cashier with red error.
            $isTokenIssue = str_contains(strtolower($fbrErrors), 'token');
            $warningMsg = $isTokenIssue
                ? "✓ Bill saved successfully. FBR submission pending — your FBR token is not configured yet. Go to Settings → FBR Settings to set it up, then retry from Fail Queue."
                : "✓ Bill saved successfully. FBR submission pending: {$fbrErrors}. Retry from this page or the Fail Queue.";

            return redirect()->route('fbrpos.show', $transaction->id)
                ->with('success', "✓ Bill #{$transaction->invoice_number} created — PKR " . number_format($transaction->total_amount, 2))
                ->with('warning', $warningMsg);

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

        $branchSvc = app(\App\Services\BranchContextService::class);
        $query = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn($q) => $branchSvc->applyToQuery($q))
            ->with('creator');

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
                $q->whereRaw('LOWER(invoice_number) LIKE ?', ['%' . strtolower($search) . '%'])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', ['%' . strtolower($search) . '%'])
                    ->orWhereRaw('LOWER(fbr_invoice_number) LIKE ?', ['%' . strtolower($search) . '%']);
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

        if ($transaction->invoice_mode === 'local') {
            return redirect()->route('fbrpos.show', $id)->with('error', 'Local invoices cannot be submitted to FBR.');
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

    /**
     * Fail Queue — list of all failed/pending FBR POS transactions for this company
     */
    public function failQueue(Request $request)
    {
        $companyId = app('currentCompanyId');

        $query = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['failed', 'pending'])
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->with(['fbrLogs' => function ($q) {
                $q->latest()->limit(1);
            }]);

        if ($request->search) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->whereRaw('LOWER(invoice_number) LIKE ?', ['%' . strtolower($s) . '%'])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', ['%' . strtolower($s) . '%']);
            });
        }

        $transactions = $query->latest()->paginate(20)->withQueryString();

        $stats = FbrPosTransaction::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->selectRaw("
                SUM(CASE WHEN fbr_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN fbr_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN fbr_status = 'submitted' THEN 1 ELSE 0 END) as submitted_count,
                SUM(CASE WHEN fbr_status = 'failed' THEN total_amount ELSE 0 END) as failed_amount
            ")
            ->first();

        return view('fbr-pos.fail-queue', compact('transactions', 'stats'));
    }

    /**
     * Bulk retry — schedule auto-retry job for all failed invoices
     */
    public function failQueueRetryAll()
    {
        $companyId = app('currentCompanyId');

        $failed = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['failed', 'pending'])
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->get();

        $count = 0;
        foreach ($failed as $tx) {
            $tx->fbr_submission_hash = null;
            $tx->save();
            \App\Jobs\RetryFbrPosSubmissionJob::dispatch($tx->id)->delay(now()->addSeconds(10));
            $count++;
        }

        return redirect()->route('fbrpos.failQueue')
            ->with('success', "Scheduled auto-retry for {$count} failed invoice(s). Each will retry up to 3 times (10s/20s/30s apart).");
    }

    /**
     * Schedule retry job for a single failed invoice
     */
    public function failQueueRetryOne($id)
    {
        $companyId = app('currentCompanyId');
        $tx = FbrPosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($tx->fbr_status === 'submitted') {
            return back()->with('error', 'Already submitted to FBR.');
        }

        $tx->fbr_submission_hash = null;
        $tx->save();
        \App\Jobs\RetryFbrPosSubmissionJob::dispatch($tx->id)->delay(now()->addSeconds(5));

        return back()->with('success', "Auto-retry scheduled for invoice #{$tx->invoice_number}.");
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
            if ($request->has('pin_update')) {
                if ($request->has('remove_pin')) {
                    $company->update(['confidential_pin' => null]);
                    return back()->with('success', 'Confidential PIN removed successfully.');
                }

                if ($request->filled('confidential_pin')) {
                    $request->validate(['confidential_pin' => 'required|digits_between:4,6']);
                    $company->update(['confidential_pin' => \Hash::make($request->confidential_pin)]);
                    return back()->with('success', 'Confidential PIN updated successfully.');
                }

                return back()->with('error', 'Please enter a valid 4-6 digit PIN.');
            }

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

    public function receipt($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($id);

        return view('fbr-pos.receipt', compact('transaction', 'company'));
    }

    public function reports()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(discount_amount), 0) as discount')
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(discount_amount), 0) as discount')
            ->first();

        $dailySales = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        $paymentBreakdown = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('payment_method')
            ->get();

        return view('fbr-pos.reports', compact('company', 'todayStats', 'monthStats', 'dailySales', 'paymentBreakdown'));
    }

    public function taxReports()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $monthlyTax = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as total_tax, COALESCE(SUM(subtotal), 0) as total_sales, COALESCE(SUM(fbr_service_charge), 0) as total_pos_fee, COUNT(*) as invoice_count')
            ->first();

        $fbrStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw("
                COUNT(CASE WHEN fbr_status = 'submitted' THEN 1 END) as submitted,
                COUNT(CASE WHEN fbr_status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN fbr_status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN fbr_status = 'local' THEN 1 END) as local_count
            ")->first();

        $taxByRate = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('tax_rate, COUNT(*) as count, COALESCE(SUM(tax_amount), 0) as tax_total, COALESCE(SUM(subtotal), 0) as sales_total')
            ->groupBy('tax_rate')
            ->orderBy('tax_rate')
            ->get();

        return view('fbr-pos.tax-reports', compact('company', 'monthlyTax', 'fbrStats', 'taxByRate'));
    }

    public function businessProfile(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'nullable|string|max:500',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'ntn' => 'nullable|string|max:20',
                'print_paper_size' => 'nullable|in:thermal,a4',
                'receipt_footer_note' => 'nullable|string|max:255',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'remove_logo' => 'nullable|boolean',
            ]);

            // Handle logo upload / removal
            if ($request->boolean('remove_logo') && $company->logo_path) {
                \Storage::disk('public')->delete($company->logo_path);
                $company->logo_path = null;
            }
            if ($request->hasFile('logo')) {
                if ($company->logo_path) {
                    \Storage::disk('public')->delete($company->logo_path);
                }
                $company->logo_path = $request->file('logo')->store('company-logos', 'public');
            }

            $company->fill([
                'name' => $validated['name'],
                'address' => $validated['address'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'ntn' => $validated['ntn'] ?? null,
                'print_paper_size' => $validated['print_paper_size'] ?? 'thermal',
                'receipt_footer_note' => $validated['receipt_footer_note'] ?? null,
            ])->save();

            return redirect()->route('fbrpos.business-profile')->with('success', 'Business profile updated successfully.');
        }

        return view('fbr-pos.business-profile', compact('company'));
    }

    public function myProfile(Request $request)
    {
        $user = Auth::guard('fbrpos')->user();

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'nullable|string|max:20',
                'username' => 'nullable|string|max:100|unique:users,username,' . $user->id,
                'current_password' => 'nullable|required_with:new_password',
                'new_password' => 'nullable|min:8|confirmed',
            ]);

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->phone = $validated['phone'] ?? $user->phone;
            $user->username = $validated['username'] ?? $user->username;

            if (!empty($validated['current_password'])) {
                if (!\Hash::check($validated['current_password'], $user->password)) {
                    return back()->withErrors(['current_password' => 'Current password is incorrect.']);
                }
                $user->password = \Hash::make($validated['new_password']);
            }

            $user->save();
            return redirect()->route('fbrpos.my-profile')->with('success', 'Profile updated successfully.');
        }

        return view('fbr-pos.my-profile', compact('user'));
    }

    public function downloadPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("FBR-POS-Invoice-{$transaction->invoice_number}.pdf");
    }

    public function previewPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("FBR-POS-Invoice-{$transaction->invoice_number}.pdf");
    }

    public function dayCloseReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $date = $request->get('date', today()->format('Y-m-d'));

        $existingReport = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $stats = (object) [
            'total_invoices' => $transactions->count(),
            'fbr_invoices' => $transactions->where('fbr_status', 'submitted')->count(),
            'local_invoices' => $transactions->where('fbr_status', 'local')->count(),
            'failed_invoices' => $transactions->whereIn('fbr_status', ['failed', 'pending'])->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_fbr_fee' => $transactions->sum('fbr_service_charge'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $transactions->where('payment_method', 'cash')->sum('total_amount'),
            'card_amount' => $transactions->where('payment_method', 'card')->sum('total_amount'),
            'other_amount' => $transactions->whereNotIn('payment_method', ['cash', 'card'])->sum('total_amount'),
            'first_invoice' => $transactions->first(),
            'last_invoice' => $transactions->last(),
        ];

        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $previousReports = FbrDayCloseReport::where('company_id', $companyId)
            ->orderBy('report_date', 'desc')
            ->limit(10)
            ->get();

        return view('fbr-pos.day-close', compact('company', 'date', 'stats', 'existingReport', 'cashierBreakdown', 'previousReports', 'transactions'));
    }

    public function closeDayReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = Auth::guard('fbrpos')->user();
        $date = $request->input('date', today()->format('Y-m-d'));

        $existing = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        if ($existing) {
            return back()->with('error', 'Day Close Report for this date already exists.');
        }

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        if ($transactions->isEmpty()) {
            return back()->with('error', 'No transactions found for this date.');
        }

        $reportCount = FbrDayCloseReport::where('company_id', $companyId)->count();
        $reportNumber = 'ZRPT-' . str_pad($reportCount + 1, 5, '0', STR_PAD_LEFT);

        $data = [
            'company_id' => $companyId,
            'report_date' => $date,
            'report_number' => $reportNumber,
            'total_invoices' => $transactions->count(),
            'fbr_invoices' => $transactions->where('fbr_status', 'submitted')->count(),
            'local_invoices' => $transactions->where('fbr_status', 'local')->count(),
            'failed_invoices' => $transactions->whereIn('fbr_status', ['failed', 'pending'])->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_fbr_fee' => $transactions->sum('fbr_service_charge'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $transactions->where('payment_method', 'cash')->sum('total_amount'),
            'card_amount' => $transactions->where('payment_method', 'card')->sum('total_amount'),
            'other_amount' => $transactions->whereNotIn('payment_method', ['cash', 'card'])->sum('total_amount'),
            'first_invoice_number' => $transactions->first()->invoice_number ?? null,
            'last_invoice_number' => $transactions->last()->invoice_number ?? null,
            'first_invoice_time' => $transactions->first()->created_at ?? null,
            'last_invoice_time' => $transactions->last()->created_at ?? null,
            'closed_by' => $user->id,
            'notes' => $request->input('notes'),
        ];

        $hashString = json_encode($data);
        $data['hash'] = hash('sha256', $hashString);

        FbrDayCloseReport::create($data);

        return back()->with('success', 'Day Close Report ' . $reportNumber . ' generated successfully for ' . \Carbon\Carbon::parse($date)->format('d M Y'));
    }

    public function dayCloseReportPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = FbrDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $report->report_date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.day-close-pdf', compact('company', 'report', 'transactions', 'cashierBreakdown'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("Day-Close-{$report->report_number}-{$report->report_date->format('Y-m-d')}.pdf");
    }

    public function products(Request $request)
    {
        $companyId = app('currentCompanyId');
        $search = $request->get('search', '');
        $query = Product::where('company_id', $companyId);
        if ($search) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('hs_code', $like, "%{$search}%");
            });
        }
        $products = $query->orderBy('name')->paginate(20);
        return view('fbr-pos.products', compact('products', 'search'));
    }

    public function createProduct()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        return view('fbr-pos.product-form');
    }

    public function storeProduct(Request $request)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $request->validate([
            'name' => 'required|string|max:255',
            'default_price' => 'required|numeric|min:0',
            'hs_code' => 'nullable|string|max:50',
            'uom' => 'nullable|string|max:20',
            'barcode' => 'nullable|string|max:64',
            'sku' => 'nullable|string|max:64',
            'tax_type' => 'required|in:taxable,exempt,custom',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $taxType = $request->tax_type;
        $taxRate = $taxType === 'taxable' ? 18 : ($taxType === 'exempt' ? 0 : ($request->default_tax_rate ?? 0));

        Product::create([
            'company_id' => app('currentCompanyId'),
            'name' => $request->name,
            'barcode' => $request->barcode ?: null,
            'sku' => $request->sku ?: null,
            'default_price' => $request->default_price,
            'hs_code' => $request->hs_code,
            'uom' => $request->uom ?? 'U',
            'tax_type' => $taxType,
            'default_tax_rate' => $taxRate,
        ]);

        return redirect()->route('fbrpos.products')->with('success', 'Product created successfully.');
    }

    public function editProduct($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        return view('fbr-pos.product-form', compact('product'));
    }

    public function updateProduct(Request $request, $id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'default_price' => 'required|numeric|min:0',
            'hs_code' => 'nullable|string|max:50',
            'uom' => 'nullable|string|max:20',
            'barcode' => 'nullable|string|max:64',
            'sku' => 'nullable|string|max:64',
            'tax_type' => 'required|in:taxable,exempt,custom',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $taxType = $request->tax_type;
        $taxRate = $taxType === 'taxable' ? 18 : ($taxType === 'exempt' ? 0 : ($request->default_tax_rate ?? 0));

        $product->update([
            'name' => $request->name,
            'barcode' => $request->barcode ?: null,
            'sku' => $request->sku ?: null,
            'default_price' => $request->default_price,
            'hs_code' => $request->hs_code,
            'uom' => $request->uom ?? 'U',
            'tax_type' => $taxType,
            'default_tax_rate' => $taxRate,
        ]);

        return redirect()->route('fbrpos.products')->with('success', 'Product updated successfully.');
    }

    public function toggleProduct($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        return redirect()->route('fbrpos.products')->with('success', 'Product status updated.');
    }

    public function destroyProduct($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        $name = $product->name;
        $product->delete();
        return redirect()->route('fbrpos.products')->with('success', "Product \"{$name}\" deleted.");
    }

    public function searchProducts(Request $request)
    {
        $companyId = app('currentCompanyId');
        $q = trim((string) $request->get('q', ''));
        $like = \App\Helpers\DbCompat::like();
        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q, $like) {
                $query->where('name', $like, "%{$q}%")
                      ->orWhere('hs_code', $like, "%{$q}%")
                      ->orWhere('barcode', $like, "%{$q}%")
                      ->orWhere('sku', $like, "%{$q}%");
            })
            ->take(15)
            ->get(['id', 'name', 'hs_code', 'barcode', 'sku', 'default_price', 'default_tax_rate', 'tax_type', 'uom']);

        return response()->json($products);
    }

    public function lookupByBarcode(Request $request)
    {
        $companyId = app('currentCompanyId');
        $code = trim((string) $request->get('code', ''));
        if ($code === '') {
            return response()->json(['found' => false]);
        }
        $product = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->first(['id', 'name', 'hs_code', 'barcode', 'sku', 'default_price', 'default_tax_rate', 'tax_type', 'uom']);

        if (!$product) {
            return response()->json(['found' => false]);
        }
        return response()->json(['found' => true, 'product' => $product]);
    }
}
