{{--
    DI invoice delivery email (Task 140 white-label branding + Task 136 buyer send, reconciled in Task 187).

    Single buyer-facing invoice email view. Branding (logo, accent color,
    footer lines, platform credit) comes from DiBrandingService and only
    activates for Premium companies with branding switched on; everyone else
    gets the default TaxNest look (green header, company name — identical to
    the original buyer-send email).

    Expects:
      - $invoice   App\Models\Invoice with ->company eager-loaded.
      - $shareUrl  (optional) public share link; defaults to the invoice's
                   share_uuid URL when present.
      - $diBrand   (optional) pre-resolved branding array override.

    COMPLIANCE: the FBR invoice number and the tax breakdown rows are always
    rendered — branding never hides them. Logo uses an absolute public URL
    (email clients fetch it; no inline attachment needed).

    Owner rule: ALL buyer-facing content stays ENGLISH.
--}}
@php
    $diBrand = $diBrand ?? \App\Services\DiBrandingService::forCompany($invoice->company ?? null);
    $companyName = $invoice->company->name ?? 'TaxNest';
    // Default (non-branded) look = the original green buyer-send email.
    $headerBg = $diBrand['accent'] ?? '#059669';
    $headerText = $diBrand['accent'] ? $diBrand['accent_text'] : '#ffffff';
    $displayNo = $invoice->display_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? ('INV-' . $invoice->id);
    $shareUrl = $shareUrl ?? (!empty($invoice->share_uuid) ? url('/share/invoice/' . $invoice->share_uuid) : null);
    $invoiceDate = $invoice->invoice_date ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') : $invoice->created_at?->format('d M Y');
    $total = number_format((float) ($invoice->total_amount ?? 0), 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $displayNo }} from {{ $companyName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%;">
<!-- Preheader (hidden preview text) -->
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">Invoice {{ $displayNo }} &mdash; PKR {{ $total }} from {{ $companyName }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:94%; background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #e2e8f0;">

                {{-- Header band (accent-colored when branding active; default green) --}}
                <tr>
                    <td style="background-color: {{ $headerBg }}; padding:24px 28px; text-align:center;">
                        @if($diBrand['active'] && $diBrand['logo_url'])
                        <img src="{{ $diBrand['logo_url'] }}" alt="{{ $companyName }}" style="max-height:48px; width:auto; background:#ffffff; padding:4px 8px; border-radius:6px;"><br>
                        <span style="display:inline-block; margin-top:8px; color: {{ $headerText }}; font-size:16px; font-weight:bold;">{{ $companyName }}</span>
                        @else
                        <span style="color: {{ $headerText }}; font-size:22px; font-weight:bold; letter-spacing:0.5px;">{{ $companyName }}</span>
                        @endif
                        <div style="font-size:12px; color: {{ $headerText }}; opacity:0.85; margin-top:4px; letter-spacing:1px; text-transform:uppercase;">{{ $invoice->fbr_invoice_number ? 'FBR Digital Invoice' : 'Invoice' }}</div>
                    </td>
                </tr>

                {{-- Intro --}}
                <tr>
                    <td style="padding:26px 28px 8px 28px;">
                        <p style="margin:0 0 6px 0; font-size:15px; color:#0f172a; font-weight:bold;">Invoice {{ $displayNo }}</p>
                        <p style="margin:0; font-size:13px; color:#475569; line-height:1.6;">
                            Dear {{ $invoice->buyer_name ?: 'Customer' }},<br>
                            Please find attached invoice <strong>{{ $displayNo }}</strong> from <strong>{{ $companyName }}</strong>. A summary is below{{ $shareUrl ? ', and you can also view it online' : '' }}.
                        </p>
                    </td>
                </tr>

                {{-- Summary box (COMPLIANCE: FBR number + tax breakdown always shown) --}}
                <tr>
                    <td style="padding:16px 28px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:8px;">
                            <tr>
                                <td style="padding:9px 14px; font-size:12px; color:#64748b; border-bottom:1px solid #f1f5f9;">Invoice No.</td>
                                <td style="padding:9px 14px; font-size:12px; color:#0f172a; font-weight:bold; text-align:right; border-bottom:1px solid #f1f5f9;">{{ $displayNo }}</td>
                            </tr>
                            @if($invoice->fbr_invoice_number)
                            <tr>
                                <td style="padding:9px 14px; font-size:12px; color:#64748b; border-bottom:1px solid #f1f5f9;">FBR Invoice #</td>
                                <td style="padding:9px 14px; font-size:12px; color:#166534; font-weight:bold; text-align:right; border-bottom:1px solid #f1f5f9;">{{ $invoice->fbr_invoice_number }}</td>
                            </tr>
                            @endif
                            @if($invoiceDate)
                            <tr>
                                <td style="padding:9px 14px; font-size:12px; color:#64748b; border-bottom:1px solid #f1f5f9;">Date</td>
                                <td style="padding:9px 14px; font-size:12px; color:#0f172a; font-weight:bold; text-align:right; border-bottom:1px solid #f1f5f9;">{{ $invoiceDate }}</td>
                            </tr>
                            @endif
                            <tr>
                                <td style="padding:9px 14px; font-size:12px; color:#64748b; border-bottom:1px solid #f1f5f9;">Sub Total</td>
                                <td style="padding:9px 14px; font-size:12px; color:#0f172a; font-weight:bold; text-align:right; border-bottom:1px solid #f1f5f9;">PKR {{ number_format((float) ($invoice->total_value_excluding_st ?? 0), 2) }}</td>
                            </tr>
                            <tr>
                                <td style="padding:9px 14px; font-size:12px; color:#64748b; border-bottom:1px solid #f1f5f9;">Sales Tax (GST)</td>
                                <td style="padding:9px 14px; font-size:12px; color:#0f172a; font-weight:bold; text-align:right; border-bottom:1px solid #f1f5f9;">PKR {{ number_format((float) ($invoice->total_sales_tax ?? 0), 2) }}</td>
                            </tr>
                            <tr>
                                <td style="padding:11px 14px; font-size:13px; font-weight:bold; background-color: {{ $headerBg }}; color: {{ $headerText }}; border-radius:0 0 0 8px;">Total</td>
                                <td style="padding:11px 14px; font-size:14px; font-weight:bold; text-align:right; background-color: {{ $headerBg }}; color: {{ $headerText }}; border-radius:0 0 8px 0;">PKR {{ $total }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- CTA --}}
                @if($shareUrl)
                <tr>
                    <td style="padding:6px 28px 4px 28px;" align="center">
                        <a href="{{ $shareUrl }}" style="display:inline-block; background-color: {{ $headerBg }}; color: {{ $headerText }}; font-size:13px; font-weight:bold; padding:11px 26px; border-radius:8px; text-decoration:none;">View Invoice Online</a>
                        <p style="margin:10px 0 0 0; font-size:11px; color:#94a3b8; word-break:break-all;">
                            If the button does not work, open this link:<br>
                            <a href="{{ $shareUrl }}" style="color:#64748b; word-break:break-all;">{{ $shareUrl }}</a>
                        </p>
                    </td>
                </tr>
                @endif
                <tr>
                    <td style="padding:8px 28px 20px 28px;" align="center">
                        <p style="margin:0; font-size:12px; color:#94a3b8;">The invoice PDF is attached to this email.</p>
                    </td>
                </tr>

                {{-- Footer (custom lines + optional platform credit) --}}
                <tr>
                    <td style="padding:14px 28px 20px 28px; border-top:1px solid #f1f5f9; text-align:center;">
                        @foreach($diBrand['footer_lines'] as $brandLine)
                        <p style="margin:0 0 3px 0; font-size:11px; color:#475569; font-weight:bold;">{{ $brandLine }}</p>
                        @endforeach
                        <p style="margin:0 0 3px 0; font-size:11px; color:#64748b;">Sent by <strong style="color:#374151;">{{ $companyName }}</strong></p>
                        <p style="margin:0; font-size:11px; color:#94a3b8;">This is a computer-generated invoice notification.</p>
                        @unless($diBrand['hide_platform'])
                        <p style="margin:4px 0 0 0; font-size:11px; color:#64748b; font-weight:bold;">Powered by TaxNest &mdash; Tax &amp; Invoice Management System</p>
                        @endunless
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
