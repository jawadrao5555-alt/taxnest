<?php

/**
 * Hotel V1 local HTTP journey smoke (disposable MariaDB + file sessions).
 * Complements Chrome UI when Chromium system libs are unavailable.
 *
 * Usage (SESSION_DRIVER=file artisan serve already running):
 *   php scripts/cloud-local-hotel-http-smoke.php
 *
 * Evidence: .local/browser-evidence/hotel-http-*.{html,json}
 */

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8000';
if (!preg_match('#^https?://(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$#i', rtrim($base, '/'))) {
    fwrite(STDERR, "REFUSED non-loopback BASE_URL\n");
    exit(2);
}

$credsFile = __DIR__ . '/../.local/hotel-qa-creds.env';
if (!is_file($credsFile)) {
    fwrite(STDERR, "Missing hotel QA creds — run VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-qa-seed.php\n");
    exit(2);
}
$creds = [];
foreach (file($credsFile) as $line) {
    if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m)) {
        $creds[$m[1]] = trim($m[2], "\"'");
    }
}
$owner = $creds['HOTEL_QA_LOGIN'] ?? '';
$pass = $creds['HOTEL_QA_PASS'] ?? '';
$hk = $creds['HOTEL_QA_HK_LOGIN'] ?? '';
$denied = $creds['HOTEL_QA_DENIED_LOGIN'] ?? '';

$evidence = __DIR__ . '/../.local/browser-evidence';
@mkdir($evidence, 0700, true);
$jar = tempnam(sys_get_temp_dir(), 'hoteljar');
$fail = 0;
$ok = function (string $m) { echo "    OK: $m\n"; };
$bad = function (string $m) use (&$fail) { $fail++; echo "    FAIL: $m\n"; };

function req(string $method, string $url, string $jar, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);
    $loc = null;
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $loc = trim($m[1]);
    }

    return compact('code', 'headers', 'body', 'loc');
}

function csrf(string $html): string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function save(string $evidence, string $name, string $body, array $meta = []): void
{
    file_put_contents("$evidence/$name.html", $body);
    file_put_contents("$evidence/$name.json", json_encode($meta + ['time' => date('c'), 'bytes' => strlen($body)], JSON_PRETTY_PRINT));
}

echo "HOTEL HTTP SMOKE target=$base\n";

// Owner login
$loginPage = req('GET', "$base/pos/login", $jar);
$token = csrf($loginPage['body']);
$login = req('POST', "$base/pos/login", $jar, [
    '_token' => $token,
    'login' => $owner,
    'password' => $pass,
]);
if ($login['code'] === 302 && $login['loc'] && !str_contains($login['loc'], '/pos/login')) {
    $ok('owner login');
} else {
    $bad('owner login code=' . $login['code'] . ' loc=' . ($login['loc'] ?? ''));
}

$dash = req('GET', "$base/pos/hotel", $jar);
save($evidence, 'hotel-http-02-dashboard', $dash['body'], ['code' => $dash['code']]);
if ($dash['code'] === 200 && str_contains($dash['body'], 'pos/hotel/stays/create')) {
    $ok('dashboard 200 with new-stay link');
} else {
    $bad('dashboard code=' . $dash['code']);
}

$rooms = req('GET', "$base/pos/hotel/rooms", $jar);
save($evidence, 'hotel-http-03-rooms', $rooms['body'], ['code' => $rooms['code']]);
if ($rooms['code'] === 200 && (str_contains($rooms['body'], '101') || str_contains($rooms['body'], '102'))) {
    $ok('rooms board');
} else {
    $bad('rooms board');
}

// Flip housekeeping on first dirty room form if present
if (preg_match('#action="([^"]*hotel/rooms/(\d+)/housekeeping)"#', $rooms['body'], $hm)) {
    $hkToken = csrf($rooms['body']);
    $hkPost = req('POST', $hm[1], $jar, ['_token' => $hkToken, 'housekeeping' => 'clean']);
    if (in_array($hkPost['code'], [302, 200], true)) {
        $ok('housekeeping → clean room ' . $hm[2]);
    } else {
        $bad('housekeeping post ' . $hkPost['code']);
    }
} else {
    $bad('no housekeeping form');
}

$create = req('GET', "$base/pos/hotel/stays/create", $jar);
save($evidence, 'hotel-http-04-create', $create['body'], ['code' => $create['code']]);
$token = csrf($create['body']);
$roomId = null;
if (preg_match_all('/<option value="(\d+)"[^>]*>/', $create['body'], $opts)) {
    $roomId = $opts[1][0] ?? null;
}
$tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$store = req('POST', "$base/pos/hotel/stays", $jar, [
    '_token' => $token,
    'idempotency_key' => bin2hex(random_bytes(8)),
    'room_id' => $roomId,
    'check_in_date' => $today,
    'check_out_date' => $tomorrow,
    'guest_name' => 'HTTP QA Guest',
    'guest_phone' => '03001112233',
    'walk_in' => '1',
    'adult_count' => 1,
    'child_count' => 0,
]);
$stayUrl = $store['loc'] ?? '';
if ($store['code'] === 302 && preg_match('#/pos/hotel/stays/(\d+)#', $stayUrl, $sm)) {
    $ok('stay booked id=' . $sm[1]);
    $stayId = $sm[1];
} else {
    $bad('stay book code=' . $store['code'] . ' loc=' . $stayUrl);
    $stayId = null;
}

if ($stayId) {
    $show = req('GET', "$base/pos/hotel/stays/$stayId", $jar);
    save($evidence, 'hotel-http-05-stay', $show['body'], ['code' => $show['code']]);
    $token = csrf($show['body']);
    $dep = req('POST', "$base/pos/hotel/stays/$stayId/folio/payment", $jar, [
        '_token' => $token,
        'amount' => 2000,
        'payment_method' => 'cash',
        'kind' => 'deposit',
    ]);
    if ($dep['code'] === 302) {
        $ok('deposit posted');
    } else {
        $bad('deposit ' . $dep['code']);
    }
    $show2 = req('GET', "$base/pos/hotel/stays/$stayId", $jar);
    $token = csrf($show2['body']);
    $pay = req('POST', "$base/pos/hotel/stays/$stayId/folio/payment", $jar, [
        '_token' => $token,
        'amount' => 8000,
        'payment_method' => 'cash',
        'kind' => 'payment',
    ]);
    if ($pay['code'] === 302) {
        $ok('advance payment posted');
    } else {
        $bad('payment ' . $pay['code']);
    }
    $show3 = req('GET', "$base/pos/hotel/stays/$stayId", $jar);
    $token = csrf($show3['body']);
    $co = req('POST', "$base/pos/hotel/stays/$stayId/check-out", $jar, ['_token' => $token]);
    if ($co['code'] === 302) {
        $ok('checkout posted');
    } else {
        $bad('checkout ' . $co['code']);
    }
    $show4 = req('GET', "$base/pos/hotel/stays/$stayId", $jar);
    save($evidence, 'hotel-http-06-checkout', $show4['body'], ['code' => $show4['code']]);
}

// Permissions: logout then HK
req('GET', "$base/pos/logout", $jar);
@unlink($jar);
$jar = tempnam(sys_get_temp_dir(), 'hoteljar');
$loginPage = req('GET', "$base/pos/login", $jar);
$token = csrf($loginPage['body']);
req('POST', "$base/pos/login", $jar, ['_token' => $token, 'login' => $hk, 'password' => $pass]);
$hkDesk = req('GET', "$base/pos/hotel", $jar);
if ($hkDesk['code'] === 302 || ($hkDesk['code'] === 200 && !str_contains($hkDesk['body'], 'pos/hotel/stays/create'))) {
    $ok('HK blocked/redirected from front desk code=' . $hkDesk['code']);
} else {
    $bad('HK reached front desk');
}
$hkRooms = req('GET', "$base/pos/hotel/rooms", $jar);
if ($hkRooms['code'] === 200) {
    $ok('HK rooms board');
} else {
    $bad('HK rooms ' . $hkRooms['code']);
}
save($evidence, 'hotel-http-07-hk', $hkRooms['body'], ['desk' => $hkDesk['code'], 'rooms' => $hkRooms['code']]);

req('GET', "$base/pos/logout", $jar);
@unlink($jar);
$jar = tempnam(sys_get_temp_dir(), 'hoteljar');
$loginPage = req('GET', "$base/pos/login", $jar);
$token = csrf($loginPage['body']);
req('POST', "$base/pos/login", $jar, ['_token' => $token, 'login' => $denied, 'password' => $pass]);
$d1 = req('GET', "$base/pos/hotel", $jar);
$d2 = req('GET', "$base/pos/hotel/rooms", $jar);
if ($d1['code'] === 302) {
    $ok('denied cashier blocked from desk');
} else {
    $bad('denied desk ' . $d1['code']);
}
if ($d2['code'] === 302) {
    $ok('denied cashier blocked from rooms');
} else {
    $bad('denied rooms ' . $d2['code']);
}
save($evidence, 'hotel-http-08-denied', $d1['body'] . "\n---\n" . $d2['body'], ['desk' => $d1['code'], 'rooms' => $d2['code']]);

@unlink($jar);
file_put_contents("$evidence/hotel-http-summary.json", json_encode([
    'failed' => $fail,
    'time' => date('c'),
    'base' => $base,
], JSON_PRETTY_PRINT));

if ($fail) {
    echo "HOTEL HTTP SMOKE: $fail failure(s)\n";
    exit(1);
}
echo "HOTEL HTTP SMOKE: PASS\n";
exit(0);
