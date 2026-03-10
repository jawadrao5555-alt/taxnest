<?php

/*
 * PRA PROXY ENDPOINT — Add this to nestpay.replit.app project
 * 
 * Route: POST /api/pra-proxy/submit
 * 
 * This receives PRA invoice data from TaxNest and forwards it to
 * PRA's ims.pral.com.pk server using the whitelisted IP.
 * 
 * REQUEST FORMAT (JSON):
 * {
 *   "pra_url": "https://ims.pral.com.pk/ims/sandbox/api/Live/PostData",
 *   "pra_token": "24d8fab3-f2e9-398f-ae17-b387125ec4a2",
 *   "invoice_data": { ...PRA invoice payload... }
 * }
 * 
 * RESPONSE: Direct PRA API response forwarded back
 * 
 * ============================================================
 * If nestpay is a Laravel project, add this route:
 * ============================================================
 * 
 * In routes/api.php add:
 * 
 * Route::post('/pra-proxy/submit', function (Request $request) {
 *     $praUrl = $request->input('pra_url');
 *     $praToken = $request->input('pra_token');
 *     $invoiceData = $request->input('invoice_data');
 * 
 *     if (!$praUrl || !$praToken || !$invoiceData) {
 *         return response()->json(['error' => 'Missing pra_url, pra_token, or invoice_data'], 400);
 *     }
 * 
 *     $allowedHosts = ['ims.pral.com.pk'];
 *     $parsed = parse_url($praUrl);
 *     if (!in_array($parsed['host'] ?? '', $allowedHosts)) {
 *         return response()->json(['error' => 'Invalid PRA URL'], 400);
 *     }
 * 
 *     try {
 *         $response = Http::timeout(30)
 *             ->withToken($praToken)
 *             ->post($praUrl, $invoiceData);
 * 
 *         return response($response->body(), $response->status())
 *             ->header('Content-Type', 'application/json');
 *     } catch (\Exception $e) {
 *         return response()->json([
 *             'error' => 'PRA connection failed: ' . $e->getMessage(),
 *             'Code' => '502'
 *         ], 502);
 *     }
 * });
 * 
 * ============================================================
 * If nestpay is a Node.js/Express project, add this route:
 * ============================================================
 * 
 * app.post('/api/pra-proxy/submit', async (req, res) => {
 *     const { pra_url, pra_token, invoice_data } = req.body;
 *     
 *     if (!pra_url || !pra_token || !invoice_data) {
 *         return res.status(400).json({ error: 'Missing pra_url, pra_token, or invoice_data' });
 *     }
 *     
 *     const url = new URL(pra_url);
 *     if (url.hostname !== 'ims.pral.com.pk') {
 *         return res.status(400).json({ error: 'Invalid PRA URL' });
 *     }
 *     
 *     try {
 *         const response = await fetch(pra_url, {
 *             method: 'POST',
 *             headers: {
 *                 'Content-Type': 'application/json',
 *                 'Authorization': `Bearer ${pra_token}`
 *             },
 *             body: JSON.stringify(invoice_data)
 *         });
 *         
 *         const data = await response.text();
 *         res.status(response.status).type('json').send(data);
 *     } catch (e) {
 *         res.status(502).json({ error: 'PRA connection failed: ' + e.message, Code: '502' });
 *     }
 * });
 * 
 * ============================================================
 * If nestpay is plain PHP (no framework):
 * ============================================================
 * 
 * Save this as: api/pra-proxy/submit/index.php
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$praUrl = $body['pra_url'] ?? '';
$praToken = $body['pra_token'] ?? '';
$invoiceData = $body['invoice_data'] ?? null;

if (!$praUrl || !$praToken || !$invoiceData) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing pra_url, pra_token, or invoice_data']);
    exit;
}

$parsed = parse_url($praUrl);
if (($parsed['host'] ?? '') !== 'ims.pral.com.pk') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid PRA URL']);
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
        'error' => 'PRA connection failed: ' . $error,
        'Code' => '502',
    ]);
    exit;
}

http_response_code($httpCode ?: 200);
echo $result;
