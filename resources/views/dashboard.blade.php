<h2>Company Dashboard</h2>

<a href="/invoice/create">Create Invoice</a>

<hr>

@foreach($invoices as $invoice)
<p>
Invoice #{{ $invoice->id }} - {{ $invoice->status }}

@if($invoice->status == 'draft')
<form method="POST" action="/invoice/{{ $invoice->id }}/submit">
    @csrf
    <button type="submit">Submit to FBR</button>
</form>
@endif

<a href="/invoice/{{ $invoice->id }}/pdf">Download PDF</a>
</p>
@endforeach
