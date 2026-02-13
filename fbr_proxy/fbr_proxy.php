<?php
header('Content-Type: application/json');

$PROXY_SECRET = 'TaxNest_FBR_Proxy_2024_SecureKey';

$headers = getallheaders();
$proxyAuth = $headers['X-Proxy-Secret'] ?? $headers['x-proxy-secret'] ?? '';

if ($proxyAuth !== $PROXY_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized proxy access']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$fbrUrl = $data['fbr_url'] ?? '';
$fbrToken = $data['fbr_token'] ?? '';
$fbrPayload = $data['fbr_payload'] ?? null;

if (empty($fbrUrl) || empty($fbrToken) || !$fbrPayload) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: fbr_url, fbr_token, fbr_payload']);
    exit;
}

$allowedUrls = [
    'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
    'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
    'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata',
    'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb',
];

if (!in_array($fbrUrl, $allowedUrls)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid FBR URL']);
    exit;
}

$jsonPayload = json_encode($fbrPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

$ch = curl_init($fbrUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $fbrToken,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode([
        'proxy_error' => true,
        'error' => 'FBR connection failed: ' . $curlError,
    ]);
    exit;
}

http_response_code($httpCode);
echo $response;
