<?php

/*
 * Support Inbox (support@taxnest.com.pk) — IMAP read + SMTP send config.
 * Password comes ONLY from env (SUPPORT_MAIL_PASSWORD) — never hardcode.
 */
return [
    'host' => env('SUPPORT_MAIL_HOST', 'mail.taxnest.com.pk'),
    'imap_port' => (int) env('SUPPORT_MAIL_IMAP_PORT', 993),
    'smtp_port' => (int) env('SUPPORT_MAIL_SMTP_PORT', 465),
    'username' => env('SUPPORT_MAIL_USERNAME', 'support@taxnest.com.pk'),
    'password' => env('SUPPORT_MAIL_PASSWORD'),
    'from_name' => env('SUPPORT_MAIL_FROM_NAME', 'TaxNest Support'),
    // Set false only if the mail server certificate does not match the host.
    'validate_cert' => (bool) env('SUPPORT_MAIL_VALIDATE_CERT', true),
    'per_page' => 20,
];
