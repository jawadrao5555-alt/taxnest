{{-- One compact row across all nine designs. Numbers belong beneath their QR. --}}
<table class="dual-fiscal" style="width:100%;table-layout:fixed;border-collapse:collapse;break-inside:avoid;margin:3px 0;color:#000">
    <tr>
        <td style="width:50%;vertical-align:top;text-align:center;padding:2px;font-size:9px;overflow-wrap:anywhere">
            <div>PRA</div>
            @if($transaction->pra_status==='submitted' && $transaction->pra_invoice_number)
                <img src="{{ \App\Support\QrImage::dataUri($transaction->pra_invoice_number,5,4) }}" alt="PRA fiscal QR" style="display:block;width:{{ $fiscalQrSize }}px;height:{{ $fiscalQrSize }}px;margin:2px auto">
                <div class="pra-fiscal-reference" style="font-weight:bold;word-break:break-all">{{ $transaction->pra_invoice_number }}</div>
            @else
                <div>{{ $transaction->pra_status ?: 'Not reported' }}</div>
            @endif
        </td>
        <td style="width:50%;vertical-align:top;text-align:center;padding:2px;font-size:9px;overflow-wrap:anywhere">
            <div>{{ $federalFiscal['label'] }} · {{ $federalFiscal['environment'] }}</div>
            @if($federalFiscal['reference'])
                <img src="{{ \App\Support\QrImage::dataUri($federalFiscal['reference'],5,4) }}" alt="FBR fiscal QR" style="display:block;width:{{ $fiscalQrSize }}px;height:{{ $fiscalQrSize }}px;margin:2px auto">
                <div class="fbr-fiscal-reference" style="font-weight:bold;word-break:break-all">{{ $federalFiscal['reference'] }}</div>
                @if(str_contains($federalFiscal['state'],'central unverified'))<div>{{ $federalFiscal['state'] }}</div>@endif
            @else
                <div>{{ $federalFiscal['state'] }}</div>
            @endif
        </td>
    </tr>
</table>
