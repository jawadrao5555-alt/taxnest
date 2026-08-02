@php
    $companyName = $invoice->company->name ?? 'TaxNest';
    $number = $invoice->display_invoice_number;
    $invoiceDate = $invoice->invoice_date ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') : ($invoice->created_at ? $invoice->created_at->format('d M Y') : '');
    $total = number_format((float) ($invoice->total_amount ?? 0), 2);
@endphp
Dear {{ $invoice->buyer_name ?: 'Customer' }},

Please find attached invoice {{ $number }} from {{ $companyName }}.

Invoice #: {{ $number }}
@if($invoiceDate)Invoice Date: {{ $invoiceDate }}
@endif
Total Amount: PKR {{ $total }}
@if($invoice->fbr_invoice_number)FBR Invoice No: {{ $invoice->fbr_invoice_number }} (registered with FBR, Pakistan)
@endif

View invoice online:
{{ $shareUrl }}

The invoice PDF is attached to this email.

Sent by {{ $companyName }}
Powered by TaxNest - FBR compliant digital invoicing for Pakistan
