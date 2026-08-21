@php
    $companyName = $company->name ?? 'TaxNest';
    $batch = $report['batch'] ?? [];
    $counts = $report['counts'] ?? [];
    $open = (int) ($counts['needs_review'] ?? 0) + (int) ($counts['failed'] ?? 0);
@endphp
Assalam-o-Alaikum,

{{ $senderName !== '' ? $senderName . ' at ' . $companyName : $companyName }} has shared an invoice review summary with you.

Batch #{{ $batch['id'] ?? '' }} - {{ $batch['status_label'] ?? '' }}
Source invoices processed: {{ $batch['processed'] ?? 0 }} of {{ $batch['total'] ?? 0 }}
Ready: {{ $counts['ready'] ?? 0 }}
Needs review: {{ $counts['needs_review'] ?? 0 }}
Duplicate: {{ $counts['duplicate'] ?? 0 }}
Failed: {{ $counts['failed'] ?? 0 }}
Still processing: {{ $counts['pending'] ?? 0 }}
@if($open > 0)

{{ $open }} row(s) still need attention. The attached PDF lists each source file with the notes to resolve.
@endif

The full summary is attached as a PDF.

Please note: the private source invoice photos are NOT attached to this email and are not linked from the summary. Only the review data (file name, status, notes, and the draft invoice number) is shared. Ask {{ $companyName }} to open the batch in TaxNest to see a photo.

Nothing in this batch is submitted to FBR automatically.

Sent by {{ $companyName }} via TaxNest
Powered by TaxNest - FBR compliant digital invoicing for Pakistan
