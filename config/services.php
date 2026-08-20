<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pra' => [
        // Shared secret for the nestpay PRA relay (X-Relay-Token header).
        // Actual value lives ONLY in live .env / relay host env — never in the repo.
        'relay_token' => env('PRA_RELAY_TOKEN', ''),
    ],

    'cloudflare' => [
        // API token with Zone Settings Edit permission (auto-fix Rocket Loader).
        'api_token' => env('CLOUDFLARE_API_TOKEN', ''),
        'zone_id' => env('CLOUDFLARE_ZONE_ID', ''),
    ],

    'uptime_watch' => [
        // Public URL site:uptime-watch pings THROUGH Cloudflare. Deliberately not
        // APP_URL: dev/staging point APP_URL at the workspace, and the watchdog
        // must always measure the real live site.
        'url' => env('UPTIME_WATCH_URL', 'https://taxnest.com.pk/up'),
        // Origin IP for the Cloudflare-bypass probe. Left empty it is resolved
        // from the cPanel hostname (never Cloudflare-proxied), so a hosting IP
        // change needs no redeploy.
        'origin_ip' => env('UPTIME_WATCH_ORIGIN_IP', ''),
    ],

    'vapid' => [
        'public'  => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@taxnest.com.pk'),
    ],

    'fcm' => [
        // Firebase service-account credential for rider-app push (Task #1106).
        // Preferred on cPanel live: upload the service-account JSON file to
        // storage/app/firebase/rider-fcm.json (outside the public repo; the
        // whole storage/app dir is gitignored). Alternatively paste the JSON
        // (raw or base64) into FIREBASE_CREDENTIALS_JSON in .env.
        // Missing credential = push silently disabled; 15-min poll fallback
        // keeps working. NEVER commit the JSON — repo is public.
        'credentials_file' => env('FIREBASE_CREDENTIALS_FILE', storage_path('app/firebase/rider-fcm.json')),
        'credentials_json' => env('FIREBASE_CREDENTIALS_JSON', ''),
    ],

];
