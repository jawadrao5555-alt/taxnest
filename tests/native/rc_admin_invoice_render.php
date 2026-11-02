<?php

// SELECT-only diagnostic against the exact fictional browser database, never a live source.
$socket = (string) getenv('DB_SOCKET');
$fixture = (string) getenv('RC_BROWSER_FIXTURE_OUT');
if (getenv('DB_DATABASE') !== 'taxnest_rc_browser' || getenv('DB_HOST') !== '127.0.0.1'
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-browser-[0-9]+/run/mariadb\.sock$#', $socket)
    || ! preg_match('#^/tmp/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?/safe-runtime/browser-state/fixture\.json$#', $fixture)
    || is_link($socket) || is_link($fixture) || ! is_file($fixture)) {
    fwrite(STDERR, "Native reporting probe refused: exact synthetic fixture/socket required.\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql'
    || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'taxnest_rc_browser') {
    exit(2);
}
$data = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
if (($data['synthetic'] ?? false) !== true) {
    exit(2);
}
$case = collect($data['readOnlyJourneys'])->firstWhere('name', 'admin-dashboard-details');
foreach ($case['previewDocuments'] ?? [] as $doc) {
    $model = match ($doc['product']) {
        'di' => \App\Models\Invoice::class, 'pos' => \App\Models\PosTransaction::class,
        'fbrpos' => \App\Models\FbrPosTransaction::class, default => throw new RuntimeException('Unexpected synthetic product'),
    };
    $company = \App\Models\Company::where('product_type', $doc['product'])->findOrFail($doc['company']);
    if (! str_starts_with($company->name, 'Synthetic ')) {
        exit(2);
    }
    $record = $model::where('company_id', $company->id)->findOrFail($doc['record']);
    $bytes = app(\App\Services\AdminInvoiceDocument::class)->pdf($record, $company, $doc['product'])->output();
    if (! str_starts_with($bytes, '%PDF-')) {
        throw new RuntimeException('Synthetic invoice PDF is invalid');
    }
    echo 'PASS: actual native '.$doc['product']." admin invoice renderer; no fiscal transport\n";
}
if (count($case['previewDocuments'] ?? []) !== 3) {
    exit(2);
}
