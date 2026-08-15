<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $transaction->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: {{ $urduScript ? "'Noto Naskh Arabic', Tahoma, Arial, sans-serif" : "Arial, 'Segoe UI', sans-serif" }};
            background: #f3f4f6; color: #111827; padding: 16px;
            line-height: {{ $urduScript ? '1.7' : '1.45' }};
        }
        .card { max-width: 420px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 20px; }
        .biz { text-align: center; font-size: 18px; font-weight: 700; margin-bottom: 2px; }
        .badge { text-align: center; font-size: 11px; font-weight: 700; letter-spacing: 1px; color: #6b7280; text-transform: uppercase; margin-bottom: 10px; }
        .serial { text-align: center; font-size: 15px; font-weight: 700; }
        .token { text-align: center; font-size: 30px; font-weight: 900; line-height: 1.1; }
        .meta { text-align: center; font-size: 12px; color: #6b7280; margin: 6px 0 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 4px 2px; }
        td { padding: 5px 2px; border-bottom: 1px dashed #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { border-bottom: none; padding: 3px 2px; }
        .totals .grand td { font-size: 16px; font-weight: 900; border-top: 2px solid #111827; padding-top: 6px; }
        .menu-link { display: block; text-align: center; margin-top: 14px; font-size: 13px; color: #0a4d5c; font-weight: 700; text-decoration: none; }
        .foot { text-align: center; font-size: 11px; color: #9ca3af; margin-top: 14px; }
        .return-banner { text-align: center; font-size: 12px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: #fff; background: #dc2626; border-radius: 6px; padding: 4px 10px; margin-bottom: 10px; }
        .returns-note  { text-align: center; font-size: 12px; font-weight: 600; color: #b45309; background: #fef3c7; border-radius: 6px; padding: 4px 10px; margin-bottom: 10px; }
    </style>
</head>
<body>
@php
    $isProvisional = ($transaction->invoice_mode ?? 'pra') === 'local';
    // Big token only when this bill's stream style is token (mirrors receipts).
    $pageBillToken = null;
    try {
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token') && $transaction->bill_token) {
            $isLocalStream = $transaction->isLocalBill() || $transaction->isExemptStream();
            $numStyle = $isLocalStream ? ($company->local_number_style ?? 'serial') : ($company->pra_number_style ?? 'serial');
            if ($numStyle === 'token') { $pageBillToken = (int) $transaction->bill_token; }
        }
    } catch (\Throwable $e) { $pageBillToken = null; }
@endphp
<div class="card">
    @if($showBusinessName)
    <div class="biz">{{ $company->name }}</div>
    @endif
    <div class="badge">{{ $isProvisional ? __('pos.receipt_provisional_bill') : __('pos.receipt_sale_receipt') }}</div>
    @if($isReturnBill)
    <div class="return-banner">{{ __('pos.return_bill_banner') }}</div>
    @elseif($hasReturns)
    <div class="returns-note">{{ __('pos.bill_has_returns') }}</div>
    @endif
    @if($pageBillToken !== null)
    <div class="token">{{ $pageBillToken }}</div>
    <div class="meta">{{ __('pos.bill_ref_label') }}: {{ $transaction->invoice_number }}</div>
    @else
    <div class="serial">{{ $transaction->invoice_number }}</div>
    @endif
    <div class="meta">{{ __('pos.receipt_date') }}: {{ $transaction->created_at->format('d/m/Y h:i A') }}</div>

    <table>
        <tr>
            <th>{{ __('pos.receipt_item') }}</th>
            <th class="num">{{ __('pos.receipt_qty') }}</th>
            <th class="num">{{ __('pos.receipt_total') }}</th>
        </tr>
        @foreach($transaction->items as $item)
        <tr>
            <td>{{ $item->item_name }}</td>
            <td class="num">{{ $item->quantity == intval($item->quantity) ? intval($item->quantity) : number_format($item->quantity, 2) }}</td>
            <td class="num">{{ number_format((float) $item->subtotal + (float) ($item->tax_amount ?? 0), 0) }}</td>
        </tr>
        @endforeach
    </table>

    <table class="totals" style="margin-top:8px;">
        @if((float) $transaction->discount_amount > 0)
        <tr>
            <td>{{ __('pos.receipt_discount') }}</td>
            <td class="num">-{{ number_format((float) $transaction->discount_amount, 0) }}</td>
        </tr>
        @endif
        <tr class="grand">
            <td>{{ __('pos.receipt_total') }}</td>
            <td class="num">Rs {{ number_format((float) $transaction->total_amount, 0) }}</td>
        </tr>
    </table>

    @php
        // Payment breakdown — only shown when there are ≥2 rows (single-method
        // bills don't need a breakdown) and the relation was loaded (schema-guarded
        // in the controller). No PII: payment method label + amount only.
        $billPayments = [];
        try {
            if ($transaction->relationLoaded('payments')) {
                $rawPayments = $transaction->payments ?? collect();
                // Show breakdown whenever there are ≥2 raw payment rows, even
                // if all aliases collapse into a single display bucket (e.g.
                // card + debit_card → one Card line). The customer paid in
                // multiple transactions, so the section is still meaningful.
                if ($rawPayments->count() >= 2) {
                    // Bucket aliases so the customer sees a friendly label.
                    $cardAliases = ['card', 'debit_card', 'credit_card'];
                    $methodLabel = function(string $m) use ($cardAliases): string {
                        if ($m === 'cash')                      return __('pos.receipt_pay_cash');
                        if (in_array($m, $cardAliases, true))  return __('pos.receipt_pay_card');
                        return __('pos.receipt_pay_other');
                    };
                    // Group by bucket so debit_card + card collapse into one row.
                    $grouped = [];
                    foreach ($rawPayments as $p) {
                        $bucket = $p->payment_method === 'cash' ? 'cash'
                            : (in_array($p->payment_method, $cardAliases, true) ? 'card' : ('other:'.$p->payment_method));
                        $grouped[$bucket] = ($grouped[$bucket] ?? 0) + (float) $p->amount;
                    }
                    foreach ($grouped as $bucket => $amount) {
                        $rawMethod = $bucket === 'cash' ? 'cash'
                            : (str_starts_with($bucket, 'other:') ? substr($bucket, 6) : 'debit_card');
                        $billPayments[] = ['label' => $methodLabel($rawMethod), 'amount' => $amount];
                    }
                }
            }
        } catch (\Throwable $e) {
            $billPayments = [];
        }
    @endphp
    @if(count($billPayments) >= 1)
    <table class="totals" style="margin-top:6px; border-top:1px dashed #e5e7eb; padding-top:4px;">
        <tr>
            <td colspan="2" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;padding-bottom:2px;">
                {{ __('pos.payment_breakdown') }}
            </td>
        </tr>
        @foreach($billPayments as $pay)
        <tr>
            <td style="font-size:13px;">{{ $pay['label'] }}</td>
            <td class="num" style="font-size:13px;">Rs {{ number_format($pay['amount'], 0) }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    @if($menuUrl)
    <a class="menu-link" href="{{ $menuUrl }}">{{ __('pos.receipt_scan_menu') }} &rarr;</a>
    @endif
    <div class="foot">{{ __('pos.brand_developed_by') }}</div>
</div>
</body>
</html>
