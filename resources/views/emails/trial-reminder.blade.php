<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust:100%;">
    <!-- Preheader (hidden preview text) -->
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $headline }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#059669; padding:28px 32px; text-align:center;">
                            <span style="font-size:26px; font-weight:bold; color:#ffffff; letter-spacing:0.5px;">TaxNest</span>
                            <div style="font-size:12px; color:#d1fae5; margin-top:4px; letter-spacing:1px; text-transform:uppercase;">{{ $panelName }}</div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 6px; font-size:14px; color:#6b7280;">Assalam-o-Alaikum,</p>
                            <h1 style="margin:0 0 18px; font-size:20px; line-height:1.4; color:#111827;">{{ $headline }}</h1>

                            @foreach($paragraphs as $para)
                            <p style="margin:0 0 14px; font-size:14px; line-height:1.7; color:#374151;">{{ $para }}</p>
                            @endforeach

                            <!-- CTA button -->
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px auto 8px;">
                                <tr>
                                    <td style="border-radius:8px; background-color:#059669;">
                                        <a href="{{ $ctaUrl }}" style="display:inline-block; padding:13px 34px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $ctaLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:6px 0 0; font-size:12px; color:#9ca3af; text-align:center;">
                                Button kaam na kare to yeh link kholen:<br>
                                <a href="{{ $ctaUrl }}" style="color:#059669; word-break:break-all;">{{ $ctaUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <!-- Divider -->
                    <tr><td style="padding:0 32px;"><div style="border-top:1px solid #e5e7eb;"></div></td></tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:20px 32px 26px; text-align:center;">
                            <p style="margin:0 0 4px; font-size:12px; color:#6b7280;">Yeh email <strong style="color:#374151;">{{ $companyName }}</strong> ke TaxNest account ke liye bheja gaya hai.</p>
                            <p style="margin:0; font-size:12px; color:#9ca3af;">Team TaxNest &middot; FBR &amp; PRA compliant invoicing for Pakistan</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
