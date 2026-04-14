<?php
header('Content-Type: text/plain');
echo "=== PRA Connection Test ===\n";
echo "Server IP: " . file_get_contents('https://api.ipify.org') . "\n";
echo "Date/Time: " . date('Y-m-d H:i:s T') . "\n";
echo "Target: 103.125.60.124:443 (ims.pral.com.pk)\n\n";

echo "--- Test 1: TCP Socket Connection ---\n";
$start = microtime(true);
$sock = @fsockopen('103.125.60.124', 443, $errno, $errstr, 15);
$elapsed = round(microtime(true) - $start, 2);
if ($sock) {
    echo "RESULT: SUCCESS (connected in {$elapsed}s)\n";
    fclose($sock);
} else {
    echo "RESULT: FAILED after {$elapsed}s\n";
    echo "Error #{$errno}: {$errstr}\n";
}

echo "\n--- Test 2: CURL to PRA API ---\n";
$ch = curl_init('https://ims.pral.com.pk/ims/production/api/Live/PostData');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_VERBOSE => true,
]);
$start = microtime(true);
$result = curl_exec($ch);
$elapsed = round(microtime(true) - $start, 2);
$error = curl_error($ch);
$errno2 = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Connected IP: {$ip}\n";
echo "Time: {$elapsed}s\n";
if ($error) {
    echo "CURL Error #{$errno2}: {$error}\n";
} else {
    echo "Response: " . substr($result, 0, 200) . "\n";
}

echo "\n--- Test 3: DNS Resolution ---\n";
$dns = dns_get_record('ims.pral.com.pk', DNS_A);
foreach ($dns as $record) {
    echo "DNS A Record: {$record['ip']}\n";
}

echo "\n--- Test 4: Other HTTPS Sites (Control Test) ---\n";
$ch2 = curl_init('https://www.google.com');
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
$r2 = curl_exec($ch2);
$e2 = curl_error($ch2);
curl_close($ch2);
echo "Google.com: " . ($e2 ? "FAILED - {$e2}" : "OK (" . strlen($r2) . " bytes)") . "\n";

echo "\n=== END TEST ===\n";
