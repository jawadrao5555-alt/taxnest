<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Proxy-Auth, X-Pra-Url, X-Pra-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$authHeader = $_SERVER['HTTP_X_PROXY_AUTH'] ?? '';
if ($authHeader !== 'TaxNestPraProxy2026Secret') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = file_get_contents('php://input');
if (!$input || !json_decode($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$praUrl = $_SERVER['HTTP_X_PRA_URL'] ?? 'https://ims.pral.com.pk/ims/production/api/Live/PostData';
$praToken = $_SERVER['HTTP_X_PRA_TOKEN'] ?? '';

$ch = curl_init($praUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $input,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $praToken,
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode([
        'Code' => '500',
        'Response' => 'Proxy: PRA connection failed - ' . $error,
        'InvoiceNumber' => 'Not Available'
    ]);
    exit;
}

http_response_code($httpCode ?: 200);
echo $response;
