<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\BranchStockService;
use App\Services\InventoryService;
use App\Services\PharmacyBatchService;
use App\Services\PkPhone;
use App\Services\SupplierLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS — distributor ledger: payments, statements, purchase returns
 * (Task 1580). Lives beside FbrPosStockController under /fbr-pos/stock/…
 * so the existing owner/manager gate, branch scoping and Custom Access
 * mapping ("inventory") cover it unchanged.
 *
 * Rules (khata/stock rule): owner + manager only, cashiers and viewers 403.
 * Every balance shown here comes from SupplierLedgerService — never a
 * local SUM(). Payments are void-only (never edited); returns are final
 * documents; everything money-moving is audit-logged.
 */
class FbrPosSupplierLedgerController extends Controller
{
    private function user() { return Auth::guard('fbrpos')->user(); }
    private function companyId(): int { return (int) $this->user()->company_id; }

    private function assertNotCashier(): void
    {
        $u = $this->user();
        if (in_array($u->pos_role ?? '', ['pos_cashier', 'local_viewer'], true)) {
            abort(403, 'Sirf admin/manager stock manage kar sakte hain.');
        }
    }

    /** The ledger pages 404 politely until the migration has run on this host. */
    private function assertLedgerReady(): void
    {
        if (!SupplierLedgerService::schemaReady()) {
            abort(404);
        }
    }

    private function branchView(int $companyId): array
    {
        $branches = BranchStockService::branches($companyId);
        $activeBranchId = BranchStockService::viewBranchId($companyId);

        return [
            'branches' => BranchStockService::actorBranches($companyId),
            'multiBranch' => $branches->isNotEmpty(),
            'canTransfer' => BranchStockService::canTransfer($companyId),
            'activeBranchId' => $activeBranchId,
            'activeBranchName' => BranchStockService::branchName($companyId, $activeBranchId),
            'allBranches' => BranchStockService::viewingAllBranches($companyId),
            'branchNames' => $branches->pluck('name', 'id')->all(),
        ];
    }

    private function assertBranchAllowed(int $companyId, ?int $branchId): void
    {
        if ($branchId !== null && !BranchStockService::actorCanUse($companyId, $branchId)) {
            abort(403, __('pos.access_denied'));
        }
    }

    /** Supplier row for this company or 404 — never another shop's distributor. */
    private function supplierOr404(int $companyId, $id): Supplier
    {
        return Supplier::forCompany($companyId)->findOrFail((int) $id);
    }

    /**
     * The branch a purchase bill belongs to, resolved SERVER-SIDE: the bill's
     * own branch_id, else the branch its goods were actually booked into
     * (purchase movements). NULL = single-shop company, or a legacy bill that
     * never recorded a branch. Mixed movements (should not exist) count as
     * "unknown" so the caller falls back to an explicit branch choice.
     */
    private function purchaseBranchId(int $companyId, PurchaseOrder $po): ?int
    {
        if (!BranchStockService::isMultiBranch($companyId)) {
            return null;
        }
        if (Schema::hasColumn('purchase_orders', 'branch_id') && $po->branch_id !== null) {
            return (int) $po->branch_id;
        }
        $branchIds = InventoryMovement::where('company_id', $companyId)
            ->where('type', InventoryMovement::TYPE_PURCHASE)
            ->where('reference_type', 'purchase_order')
            ->where('reference_id', $po->id)
            ->whereNotNull('branch_id')
            ->distinct()
            ->pluck('branch_id')
            ->map(fn ($b) => (int) $b)
            ->unique()
            ->values();

        return $branchIds->count() === 1 ? (int) $branchIds->first() : null;
    }

    /**
     * Load a purchase bill this actor may touch: same company, optionally the
     * same supplier, and — on a multi-branch company — a branch the actor is
     * allowed to use. A confined manager must never see, pay against or return
     * another shop's bill, so a foreign-branch bill answers 404 exactly like a
     * foreign company's would (no existence leak).
     *
     * Returns [$po, $poBranchId]; $po is null when not found / not allowed.
     */
    private function purchaseForActor(int $companyId, int $poId, ?int $supplierId = null): array
    {
        $q = PurchaseOrder::where('company_id', $companyId)->with('items.product:id,name,uom');
        if ($supplierId !== null) {
            $q->where('supplier_id', $supplierId);
        }
        $po = $q->find($poId);
        if (!$po) {
            return [null, null];
        }
        $poBranch = $this->purchaseBranchId($companyId, $po);
        if ($poBranch !== null && !BranchStockService::actorCanUse($companyId, $poBranch)) {
            return [null, null];
        }

        return [$po, $poBranch];
    }

    /** Parse the optional from/to filter (Y-m-d); garbage = no filter. */
    private function period(Request $request): array
    {
        $parse = function ($v) {
            if (!is_string($v) || $v === '') return null;
            try {
                return \Illuminate\Support\Carbon::parse($v)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        };
        $from = $parse($request->get('from'));
        $to = $parse($request->get('to'));
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Payments
    // ═════════════════════════════════════════════════════════════════════

    /** Record money handed to a distributor (any time, optional bill reference). */
    public function storePayment(Request $request)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $request->validate([
            'supplier_id' => 'required|integer',
            'purchase_order_id' => 'nullable|integer',
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'method' => 'required|string|in:cash,bank,online,cheque',
            'paid_on' => 'nullable|date',
            'reference' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:500',
            'branch_id' => 'nullable|integer',
        ]);

        $companyId = $this->companyId();
        $supplier = $this->supplierOr404($companyId, $request->supplier_id);

        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $this->assertBranchAllowed($companyId, $picked);
        $branchId = BranchStockService::writeBranchId($companyId, $picked ?? BranchStockService::viewBranchId($companyId));

        $poId = null;
        if ($request->filled('purchase_order_id')) {
            [$po, $poBranch] = $this->purchaseForActor($companyId, (int) $request->purchase_order_id, (int) $supplier->id);
            if (!$po) {
                return back()->withInput()->with('error', __('pos.sl_bill_not_found'));
            }
            $poId = (int) $po->id;
            // A payment against a bill is booked in the BILL's branch — the
            // branch selector on screen must not re-home another shop's money.
            if ($poBranch !== null) {
                $branchId = $poBranch;
            }
        }

        $paidOn = $request->filled('paid_on')
            ? \Illuminate\Support\Carbon::parse($request->paid_on)->toDateString()
            : now()->toDateString();
        if ($paidOn > now()->toDateString()) {
            return back()->withInput()->with('error', __('pos.sl_paid_on_future'));
        }

        $payment = SupplierLedgerService::recordPayment([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $poId,
            'amount' => (float) $request->amount,
            'method' => $request->method,
            'paid_on' => $paidOn,
            'reference' => $request->filled('reference') ? trim((string) $request->reference) : null,
            'notes' => $request->filled('notes') ? trim((string) $request->notes) : null,
        ], (int) $this->user()->id);

        $balance = SupplierLedgerService::balanceFor($companyId, $supplier->id, BranchStockService::viewBranchId($companyId))->balance;
        $msg = __('pos.sl_payment_saved', [
            'amount' => number_format($payment->amount, 2),
            'name' => $supplier->name,
            'balance' => number_format($balance, 2),
        ]);

        return $this->backToLedger($request, $supplier->id, $msg);
    }

    /** Void a payment — the ONLY way to correct one (re-enter afterwards). */
    public function voidPayment(Request $request, $id)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $companyId = $this->companyId();
        $payment = SupplierPayment::where('company_id', $companyId)->findOrFail((int) $id);
        // A manager confined to one shop may not void another shop's payment.
        $this->assertBranchAllowed($companyId, $payment->branch_id !== null ? (int) $payment->branch_id : null);

        if ($payment->isVoid()) {
            return $this->backToLedger($request, $payment->supplier_id, null, __('pos.sl_payment_already_void'));
        }

        SupplierLedgerService::voidPayment($payment, (int) $this->user()->id, $request->get('reason'));

        return $this->backToLedger($request, $payment->supplier_id, __('pos.sl_payment_voided'));
    }

    private function backToLedger(Request $request, $supplierId, ?string $success, ?string $error = null)
    {
        $target = $request->get('return_to') === 'statement'
            ? route('fbrpos.stock.supplier.statement', $supplierId)
            : route('fbrpos.stock') . '#suppliers';
        $resp = redirect($target);

        return $error ? $resp->with('error', $error) : $resp->with('success', $success);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Statement
    // ═════════════════════════════════════════════════════════════════════

    /** Everything the statement page, PDF and CSV share. */
    private function statementData(Request $request, $id): array
    {
        $companyId = $this->companyId();
        $company = Company::find($companyId);
        $supplier = $this->supplierOr404($companyId, $id);
        [$from, $to] = $this->period($request);
        $branchView = $this->branchView($companyId);
        $branchId = $branchView['activeBranchId'];

        $statement = SupplierLedgerService::statement($companyId, $supplier->id, $branchId, $from, $to);
        $balance = SupplierLedgerService::balanceFor($companyId, $supplier->id, $branchId);

        // Open bills for the "payment against bill" picker (non-void, this supplier).
        $billsQ = PurchaseOrder::where('company_id', $companyId)
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED);
        if ($branchId !== null && Schema::hasColumn('purchase_orders', 'branch_id')) {
            $billsQ->where('branch_id', $branchId);
        }
        $bills = $billsQ->orderByDesc('id')->limit(60)->get(['id', 'po_number', 'supplier_invoice_no', 'total_amount', 'received_date', 'created_at']);

        return array_merge($branchView, [
            'company' => $company,
            'supplier' => $supplier,
            'statement' => $statement,
            'balance' => $balance,
            'from' => $from,
            'to' => $to,
            'bills' => $bills,
            'waUrl' => $this->statementWaUrl($company, $supplier, $statement, $balance),
        ]);
    }

    public function statement(Request $request, $id)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();

        if ($request->get('export') === 'csv') {
            return $this->statementCsv($request, $id);
        }

        return view('fbr-pos.supplier-statement', $this->statementData($request, $id));
    }

    public function statementPdf(Request $request, $id)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $data = $this->statementData($request, $id);
        $filename = 'Supplier_Statement_' . preg_replace('/[^A-Za-z0-9]+/', '_', $data['supplier']->name) . '_' . now()->format('Ymd') . '.pdf';

        return $this->renderReportPdf('fbr-pos.supplier-statement-pdf', $data, $filename);
    }

    private function statementCsv(Request $request, $id)
    {
        $data = $this->statementData($request, $id);
        $supplier = $data['supplier'];
        $st = $data['statement'];
        $filename = 'Supplier_Statement_' . preg_replace('/[^A-Za-z0-9]+/', '_', $supplier->name) . '_' . now()->format('Ymd') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];
        $csvSafe = function ($value) {
            $s = (string) $value;
            if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'" . $s;
            }
            return $s;
        };

        $callback = function () use ($st, $supplier, $csvSafe) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Supplier', $csvSafe($supplier->name)]);
            fputcsv($file, ['Period', ($st['from'] ?? 'start') . ' to ' . ($st['to'] ?? 'today')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Type', 'Reference', 'Detail', 'Debit (Billed)', 'Credit (Paid/Returned)', 'Balance']);
            fputcsv($file, ['', 'Opening balance', '', '', '', '', number_format($st['opening'], 2, '.', '')]);
            foreach ($st['rows'] as $r) {
                fputcsv($file, [
                    $r['date'],
                    ucfirst($r['kind']) . ($r['void'] ? ' (VOID)' : ''),
                    $csvSafe($r['ref']),
                    $csvSafe($r['detail']),
                    $r['debit'] > 0 ? number_format($r['debit'], 2, '.', '') : '',
                    $r['credit'] > 0 ? number_format($r['credit'], 2, '.', '') : '',
                    number_format($r['balance'], 2, '.', ''),
                ]);
            }
            fputcsv($file, []);
            fputcsv($file, ['', 'Closing balance', '', '', number_format($st['period']['billed'], 2, '.', ''),
                number_format($st['period']['paid'] + $st['period']['returned'] + $st['period']['credited'], 2, '.', ''),
                number_format($st['closing'], 2, '.', '')]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * "WhatsApp par bhejo" — compact reconciliation text the owner reads out
     * to the distributor rep (mirrors the khata reminder share). NULL when
     * the supplier has no routable Pakistani number.
     */
    private function statementWaUrl(?Company $company, Supplier $supplier, array $statement, object $balance): ?string
    {
        $normalized = PkPhone::normalize($supplier->phone);
        if ($normalized === null) {
            return null;
        }
        $fmt = fn ($v) => number_format((float) $v, 0);
        $period = $statement['from'] || $statement['to']
            ? (($statement['from'] ? \Illuminate\Support\Carbon::parse($statement['from'])->format('d M Y') : '…') . ' – '
                . ($statement['to'] ? \Illuminate\Support\Carbon::parse($statement['to'])->format('d M Y') : now()->format('d M Y')))
            : __('pos.sl_wa_all_time');
        $msg = __('pos.sl_wa_message', [
            'shop' => $company?->name ?? '',
            'supplier' => $supplier->name,
            'period' => $period,
            'opening' => $fmt($statement['opening']),
            'purchases' => $fmt($statement['period']['billed']),
            'payments' => $fmt($statement['period']['paid']),
            'returns' => $fmt($statement['period']['returned'] + $statement['period']['credited']),
            'closing' => $fmt($statement['closing']),
            'outstanding' => $fmt($balance->balance),
        ]);

        return PkPhone::waUrl($normalized, $msg);
    }

    /** A4 report PDF — mPDF for Urdu script, DomPDF otherwise (shared pattern). */
    private function renderReportPdf(string $view, array $data, string $filename, string $orientation = 'portrait')
    {
        $isUrdu = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT;
        $data['pdfUrdu'] = $isUrdu;

        if ($isUrdu) {
            try {
                return \App\Support\MpdfRenderer::render($view, $data, 'a4-report', $filename, false, $orientation);
            } catch (\Throwable $e) {
                \Log::warning("mPDF report render failed [{$filename}]: " . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale();
        $data['pdfUrdu'] = false;

        // Subset the embedded fonts — a statement is text only, whole-font
        // embedding would make every download ~880 KB.
        return \Barryvdh\DomPDF\Facade\Pdf::setOption('enable_font_subsetting', true)
            ->loadView($view, $data)
            ->setPaper('a4', $orientation)
            ->download($filename);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Purchase returns
    // ═════════════════════════════════════════════════════════════════════

    /** Returns page: list + the "maal wapas bhejo" form. */
    public function returns(Request $request)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $companyId = $this->companyId();
        $company = Company::find($companyId);
        $branchView = $this->branchView($companyId);
        $branchId = $branchView['activeBranchId'];

        $returnsQ = PurchaseReturn::where('company_id', $companyId)
            ->with('supplier:id,name', 'items.product:id,name', 'purchaseOrder:id,po_number');
        if ($branchId !== null) {
            $returnsQ->where('branch_id', $branchId);
        }
        if ($request->filled('supplier_id')) {
            $returnsQ->where('supplier_id', (int) $request->supplier_id);
        }
        $returns = $returnsQ->orderByDesc('id')->paginate(25)->withQueryString();

        $suppliers = Supplier::forCompany($companyId)->active()->orderBy('name')->get(['id', 'name']);
        $products = Product::where('company_id', $companyId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'sku', 'barcode', 'uom']);

        // Recent bills for the "against this bill" picker (JSON for Alpine).
        $billsQ = PurchaseOrder::where('company_id', $companyId)
            ->where('status', PurchaseOrder::STATUS_RECEIVED)
            ->with('supplier:id,name');
        if ($branchId !== null && Schema::hasColumn('purchase_orders', 'branch_id')) {
            $billsQ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }
        $bills = $billsQ->orderByDesc('id')->limit(120)->get()->map(fn ($po) => [
            'id' => (int) $po->id,
            'label' => $po->po_number . ($po->getAttribute('supplier_invoice_no') ? ' · #' . $po->supplier_invoice_no : '')
                . ' · ' . ($po->received_date ?? $po->created_at)?->format('d M Y'),
            'supplier_id' => $po->supplier_id ? (int) $po->supplier_id : null,
            'supplier' => $po->supplier?->name,
        ])->values()->all();

        return view('fbr-pos.stock-returns', array_merge($branchView, [
            'company' => $company,
            'returns' => $returns,
            'suppliers' => $suppliers,
            'products' => $products,
            'billsData' => $bills,
            'batchTracking' => PharmacyBatchService::trackingEnabled($company),
            'reasons' => PurchaseReturn::REASONS,
            'preselectPo' => $request->filled('po') ? (int) $request->po : null,
            'preselectSupplier' => $request->filled('supplier_id') ? (int) $request->supplier_id : null,
        ]));
    }

    /** Lines of one received bill (JSON) — what the return form offers to send back. */
    public function purchaseLines($id)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $companyId = $this->companyId();
        [$po] = $this->purchaseForActor($companyId, (int) $id);
        if (!$po) {
            // Foreign company AND foreign branch answer the same way.
            return response()->json(['success' => false, 'error' => __('pos.sl_bill_not_found')], 404);
        }

        $already = PurchaseReturnItem::whereIn('purchase_return_id', function ($q) use ($companyId, $po) {
                $q->select('id')->from('purchase_returns')
                  ->where('company_id', $companyId)
                  ->where('purchase_order_id', $po->id)
                  ->where('status', PurchaseReturn::STATUS_POSTED);
            })
            ->whereNotNull('purchase_order_item_id')
            ->selectRaw('purchase_order_item_id, SUM(quantity) as q')
            ->groupBy('purchase_order_item_id')
            ->pluck('q', 'purchase_order_item_id');

        return response()->json([
            'success' => true,
            'purchase' => [
                'id' => (int) $po->id,
                'po_number' => $po->po_number,
                'supplier_id' => $po->supplier_id ? (int) $po->supplier_id : null,
                'voided' => $po->status === PurchaseOrder::STATUS_CANCELLED,
            ],
            'lines' => $po->items->map(function ($it) use ($already) {
                $received = (float) $it->received_quantity;
                $returned = (float) ($already[$it->id] ?? 0);
                return [
                    'item_id' => (int) $it->id,
                    'product_id' => (int) $it->product_id,
                    'name' => $it->product?->name ?? ('#' . $it->product_id),
                    'uom' => $it->product?->uom ?: 'U',
                    'received' => $received,
                    'returned' => $returned,
                    'remaining' => round(max(0, $received - $returned), 3),
                    'unit_cost' => round($it->effectiveUnitCost(), 4),
                    'batch_number' => $it->getAttribute('batch_number'),
                    'expiry_date' => $it->getAttribute('expiry_date') ? \Illuminate\Support\Carbon::parse($it->getAttribute('expiry_date'))->toDateString() : null,
                ];
            })->values()->all(),
        ]);
    }

    /**
     * Post a purchase return: stock out (batch-aware), credit note on the
     * ledger, printable document. One transaction; nothing partial.
     */
    public function storeReturn(Request $request)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $request->validate([
            'supplier_id' => 'nullable|integer',
            'purchase_order_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'reason' => 'required|string|in:' . implode(',', PurchaseReturn::REASONS),
            'supplier_reference' => 'nullable|string|max:60',
            'returned_on' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.purchase_order_item_id' => 'nullable|integer',
            'items.*.batch_id' => 'nullable|integer',
            'items.*.batch_number' => 'nullable|string|max:60',
        ]);

        $companyId = $this->companyId();
        BranchStockService::healLegacyRows($companyId);

        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $this->assertBranchAllowed($companyId, $picked);
        if ($picked === null && BranchStockService::viewingAllBranches($companyId)) {
            return back()->withInput()->with('error', __('pos.stock_edit_pick_branch'));
        }
        $branchId = BranchStockService::writeBranchId($companyId, $picked ?? BranchStockService::viewBranchId($companyId));

        $po = null;
        if ($request->filled('purchase_order_id')) {
            [$po, $poBranch] = $this->purchaseForActor($companyId, (int) $request->purchase_order_id);
            if (!$po) {
                return back()->withInput()->with('error', __('pos.sl_bill_not_found'));
            }
            if ($po->status === PurchaseOrder::STATUS_CANCELLED) {
                return back()->withInput()->with('error', __('pos.sl_return_bill_void'));
            }
            // Goods go back out of the shop that received them. The bill's
            // branch is authoritative; a different branch on screen is refused
            // rather than silently re-homed (stock would vanish from the wrong
            // shop). A legacy bill with no known branch uses the picked branch.
            if ($poBranch !== null && $branchId !== null && $poBranch !== $branchId) {
                return back()->withInput()->with('error', __('pos.sl_return_branch_mismatch', [
                    'branch' => BranchStockService::branchName($companyId, $poBranch) ?? ('#' . $poBranch),
                ]));
            }
            if ($poBranch !== null) {
                $branchId = $poBranch;
            }
        }

        $supplierId = $request->filled('supplier_id') ? (int) $request->supplier_id : ($po?->supplier_id ? (int) $po->supplier_id : null);
        if ($po && $po->supplier_id && $supplierId !== (int) $po->supplier_id) {
            // The credit belongs to whoever sent the bill.
            $supplierId = (int) $po->supplier_id;
        }
        $supplier = $supplierId ? Supplier::forCompany($companyId)->find($supplierId) : null;
        if (!$supplier) {
            return back()->withInput()->with('error', __('pos.sl_return_needs_supplier'));
        }

        $productIds = collect($request->items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $validIds = Product::where('company_id', $companyId)->whereIn('id', $productIds)->pluck('id')->all();
        if ($productIds->diff($validIds)->isNotEmpty()) {
            return back()->withInput()->with('error', 'Ghalat product select hua — dobara koshish karein.');
        }

        // A bill-linked return is AUTHORITATIVE from the bill: every line must
        // name a line of that bill with the same product. The credit rate is
        // the bill's own net unit cost (server-side), never the posted value —
        // a crafted or stale form must not mint an arbitrary credit note.
        if ($po) {
            foreach ($request->items as $row) {
                $poItem = !empty($row['purchase_order_item_id'])
                    ? $po->items->firstWhere('id', (int) $row['purchase_order_item_id'])
                    : null;
                if (!$poItem || (int) $poItem->product_id !== (int) $row['product_id']) {
                    return back()->withInput()->with('error', __('pos.sl_return_line_not_on_bill'));
                }
            }
        }

        $returnedOn = $request->filled('returned_on')
            ? \Illuminate\Support\Carbon::parse($request->returned_on)->toDateString()
            : now()->toDateString();
        if ($returnedOn > now()->toDateString()) {
            return back()->withInput()->with('error', __('pos.sl_paid_on_future'));
        }

        $batchTracking = PharmacyBatchService::trackingEnabled(Company::find($companyId));
        $userId = (int) $this->user()->id;

        try {
            $ret = DB::transaction(function () use ($request, $companyId, $branchId, $po, $supplier, $returnedOn, $batchTracking, $userId) {
                // Serialise every return against one bill: the bill row is
                // locked for the whole posting, so two concurrent forms cannot
                // both pass the "remaining" check and together send back more
                // than the bill delivered. Lines are re-read under the lock.
                $lockedItems = collect();
                if ($po) {
                    $lockedPo = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
                    if (!$lockedPo || $lockedPo->status === PurchaseOrder::STATUS_CANCELLED) {
                        throw new \RuntimeException(__('pos.sl_return_bill_void'));
                    }
                    $lockedItems = \App\Models\PurchaseOrderItem::where('purchase_order_id', $po->id)
                        ->lockForUpdate()->get()->keyBy('id');
                }

                $ret = PurchaseReturn::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => $po?->id,
                    'return_number' => $this->nextReturnNumber($companyId),
                    'reason' => $request->reason,
                    'supplier_reference' => $request->filled('supplier_reference') ? trim((string) $request->supplier_reference) : null,
                    'credit_amount' => 0,
                    'status' => PurchaseReturn::STATUS_POSTED,
                    'returned_on' => $returnedOn,
                    'notes' => $request->filled('notes') ? trim((string) $request->notes) : null,
                    'created_by' => $userId,
                ]);

                $credit = 0.0;
                foreach ($request->items as $row) {
                    $productId = (int) $row['product_id'];
                    $qty = round((float) $row['quantity'], 3);
                    $unitCost = round((float) $row['unit_cost'], 4);

                    $poItemId = null;
                    if ($po) {
                        $poItem = $lockedItems->get((int) ($row['purchase_order_item_id'] ?? 0));
                        if (!$poItem || (int) $poItem->product_id !== $productId) {
                            throw new \RuntimeException(__('pos.sl_return_line_not_on_bill'));
                        }
                        $poItemId = (int) $poItem->id;
                        // Credit at what the bill actually cost us for that line.
                        $unitCost = round($poItem->effectiveUnitCost(), 4);
                        // Never send back more of a line than that bill delivered
                        // (prior returns summed under the bill lock, this form's
                        // own earlier rows included).
                        $prior = (float) PurchaseReturnItem::where('purchase_order_item_id', $poItemId)
                            ->whereIn('purchase_return_id', fn ($q) => $q->select('id')->from('purchase_returns')
                                ->where('company_id', $companyId)->where('status', PurchaseReturn::STATUS_POSTED))
                            ->sum('quantity');
                        if ($qty + $prior > (float) $poItem->received_quantity + 0.0005) {
                            throw new \RuntimeException(__('pos.sl_return_qty_exceeds', ['name' => $po->items->firstWhere('id', $poItemId)?->product?->name ?? ('#' . $productId)]));
                        }
                    }
                    $lineTotal = round($qty * $unitCost, 2);

                    // Batch: explicit id, else the number typed / carried by the line.
                    $batch = null;
                    $batchNo = PharmacyBatchService::cleanBatchNumber($row['batch_number'] ?? null);
                    if ($batchTracking) {
                        if (!empty($row['batch_id'])) {
                            $batch = ProductBatch::where('company_id', $companyId)
                                ->where('product_id', $productId)
                                ->where('branch_id', $branchId)
                                ->lockForUpdate()
                                ->find((int) $row['batch_id']);
                        } elseif ($batchNo !== null) {
                            $batch = ProductBatch::where('company_id', $companyId)
                                ->where('product_id', $productId)
                                ->where('branch_id', $branchId)
                                ->where('batch_number', $batchNo)
                                ->orderByDesc('quantity')
                                ->lockForUpdate()
                                ->first();
                        }
                        if ($batch) {
                            if ((float) $batch->quantity + 0.0005 < $qty) {
                                throw new \RuntimeException(__('pos.sl_return_batch_short', [
                                    'batch' => $batch->batch_number,
                                    'qty' => rtrim(rtrim(number_format((float) $batch->quantity, 3, '.', ''), '0'), '.'),
                                ]));
                            }
                            $batch->quantity = round((float) $batch->quantity - $qty, 3);
                            $batch->save();
                            $batchNo = $batch->batch_number;
                        }
                    }

                    PurchaseReturnItem::create([
                        'purchase_return_id' => $ret->id,
                        'product_id' => $productId,
                        'purchase_order_item_id' => $poItemId,
                        'batch_id' => $batch?->id,
                        'batch_number' => $batchNo,
                        'expiry_date' => $batch?->expiry_date?->toDateString(),
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'total' => $lineTotal,
                    ]);

                    // Goods leave through the inventory ledger as a return_out
                    // at the agreed credit rate. Average cost is untouched
                    // (issues at any rate never re-weight the remaining stock);
                    // a negative aggregate is allowed exactly like a void.
                    InventoryService::deductStock(
                        $companyId,
                        $productId,
                        $qty,
                        $unitCost,
                        InventoryMovement::TYPE_RETURN_OUT,
                        $branchId,
                        ['type' => 'purchase_return', 'id' => $ret->id, 'number' => $ret->return_number],
                        'Purchase return — ' . $ret->return_number . ' (' . $request->reason . ')',
                        $userId,
                        $batch ? [
                            'batch_id' => $batch->id,
                            'batch_number' => $batch->batch_number,
                            'batch_expiry' => $batch->expiry_date?->toDateString(),
                        ] : []
                    );

                    $credit += $lineTotal;
                }

                $ret->update(['credit_amount' => round($credit, 2)]);

                SupplierLedgerService::audit('purchase_return_created', 'purchase_return', $ret->id, null, [
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => $po?->id,
                    'credit_amount' => round($credit, 2),
                    'reason' => $request->reason,
                    'lines' => count($request->items),
                ], $companyId, $userId);

                return $ret;
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fbrpos.stock.returns')
            ->with('success', __('pos.sl_return_saved', [
                'number' => $ret->return_number,
                'amount' => number_format($ret->credit_amount, 2),
                'name' => $supplier->name,
            ]));
    }

    /** PR-ymd-NNN, unique per company (retry on the rare collision). */
    private function nextReturnNumber(int $companyId): string
    {
        for ($i = 0; $i < 5; $i++) {
            $n = 'PR-' . date('ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
            if (!PurchaseReturn::where('company_id', $companyId)->where('return_number', $n)->exists()) {
                return $n;
            }
        }

        return 'PR-' . date('ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
    }

    /** Printable return note (A4 in-browser print; also the "show" page). */
    public function returnPrint($id)
    {
        $this->assertNotCashier();
        $this->assertLedgerReady();
        $companyId = $this->companyId();
        $ret = PurchaseReturn::where('company_id', $companyId)
            ->with('supplier', 'items.product:id,name,uom', 'purchaseOrder:id,po_number,supplier_invoice_no', 'branch:id,name', 'creator:id,name')
            ->findOrFail((int) $id);
        $this->assertBranchAllowed($companyId, $ret->branch_id !== null ? (int) $ret->branch_id : null);

        return view('fbr-pos.stock-return-print', [
            'company' => Company::find($companyId),
            'ret' => $ret,
        ]);
    }
}
