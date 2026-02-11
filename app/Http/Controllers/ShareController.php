<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

class ShareController extends Controller
{
    public function show(string $uuid)
    {
        $invoice = Invoice::where('share_uuid', $uuid)
            ->with('items', 'company')
            ->firstOrFail();

        return view('share.invoice', compact('invoice'));
    }
}
