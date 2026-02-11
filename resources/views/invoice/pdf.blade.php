<h2>Invoice #{{ $invoice->invoice_number ?? $invoice->id }}</h2>

<p>Buyer: {{ $invoice->buyer_name }}</p>
<p>NTN: {{ $invoice->buyer_ntn }}</p>
<p>Total: {{ $invoice->total_amount }}</p>

<hr>

@foreach($invoice->items as $item)
<p>
HS: {{ $item->hs_code }} <br>
Desc: {{ $item->description }} <br>
Qty: {{ $item->quantity }} <br>
Price: {{ $item->price }} <br>
Tax: {{ $item->tax }}
</p>
<hr>
@endforeach
