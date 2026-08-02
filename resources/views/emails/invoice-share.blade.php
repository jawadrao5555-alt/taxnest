{{-- Buyer-facing invoice email — ENGLISH ONLY (owner rule: buyer-facing output stays English). --}}
@php
    $companyName = $invoice->company->name ?? 'TaxNest';
    $number = $invoice->display_invoice_number;
    $invoiceDate = $invoice->invoice_date ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') : ($invoice->created_at ? $invoice->created_at->format('d M Y') : '');
    $total = number_format((float) ($invoice->total_amount ?? 0), 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice {{ $number }} from {{ $companyName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%;">
    <!-- Preheader (hidden preview text) -->
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">Invoice {{ $number }} — PKR {{ $total }} from {{ $companyName }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                    <!-- Header: seller company -->
                    <tr>
                        <td style="background-color:#059669; padding:28px 32px; text-align:center;">
                            <span style="font-size:24px; font-weight:bold; color:#ffffff; letter-spacing:0.5px;">{{ $companyName }}</span>
                            <div style="font-size:12px; color:#d1fae5; margin-top:4px; letter-spacing:1px; text-transform:uppercase;">{{ $invoice->fbr_invoice_number ? 'FBR Digital Invoice' : 'Invoice' }}</div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px; font-size:14px; color:#374151;">Dear {{ $invoice->buyer_name ?: 'Customer' }},</p>
                            <p style="margin:0 0 20px; font-size:14px; line-height:1.7; color:#374151;">Please find attached invoice <strong>{{ $number }}</strong> from <strong>{{ $companyName }}</strong>. You can also view it online using the button below.</p>

                            <!-- Invoice summary -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; margin:0 0 22px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px; color:#374151;">
                                            <tr>
                                                <td style="padding:4px 0; color:#6b7280;">Invoice #</td>
                                                <td style="padding:4px 0; text-align:right; font-weight:bold; color:#111827;">{{ $number }}</td>
                                            </tr>
                                            @if($invoiceDate)
                                            <tr>
                                                <td style="padding:4px 0; color:#6b7280;">Invoice Date</td>
                                                <td style="padding:4px 0; text-align:right; color:#111827;">{{ $invoiceDate }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding:8px 0 4px; color:#6b7280; border-top:1px solid #e5e7eb;">Total Amount</td>
                                                <td style="padding:8px 0 4px; text-align:right; font-weight:bold; font-size:15px; color:#059669; border-top:1px solid #e5e7eb;">PKR {{ $total }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            @if($invoice->fbr_invoice_number)
                            <p style="margin:0 0 20px; font-size:12px; line-height:1.6; color:#6b7280;">This invoice is registered with FBR (Federal Board of Revenue, Pakistan). FBR Invoice No: <strong style="color:#374151;">{{ $invoice->fbr_invoice_number }}</strong></p>
                            @endif

                            <!-- CTA button -->
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px auto 8px;">
                                <tr>
                                    <td style="border-radius:8px; background-color:#059669;">
                                        <a href="{{ $shareUrl }}" style="display:inline-block; padding:13px 34px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">View Invoice Online</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:6px 0 0; font-size:12px; color:#9ca3af; text-align:center;">
                                If the button does not work, open this link:<br>
                                <a href="{{ $shareUrl }}" style="color:#059669; word-break:break-all;">{{ $shareUrl }}</a>
                            </p>
                            <p style="margin:18px 0 0; font-size:12px; color:#9ca3af; text-align:center;">The invoice PDF is attached to this email.</p>
                        </td>
                    </tr>
                    <!-- Divider -->
                    <tr><td style="padding:0 32px;"><div style="border-top:1px solid #e5e7eb;"></div></td></tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:20px 32px 26px; text-align:center;">
                            <p style="margin:0 0 4px; font-size:12px; color:#6b7280;">Sent by <strong style="color:#374151;">{{ $companyName }}</strong></p>
                            <p style="margin:0; font-size:11px; color:#9ca3af;">Powered by TaxNest &middot; FBR compliant digital invoicing for Pakistan</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
