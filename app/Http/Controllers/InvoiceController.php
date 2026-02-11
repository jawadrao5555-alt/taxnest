<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
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
