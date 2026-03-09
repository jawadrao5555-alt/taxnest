<?php

header('Content-Type: application/json');

$PROXY_SECRET = getenv('PRA_PROXY_SECRET') ?: 'your-secret-key-here';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

$incomingSecret = $_SERVER['HTTP_X_PROXY_SECRET'] ?? '';
if ($PROXY_SECRET !== 'your-secret-key-here' && $incomingSecret !== $PROXY_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['pra_url']) || !isset($body['pra_token']) || !isset($body['invoice_data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: pra_url, pra_token, invoice_data']);
    exit;
}

$praUrl = $body['pra_url'];
$praToken = $body['pra_token'];
$invoiceData = $body['invoice_data'];

$allowedDomains = ['ims.pral.com.pk'];
$parsedUrl = parse_url($praUrl);
if (!$parsedUrl || !in_array($parsedUrl['host'] ?? '', $allowedDomains)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid PRA URL domain']);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $praUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $praToken,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode([
        'error' => 'PRA connection failed',
        'curl_error' => $error,
        'Code' => '502',
    ]);
    exit;
}

http_response_code($httpCode ?: 200);
echo $result;
