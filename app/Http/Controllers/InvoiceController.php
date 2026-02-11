<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends Controller
{
    public function create()
    {
        return view('invoice.create');
    }

    public function store(Request $request)
    {
        $invoice = Invoice::create([
            'company_id' => app('currentCompanyId'),
            'buyer_name' => $request->buyer_name,
            'buyer_ntn' => $request->buyer_ntn,
            'total_amount' => $request->total_amount
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'hs_code' => $request->hs_code,
            'description' => $request->description,
            'quantity' => $request->quantity,
            'price' => $request->price,
            'tax' => $request->tax
        ]);

        return redirect('/dashboard');
    }

    public function pdf(Invoice $invoice)
    {
        $pdf = Pdf::loadView('invoice.pdf', compact('invoice'));
        return $pdf->download('invoice_'.$invoice->id.'.pdf');
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return response()->json([
                'error' => 'Production invoice cannot be edited.'
            ], 403);
        }

        $invoice->update($request->all());

        return response()->json(['message' => 'Invoice updated']);
    }

    public function submit(Invoice $invoice)
    {
        if ($invoice->isLocked()) {
            return response()->json([
                'error' => 'Invoice already locked.'
            ], 403);
        }

        // Simulate FBR success
        $invoice->status = 'locked';
        $invoice->invoice_number = 'FBR' . time();
        $invoice->save();

        return response()->json([
            'message' => 'Invoice submitted to FBR and locked.',
            'invoice_number' => $invoice->invoice_number
        ]);
    }
}
