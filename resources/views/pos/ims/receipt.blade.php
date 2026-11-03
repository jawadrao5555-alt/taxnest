<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PRA + FBR POS receipt</title><style>*{box-sizing:border-box}body{margin:0;background:#eee;color:#000;font:12px/1.3 Arial,sans-serif}.tools{text-align:center;padding:15px}.receipt{width:{{ $width }}mm;padding:2mm;margin:12px auto;background:white;overflow-wrap:anywhere}h1{text-align:center;font-size:16px;margin:4px 0}p{margin:5px 0}.center{text-align:center}.notice{border:1px solid;padding:4px;text-align:center;font-weight:bold}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px dashed #888;padding:3px 1px;text-align:left}.right{text-align:right}.qty{text-align:center}.total{font-size:16px;font-weight:bold}.fiscal{table-layout:fixed;margin-top:5px}.fiscal td{width:50%;vertical-align:top;text-align:center;font-size:9px}.fiscal img{display:block;width:24mm;height:24mm;margin:3px auto}.small{font-size:9px}@media print{body{background:white}.tools{display:none}.receipt{margin:0;width:100%}@page{size:{{ $width }}mm auto;margin:0}}</style></head><body>
@php($s=$record->snapshot)
@php($accepted=in_array($record->state,['accepted','local_accepted']) && $record->fiscal_number)
<div class="tools"><a href="{{ route('pos.tax-integrations.ims.show',$record->id) }}">Back to IMS record</a> <button type="button" onclick="window.print()">Print preview</button></div>
<article class="receipt"><p class="notice">@if($record->environment==='sandbox')
SANDBOX TEST COPY · NOT A VALID TAX INVOICE
@elseif(!$accepted)
PRODUCTION PREVIEW · NOT ACKNOWLEDGED BY FBR IMS
@else
PRA + FBR POS
@endif</p>
<h1>{{ $s['company_name'] }}</h1><p class="center small">{{ $s['seller_address'] }}<br>NTN: {{ $s['seller_ntn'] }}</p><p>Bill: {{ $s['invoice_number'] }}<br>{{ $s['date_time'] }} · {{ $s['payment_method'] }} · {{ strtoupper($s['transaction_type']) }}</p>
<table><thead><tr><th>Item</th><th class="qty">Qty</th><th class="right">Amount</th></tr></thead><tbody>@foreach($s['lines'] as $l)<tr><td>{{ $l['name'] }}</td><td class="qty">{{ $l['quantity'] }}</td><td class="right">{{ number_format($l['base'],2) }}</td></tr>@endforeach</tbody></table>
<table><tr><td>Net amount</td><td class="right">{{ number_format(array_sum(array_column($s['lines'],'base')),2) }}</td></tr><tr><td>PRA Sales Tax</td><td class="right">{{ number_format($s['pra_tax'],2) }}</td></tr><tr><td>Rounding / adjustments</td><td class="right">{{ number_format($s['total']-array_sum(array_column($s['lines'],'base'))-$s['pra_tax'],2) }}</td></tr><tr class="total"><td>TOTAL</td><td class="right">{{ number_format($s['total'],2) }}</td></tr></table>
<table class="fiscal"><tr><td><strong>PRA</strong><br>{{ $s['pra_status'] ?: 'Not reported' }}<br>@if($s['pra_status']==='submitted' && $s['pra_number']){{ $s['pra_number'] }}<img src="{{ \App\Support\QrImage::dataUri($s['pra_number'],5,4) }}" alt="PRA source QR">@endif</td><td><strong>FBR POS · {{ strtoupper($record->environment) }}</strong><br>{{ str_replace('_',' ',$record->state) }}<br>@if($accepted){{ $record->fiscal_number }}<img src="{{ \App\Support\QrImage::dataUri($record->fiscal_number,5,4) }}" alt="FBR IMS acknowledgement QR">@else
No acknowledged IMS reference. No FBR QR issued.
@endif</td></tr></table>
@if($record->payload)<p class="small">FBR reported tax: {{ number_format($record->payload['TotalTaxCharged'],2) }} · FBR reported total: {{ number_format($record->payload['TotalBillAmount'],2) }}</p>@endif
@if($record->state==='local_accepted')<p class="small center">Local FBR IMS acknowledgement. Central synchronization not independently verified.</p>@endif
<p class="small center">{{ $record->environment==='sandbox' || !$accepted ? 'TEST / PREVIEW · NOT FOR CUSTOMER USE' : 'FBR POS ACKNOWLEDGEMENT' }}<br>TaxNest · PRA POS + FBR POS</p></article></body></html>
