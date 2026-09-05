<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HealthPharmacyContext;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthSupplierPayment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\BranchStockService;
use App\Services\HealthPharmacyReportService;
use App\Services\HealthPharmacyService;
use App\Services\HealthPharmacyStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Medicine purchasing — receiving stock by supplier, branch, batch and expiry
 * (Task 1549).
 *
 * The purchase document itself is a shared `purchase_orders` row, exactly as
 * the POS panels write it, so the platform's purchase history keeps working.
 * What healthcare adds is the batch layer: each received LINE becomes a lot
 * with its own expiry, cost and sale price. Supplier money is healthcare-owned
 * (health_supplier_payments) so the shared table keeps its meaning elsewhere.
 */
class HealthPharmacyPurchaseController extends Controller
{
    use HealthPharmacyContext;

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        [$from, $to] = HealthPharmacyReportService::range($request->query('from'), $request->query('to'));

        $purchases = HealthPharmacyReportService::purchases($companyId, $branchId, $allBranches, $from, $to);

        // Batch lines for the listed purchases, in one read (live throws on
        // lazy loading, so nothing may be resolved row by row in the view).
        $batches = HealthMedicineBatch::withoutGlobalScopes()
            ->with('medicine')
            ->where('company_id', $companyId)
            ->whereIn('purchase_order_id', $purchases->pluck('id')->all())
            ->get()
            ->groupBy('purchase_order_id');

        $paid = DB::table('health_supplier_payments')
            ->where('company_id', $companyId)
            ->whereIn('purchase_order_id', $purchases->pluck('id')->all())
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, COALESCE(SUM(amount), 0) as paid')
            ->pluck('paid', 'purchase_order_id');

        return view('health.pharmacy.purchases', [
            'purchases' => $purchases,
            'batchMap' => $batches,
            'paidMap' => $paid,
            'suppliers' => Supplier::forCompany($companyId)->orderBy('name')->get(),
            'medicines' => $this->medicineOptions(),
            'balances' => HealthPharmacyReportService::supplierBalances($companyId),
            'branches' => $this->branches(),
            'isMultiBranch' => BranchStockService::isMultiBranch($companyId),
            'viewBranchId' => $branchId,
            'from' => $from,
            'to' => $to,
            'canManage' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.manage', $this->company()),
        ]);
    }

    /**
     * One-shot receiving: the goods are on the shelf the moment the form is
     * saved, because that is what actually happened at the delivery door.
     * A draft/ordered workflow would let stock exist on paper only.
     */
    public function store(Request $request)
    {
        $this->assertCan('pharmacy.manage');

        $request->validate([
            'supplier_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'invoice_reference' => 'nullable|string|max:64',
            'order_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:24',
            'items' => 'required|array|min:1',
            'items.*.medicine_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.cost_price' => 'required|numeric|min:0',
            'items.*.sale_price' => 'nullable|numeric|min:0',
            'items.*.batch_no' => 'nullable|string|max:64',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.manufacture_date' => 'nullable|date',
        ]);

        $companyId = $this->companyId();
        $picked = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        if ($this->mustPickBranch($picked)) {
            return back()->withInput()->with('error', __('health.ph_pick_branch'));
        }

        $branchId = $this->writeBranchId($picked);

        $supplier = $request->filled('supplier_id')
            ? Supplier::forCompany($companyId)->find((int) $request->input('supplier_id'))
            : null;

        // Every medicine is proven to be this company's BEFORE anything is
        // written, so a tampered form cannot half-receive a delivery.
        $medicineIds = collect($request->input('items'))->pluck('medicine_id')->map(fn ($v) => (int) $v)->unique();
        $medicines = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $medicineIds)
            ->get()
            ->keyBy('id');

        if ($medicines->count() !== $medicineIds->count()) {
            return back()->withInput()->with('error', __('health.ph_medicine_missing'));
        }

        try {
            $order = DB::transaction(function () use ($request, $companyId, $supplier, $branchId, $medicines) {
                $items = $request->input('items');
                $total = 0.0;
                foreach ($items as $row) {
                    $total = round($total + (float) $row['quantity'] * (float) $row['cost_price'], 2);
                }

                $order = PurchaseOrder::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'supplier_id' => $supplier?->id,
                    'po_number' => HealthPharmacyService::nextPurchaseNumber(),
                    'status' => PurchaseOrder::STATUS_RECEIVED,
                    'order_date' => $request->input('order_date') ?: now()->toDateString(),
                    'received_date' => now()->toDateString(),
                    'total_amount' => $total,
                    'notes' => trim(implode(' · ', array_filter([
                        $request->input('invoice_reference'),
                        $request->input('notes'),
                    ]))) ?: null,
                    'created_by' => $this->user()?->id,
                ]);

                foreach ($items as $row) {
                    $medicine = $medicines[(int) $row['medicine_id']];
                    $quantity = round((float) $row['quantity'], 3);
                    $cost = round((float) $row['cost_price'], 2);

                    $orderItem = PurchaseOrderItem::create([
                        'purchase_order_id' => $order->id,
                        'product_id' => $medicine->product_id,
                        'quantity' => $quantity,
                        'unit_price' => $cost,
                        'total_price' => round($quantity * $cost, 2),
                        'received_quantity' => $quantity,
                    ]);

                    HealthPharmacyStockService::receive(
                        $companyId,
                        $medicine,
                        [
                            'quantity' => $quantity,
                            'cost_price' => $cost,
                            'sale_price' => $row['sale_price'] ?? $medicine->sale_price,
                            'batch_no' => $row['batch_no'] ?? null,
                            'expiry_date' => $row['expiry_date'] ?? null,
                            'manufacture_date' => $row['manufacture_date'] ?? null,
                            'supplier_id' => $supplier?->id,
                            'purchase_order_id' => $order->id,
                            'purchase_order_item_id' => $orderItem->id,
                        ],
                        $branchId,
                        ['type' => 'purchase_order', 'id' => $order->id, 'number' => $order->po_number],
                        $this->user()?->id
                    );

                    // Keep the catalogue's last-known rates honest so the next
                    // purchase form and the counter both open on real numbers.
                    $medicine->purchase_price = $cost;
                    if (!empty($row['sale_price'])) {
                        $medicine->sale_price = round((float) $row['sale_price'], 2);
                    }
                    $medicine->save();
                }

                $paid = round((float) $request->input('paid_amount', 0), 2);
                if ($paid > 0 && $supplier) {
                    HealthSupplierPayment::withoutGlobalScopes()->create([
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'supplier_id' => $supplier->id,
                        'purchase_order_id' => $order->id,
                        'amount' => $paid,
                        'method' => in_array($request->input('payment_method'), HealthSupplierPayment::METHODS, true)
                            ? $request->input('payment_method')
                            : 'cash',
                        'paid_on' => now()->toDateString(),
                        'created_by' => $this->user()?->id,
                    ]);
                }

                return $order;
            });
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('health.pharmacy.purchases')
            ->with('success', __('health.ph_purchase_saved', [
                'number' => $order->po_number,
                'amount' => number_format((float) $order->total_amount, 2),
            ]));
    }

    /** Record money paid to a supplier, with or without a purchase attached. */
    public function storePayment(Request $request)
    {
        $this->assertCan('pharmacy.manage');

        $companyId = $this->companyId();

        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'purchase_order_id' => 'nullable|integer',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|max:24',
            'paid_on' => 'nullable|date',
            'reference' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:500',
        ]);

        $supplier = Supplier::forCompany($companyId)->findOrFail($data['supplier_id']);

        if (!empty($data['purchase_order_id'])) {
            $exists = PurchaseOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($data['purchase_order_id'])
                ->exists();

            if (!$exists) {
                return back()->with('error', __('health.ph_purchase_missing'));
            }
        }

        HealthSupplierPayment::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'branch_id' => $this->viewBranchId(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'amount' => round((float) $data['amount'], 2),
            'method' => in_array($data['method'] ?? null, HealthSupplierPayment::METHODS, true) ? $data['method'] : 'cash',
            'paid_on' => $data['paid_on'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->user()?->id,
        ]);

        return back()->with('success', __('health.ph_payment_saved', ['name' => $supplier->name]));
    }

    /** Add a medicine supplier. Shared table, so the row stays company-scoped. */
    public function storeSupplier(Request $request)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:190',
            'ntn' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:64',
            'contact_person' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
        ]);

        Supplier::create($data + ['company_id' => $this->companyId(), 'is_active' => true]);

        return back()->with('success', __('health.ph_supplier_saved', ['name' => $data['name']]));
    }
}
