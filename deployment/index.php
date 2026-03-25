<?php

define('LARAVEL_START', microtime(true));

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files from taxnest/public if they exist
if ($uri !== '/' && file_exists(__DIR__.'/taxnest/public'.$uri)) {
    $filePath = __DIR__.'/taxnest/public'.$uri;
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'pdf' => 'application/pdf',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: '.$mimeTypes[$ext]);
        header('Content-Length: '.filesize($filePath));
        header('Cache-Control: public, max-age=31536000');
        readfile($filePath);
        exit;
    }
    return false;
}

require __DIR__.'/taxnest/vendor/autoload.php';

$app = require_once __DIR__.'/taxnest/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
