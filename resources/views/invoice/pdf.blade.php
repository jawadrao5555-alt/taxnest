<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #333; font-size: 13px; }
        .doc-title { text-align: center; font-size: 22px; font-weight: bold; color: #10b981; letter-spacing: 3px; border-bottom: 2px solid #10b981; padding-bottom: 12px; margin-bottom: 20px; }
        .header { border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 24px; }
        .header table { width: 100%; }
        .company-name { font-size: 18px; font-weight: bold; color: #1f2937; }
        .info-grid { width: 100%; margin-bottom: 24px; }
        .info-grid td { width: 50%; vertical-align: top; }
        .info-box { background: #f9fafb; padding: 14px; border-radius: 8px; border: 1px solid #e5e7eb; }
        .info-box h4 { font-size: 10px; text-transform: uppercase; color: #6b7280; margin: 0 0 8px 0; letter-spacing: 1px; font-weight: 700; }
        .info-box p { margin: 3px 0; font-size: 12px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.items th { background: #f3f4f6; padding: 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; font-weight: 700; border-bottom: 2px solid #e5e7eb; }
        table.items td { padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 12px; }
        table.items tr:nth-child(even) td { background: #fafafa; }
        .text-right { text-align: right; }
        .total-row td { background: #f0fdf4; font-size: 14px; font-weight: bold; color: #059669; border-top: 2px solid #059669; }
        .status { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-submitted { background: #dbeafe; color: #1e40af; }
        .status-locked { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="doc-title">{{ strtoupper($invoice->document_type ?? 'SALE INVOICE') }}</div>

    <div class="header">
        <table>
            <tr>
                <td style="width: 60%; vertical-align: top;">
                    <div class="company-name">{{ $invoice->company->name ?? 'TaxNest' }}</div>
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                    @if($invoice->company->cnic && $invoice->company->cnic !== $invoice->company->ntn && $invoice->company->cnic !== $invoice->company->registration_no)
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">CNIC: {{ $invoice->company->cnic }}</p>
                    @endif
                    @if($invoice->company->registration_no)
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">Reg #: {{ $invoice->company->registration_no }}</p>
                    @endif
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">{{ $invoice->company->address ?? '' }}@if($invoice->company->city), {{ $invoice->company->city }}@endif</p>
                    @if($invoice->company->phone)
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">Phone: {{ $invoice->company->phone }}</p>
                    @endif
                    @if($invoice->company->mobile && $invoice->company->mobile !== $invoice->company->phone)
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">Mobile: {{ $invoice->company->mobile }}</p>
                    @endif
                    @if($invoice->company->email)
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">Email: {{ $invoice->company->email }}</p>
                    @endif
                </td>
                <td style="width: 40%; vertical-align: top; text-align: right;">
                    @if($invoice->fbr_invoice_number)
                    @php
                        $qrData = json_encode([
                            'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $invoice->company->ntn ?? ''),
                            'fbr_invoice_number' => $invoice->fbr_invoice_number,
                            'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                            'totalValues' => $invoice->total_amount
                        ]);
                    @endphp
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data={{ urlencode($qrData) }}" alt="QR Code" style="width: 90px; height: 90px; display: inline-block;">
                    <div style="font-size: 9px; color: #059669; font-weight: bold; margin-top: 3px;">FBR Verified</div>
                    @else
                    <span class="status status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <table class="info-grid">
        <tr>
            <td style="padding-right: 8px;">
                <div class="info-box">
                    <h4>Bill To</h4>
                    <p><strong>{{ $invoice->buyer_name }}</strong></p>
                    @if($invoice->buyer_ntn)
                    <p>NTN: {{ $invoice->buyer_ntn }}</p>
                    @endif
                    @if($invoice->buyer_cnic)
                    <p>CNIC: {{ $invoice->buyer_cnic }}</p>
                    @endif
                    @if($invoice->buyer_address)
                    <p>Address: {{ $invoice->buyer_address }}</p>
                    @endif
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="info-box">
                    <h4>Invoice Details</h4>
                    <p>Internal #: <strong>{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</strong></p>
                    @if($invoice->fbr_invoice_number)
                    <p>FBR #: <strong style="color: #059669;">{{ $invoice->fbr_invoice_number }}</strong></p>
                    @endif
                    <p>Date: <strong>{{ $invoice->created_at->format('d M Y') }}</strong></p>
                    <p>Status: <strong>{{ ucfirst($invoice->status) }}</strong></p>
                    @if($invoice->submission_mode)
                    <p>Mode: <strong>{{ ucfirst($invoice->submission_mode) }}</strong></p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>HS Code</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Price</th>
                <th class="text-right">Tax</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td style="font-family: monospace; font-size: 11px;">{{ $item->hs_code }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">Rs. {{ number_format($item->price, 2) }}</td>
                <td class="text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                <td class="text-right" style="font-weight: 600;">Rs. {{ number_format(($item->price * $item->quantity) + $item->tax, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="6" class="text-right"><strong>Grand Total</strong></td>
                <td class="text-right">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if(!empty($showWatermark) && $showWatermark)
    <div style="position: fixed; top: 40%; left: 15%; font-size: 48px; color: rgba(239, 68, 68, 0.10); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); pointer-events: none; z-index: 9999; white-space: nowrap; letter-spacing: 6px;">
        Subscription Expired
    </div>
    @endif

    @if(!empty($isDraft) && $isDraft)
    <div style="position: fixed; top: 40%; left: 20%; font-size: 60px; color: rgba(156, 163, 175, 0.12); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); pointer-events: none; z-index: 9999; white-space: nowrap; letter-spacing: 8px;">
        DRAFT
    </div>
    @endif
</body>
</html>
