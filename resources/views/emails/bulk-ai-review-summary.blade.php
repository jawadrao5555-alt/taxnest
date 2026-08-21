{{--
    Task 1343: Bulk AI Image Import review summary emailed to another reviewer.

    Branding (logo, accent colour, footer lines, platform credit) comes from
    DiBrandingService and only activates for Premium companies with branding
    switched on; everyone else gets the default TaxNest green look.

    Expects:
      - $company     App\Models\Company (the sending shop)
      - $report      BulkAiImageImportService::reviewReport() array
      - $senderName  name of the TaxNest user who sent it (may be '')

    PRIVACY: only stored review data is shown. The private source photos are
    never attached or linked, and this email says so explicitly.

    Owner rule: this recipient is outside the shop's account, so ALL content
    here stays ENGLISH.
--}}
@php
    $diBrand = \App\Services\DiBrandingService::forCompany($company ?? null);
    $companyName = $company->name ?? 'TaxNest';
    $headerBg = $diBrand['accent'] ?? '#059669';
    $headerText = $diBrand['accent'] ? $diBrand['accent_text'] : '#ffffff';
    $batch = $report['batch'] ?? [];
    $counts = $report['counts'] ?? [];
    $openRows = (int) ($counts['needs_review'] ?? 0) + (int) ($counts['failed'] ?? 0);
    $rows = [
        ['Ready', (int) ($counts['ready'] ?? 0), '#166534'],
        ['Needs review', (int) ($counts['needs_review'] ?? 0), '#b45309'],
        ['Duplicate', (int) ($counts['duplicate'] ?? 0), '#0f172a'],
        ['Failed', (int) ($counts['failed'] ?? 0), '#b91c1c'],
        ['Still processing', (int) ($counts['pending'] ?? 0), '#0f172a'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice review summary from {{ $companyName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%;">
<!-- Preheader (hidden preview text) -->
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">Batch #{{ $batch['id'] ?? '' }} &mdash; {{ $batch['processed'] ?? 0 }} of {{ $batch['total'] ?? 0 }} source invoices reviewed by {{ $companyName }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:94%; background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #e2e8f0;">

                {{-- Header band (accent-coloured when branding active; default green) --}}
                <tr>
                    <td style="background-color: {{ $headerBg }}; padding:24px 28px; text-align:center;">
                        @if($diBrand['active'] && $diBrand['logo_url'])
                        <img src="{{ $diBrand['logo_url'] }}" alt="{{ $companyName }}" style="max-height:48px; width:auto; background:#ffffff; padding:4px 8px; border-radius:6px;"><br>
                        <span style="display:inline-block; margin-top:8px; color: {{ $headerText }}; font-size:16px; font-weight:bold;">{{ $companyName }}</span>
                        @else
                        <span style="color: {{ $headerText }}; font-size:22px; font-weight:bold; letter-spacing:0.5px;">{{ $companyName }}</span>
                        @endif
                        <div style="font-size:12px; color: {{ $headerText }}; opacity:0.85; margin-top:4px; letter-spacing:1px; text-transform:uppercase;">Invoice Review Summary</div>
                    </td>
                </tr>

                {{-- Intro --}}
                <tr>
                    <td style="padding:26px 28px 8px 28px;">
                        <p style="margin:0 0 6px 0; font-size:15px; color:#0f172a; font-weight:bold;">Batch #{{ $batch['id'] ?? '' }} &mdash; {{ $batch['status_label'] ?? '' }}</p>
                        <p style="margin:0; font-size:13px; color:#475569; line-height:1.6;">
                            Assalam-o-Alaikum,<br>
                            {{ $senderName !== '' ? $senderName . ' at ' . $companyName : $companyName }} has shared an invoice review summary with you.
                            It covers <strong>{{ $batch['processed'] ?? 0 }} of {{ $batch['total'] ?? 0 }}</strong> source invoices read in this batch.
                            @if($openRows > 0)
                            <strong>{{ $openRows }} row(s)</strong> still need attention &mdash; the attached PDF lists each file with the notes to resolve.
                            @endif
                        </p>
                    </td>
                </tr>

                {{-- Counts --}}
                <tr>
                    <td style="padding:16px 28px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:8px;">
                            @foreach($rows as $row)
                            <tr>
                                <td style="padding:9px 14px; font-size:12px; color:#64748b; @if(!$loop->last) border-bottom:1px solid #f1f5f9; @endif">{{ $row[0] }}</td>
                                <td style="padding:9px 14px; font-size:12px; color: {{ $row[2] }}; font-weight:bold; text-align:right; @if(!$loop->last) border-bottom:1px solid #f1f5f9; @endif">{{ $row[1] }}</td>
                            </tr>
                            @endforeach
                        </table>
                        @if(!empty($batch['finished_at']))
                        <p style="margin:8px 0 0 0; font-size:11px; color:#94a3b8;">Batch finished {{ $batch['finished_at'] }}.</p>
                        @endif
                    </td>
                </tr>

                {{-- Privacy note --}}
                <tr>
                    <td style="padding:4px 28px 8px 28px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                            <tr>
                                <td style="padding:12px 14px; font-size:12px; color:#475569; line-height:1.6;">
                                    <strong style="color:#0f172a;">The private source invoice photos are not attached to this email</strong>
                                    and are not linked from the summary. Only the review data &mdash; file name, status, short notes and the
                                    draft invoice number &mdash; is shared. To see a photo, ask {{ $companyName }} to open the batch in TaxNest.
                                    Nothing in this batch is submitted to FBR automatically.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px 20px 28px;" align="center">
                        <p style="margin:0; font-size:12px; color:#94a3b8;">The full review summary is attached as a PDF.</p>
                    </td>
                </tr>

                {{-- Footer (custom lines + optional platform credit) --}}
                <tr>
                    <td style="padding:14px 28px 20px 28px; border-top:1px solid #f1f5f9; text-align:center;">
                        @foreach($diBrand['footer_lines'] as $brandLine)
                        <p style="margin:0 0 3px 0; font-size:11px; color:#475569; font-weight:bold;">{{ $brandLine }}</p>
                        @endforeach
                        <p style="margin:0 0 3px 0; font-size:11px; color:#64748b;">Sent by <strong style="color:#374151;">{{ $companyName }}</strong>{{ $senderName !== '' ? ' (' . $senderName . ')' : '' }}</p>
                        <p style="margin:0; font-size:11px; color:#94a3b8;">This is a computer-generated notification. Reply to this address only if you know the sender.</p>
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
