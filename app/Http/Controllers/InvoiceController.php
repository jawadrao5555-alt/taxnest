<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Subscription;
use App\Jobs\SendInvoiceToFbrJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');
        $invoices = Invoice::where('company_id', $companyId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('invoice.index', compact('invoices'));
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $this->checkInvoiceLimit($companyId);
        return view('invoice.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'required|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
        ]);

        $companyId = app('currentCompanyId');
        $this->checkInvoiceLimit($companyId);

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
                'buyer_name' => $request->buyer_name,
                'buyer_ntn' => $request->buyer_ntn,
                'total_amount' => $totalAmount,
                'status' => 'draft',
            ]);

            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'tax' => $item['tax'],
                ]);
            }

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
        $invoice->load('items', 'company');
        return view('invoice.show', compact('invoice'));
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
        return view('invoice.edit', compact('invoice'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Locked invoices cannot be edited.');
        }

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'required|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax' => 'required|numeric|min:0',
        ]);

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
            ]);

            $invoice->items()->delete();
            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'hs_code' => $item['hs_code'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'tax' => $item['tax'],
                ]);
            }

            DB::commit();
            return redirect('/invoice/' . $invoice->id)->with('success', 'Invoice updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update invoice.')->withInput();
        }
    }

    public function submit(Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return redirect('/invoices')->with('error', 'Invoice already locked.');
        }

        $invoice->status = 'submitted';
        $invoice->save();

        SendInvoiceToFbrJob::dispatch($invoice->id);

        return redirect('/invoices')->with('success', 'Invoice submitted to FBR for processing.');
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load('items', 'company');
        $html = view('invoice.pdf', compact('invoice'))->render();

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    private function checkInvoiceLimit($companyId)
    {
        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->first();

        if (!$subscription) {
            abort(403, 'No active subscription. Please subscribe to a plan first.');
        }

        $invoiceCount = Invoice::where('company_id', $companyId)->count();

        if ($invoiceCount >= $subscription->pricingPlan->invoice_limit) {
            abort(403, 'Invoice limit reached. Please upgrade your plan.');
        }
    }
}
