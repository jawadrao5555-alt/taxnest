<!doctype html>
<html lang="en">
<body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a">
<div style="max-width:640px;margin:32px auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px">
    <p style="margin:0 0 8px;color:#0891b2;font-size:12px;font-weight:700;text-transform:uppercase">TaxNest production control</p>
    <h1 style="margin:0 0 16px;font-size:24px">Deployment approval requested</h1>
    <p>PR <strong>#{{ $approval->pull_request_number }}</strong> passed the required validation and is waiting for an owner decision.</p>
    <p style="font-family:monospace;word-break:break-all;background:#f8fafc;padding:12px;border-radius:8px">{{ $approval->head_sha }}</p>
    <p><a href="{{ $reviewUrl }}" style="display:inline-block;background:#22d3ee;color:#082f49;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px">Review &amp; Approve</a></p>
    <p style="font-size:13px;color:#64748b">Opening this link never approves or deploys anything. You must be signed in as the addressed TaxNest super admin and enter your current password. The PR and exact HEAD SHA are revalidated at approval time. The link expires with this request and cannot authorize a moved PR.</p>
</div>
</body>
</html>
