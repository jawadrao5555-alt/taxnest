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
$case = collect($data['readOnlyJourneys'])->firstWhere('name', 'readonly-reporting-connection')['reportingConnector'] ?? null;
if (! $case || ! str_starts_with($case['companyName'], 'Synthetic ')) {
    exit(2);
}
$reports = $app->make(\App\Services\Reporting\CompanyReadReports::class);
$args = ['company_name' => $case['companyName'], 'date_from' => $case['date']];
$scope = [$case['companyId']];
// Let a native query exception fail with its actual schema error; the only binds
// are this fictional company ID/date. This probe never has OAuth/fiscal credentials.
try {
    $summary = $reports->summary($args, $scope);
    if ((float) $summary['totals']['net_billing'] !== (float) $case['billing']) {
        fwrite(STDERR, 'Native source total mismatch: '.json_encode($summary['totals'])."\n");
        exit(1);
    }
    $bills = $reports->bills($args, $scope);
    if (! $bills['bills']) {
        exit(1);
    }
    $reports->detail($args + ['bill_id' => $bills['bills'][0]['id']], $scope);
    $reports->taxItems($args, $scope);
    $reports->operations($args, $scope);
    $reports->payments($args, $scope);
    $cashArgs = ['company_name' => $case['companyName'], 'date_from' => $case['dayCloseDate']];
    $before = \Illuminate\Support\Facades\DB::table('pos_day_close_reports')->where('company_id', $case['companyId'])->orderBy('id')->get()->toJson();
    $cash = $reports->dayClose($cashArgs, $scope);
    if (count($cash['records']) !== 1 || $cash['records'][0]['arithmetic']['calculated_expected'] !== 750.0
        || $cash['records'][0]['arithmetic']['expected_check'] !== 'PASS' || $cash['coverage']['complete'] !== false
        || str_contains(json_encode($cash), 'SYNTHETIC-PRIVATE-RIDER')) {
        throw new RuntimeException('Native frozen cash evidence mismatch.');
    }
    foreach (['openings', 'counter_closes'] as $stream) $reports->dayClose($cashArgs + ['stream' => $stream], $scope);
    if ($before !== \Illuminate\Support\Facades\DB::table('pos_day_close_reports')->where('company_id', $case['companyId'])->orderBy('id')->get()->toJson()) {
        throw new RuntimeException('Native read changed frozen cash evidence.');
    }
    echo "NATIVE CASH EVIDENCE PASS: literal date, numeric snapshot arithmetic, redaction and unchanged business rows.\n";
    echo "NATIVE READONLY REPORTS PASS: known source totals, bill/item evidence, stock/print/agent and payment snapshots.\n";

} catch (Throwable $e) {
    fwrite(STDERR, 'Native reporting probe failed: '.$e->getMessage()."\n");
    exit(1);
}
