<?php
/**
 * Replit deployment holding page.
 *
 * The real TaxNest production site lives on cPanel at https://taxnest.com.pk.
 * The Replit deployment (laravel-setup.replit.app) is intentionally NOT a
 * running copy of the app (production is MySQL-only; see the PRODUCTION DB
 * GUARD in AppServiceProvider). Instead we publish this tiny page so:
 *   - the Replit publish/build always succeeds (no composer, no Laravel boot)
 *   - no stale copy of the app or its data is exposed publicly
 *   - visitors with old links get redirected to the real site.
 *
 * Served via: php -S 0.0.0.0:5000 replit-holding/server.php
 * Returns HTTP 200 on every path (deployment health probe needs 200 on "/").
 */

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="3;url=https://taxnest.com.pk/">
<title>TaxNest — taxnest.com.pk</title>
<style>
  body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#0A4D5C;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}
  .card{padding:2.5rem 1.5rem;max-width:32rem}
  h1{font-size:1.6rem;margin:0 0 .75rem}
  p{color:#cfe3e8;line-height:1.6;margin:.4rem 0}
  a.btn{display:inline-block;margin-top:1.25rem;background:#E7BF3B;color:#0A4D5C;font-weight:700;text-decoration:none;padding:.75rem 1.75rem;border-radius:.5rem}
</style>
</head>
<body>
<div class="card">
  <h1>TaxNest ab yahan hai: taxnest.com.pk</h1>
  <p>This page has moved. TaxNest &amp; NestPOS now live at our official website.</p>
  <p>Aap ko 3 second mein khud-ba-khud wahan bhej diya jayega.</p>
  <a class="btn" href="https://taxnest.com.pk/">taxnest.com.pk kholain</a>
</div>
</body>
</html>
