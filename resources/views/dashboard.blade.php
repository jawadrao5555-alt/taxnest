<h2>Company Dashboard</h2>

<a href="/invoice/create">Create Invoice</a>

<hr>

@foreach($invoices as $invoice)
<p>
Invoice #{{ $invoice->id }} - {{ $invoice->status }}
<a href="/invoice/{{ $invoice->id }}/pdf">Download PDF</a>
</p>
@endforeach
