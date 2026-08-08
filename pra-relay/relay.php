<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Relay-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/health') {
    echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s'), 'server' => 'PRA Relay']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

// Token comes from the environment — NEVER hardcode it here (this file lives in a public repo).
$expectedToken = getenv('PRA_RELAY_TOKEN') ?: '';
$relayToken = $_SERVER['HTTP_X_RELAY_TOKEN'] ?? '';
if ($expectedToken === '' || $relayToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid relay token']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$praToken = $data['_pra_token'] ?? '';
$praUrl = $data['_pra_url'] ?? 'https://ims.pral.com.pk/ims/production/api/Live/PostData';
unset($data['_pra_token'], $data['_pra_url']);

$jsonPayload = json_encode($data);

echo_log("Relaying to PRA: " . $praUrl);
echo_log("Payload size: " . strlen($jsonPayload) . " bytes");

$ch = curl_init($praUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $praToken,
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$result = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

echo_log("PRA Response: HTTP {$httpCode}, Time: {$totalTime}s");

if ($result === false || $error) {
    echo_log("PRA Error: " . $error);
    http_response_code(502);
    echo json_encode([
        'relay_error' => true,
        'error' => 'PRA connection failed via relay: ' . $error,
        'http_code' => $httpCode,
    ]);
    exit;
}

echo_log("PRA Success: " . substr($result, 0, 200));

http_response_code($httpCode ?: 200);
echo $result;

function echo_log($msg) {
    $time = date('Y-m-d H:i:s');
    file_put_contents('php://stderr', "[{$time}] {$msg}\n");
}
