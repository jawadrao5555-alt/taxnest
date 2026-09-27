<?php

// Dependency-free behavioral checks against the REAL service, with fictional
// snapshots only. No database, network, production credentials or restore writes.
require dirname(__DIR__, 2).'/app/Services/PosSettingsSnapshot.php';

use App\Services\PosSettingsSnapshot;

$service = new PosSettingsSnapshot();
$before = ['generated_at' => '2026-09-27T00:00:00Z', 'tables' => ['companies' => []]];
foreach (range(1, 4) as $id) {
    $before['tables']['companies'][$id] = [
        '_company_id' => (string) $id, 'agent_version' => '1.13.13',
        'agent_offline_mode' => '0', 'agent_enabled' => '1',
        'pos_printer_settings' => '{"receipt_printer":"Counter-A","kot_printer":"Kitchen"}',
    ];
}
$after = $before;
foreach (range(1, 4) as $id) {
    $after['tables']['companies'][$id]['agent_version'] = '1.13.14';
}
$after['tables']['companies'][1]['agent_offline_mode'] = '1';
$plan = $service->planProtectedRestore($before, $after);
if (($argv[1] ?? '') === '--expect-regression') {
    if ($plan['ok'] || count($plan['refused']) !== 5 || $plan['restore'] !== []) {
        throw new RuntimeException('Expected five heartbeat-only unsupported-column refusals.');
    }
    echo "REPRODUCED: four version reports + one offline-mode report yield five unsupported-column refusals.\n";
    exit;
}

$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    if (! $passed) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$check($plan['ok'] && $plan['reason'] === 'already_clean' && $plan['restore'] === [], 'Reported failure shape must be clean without writes.');
$observations = [
    'agent_version' => '1.13.14', 'agent_offline_mode' => '1',
    'agent_update_target' => '1.13.14', 'agent_update_stage' => 'download',
    'agent_update_error' => 'synthetic update failure',
];
foreach ($observations as $column => $value) {
    $old = $before;
    $new = $before;
    foreach (range(1, 4) as $id) {
        $old['tables']['companies'][$id][$column] = null;
        $new['tables']['companies'][$id][$column] = null;
    }
    $new['tables']['companies'][1][$column] = $value;
    foreach ([[$old, $new], [$new, $old]] as [$b, $a]) {
        $check($service->planProtectedRestore($b, $a)['ok'], $column.' must be telemetry in both directions.');
    }
    foreach (range(1, 4) as $id) {
        unset($new['tables']['companies'][$id][$column]);
    }
    $diff = $service->diff($old, $new);
    $check($diff['changed'] === [] && $diff['dropped_columns'] === [], 'Legacy-only column must not appear dropped: '.$column);
}
$captured = $after;
foreach ($captured['tables']['companies'] as &$row) {
    foreach (array_keys($observations) as $column) {
        unset($row[$column]);
    }
}
unset($row);
foreach ([[$before, $captured], [$captured, $before]] as [$b, $a]) {
    $diff = $service->diff($b, $a);
    $check($diff['changed'] === [] && $diff['dropped_columns'] === [] && $diff['new_columns'] === [], 'Old/new capture schema must remain compatible.');
}
foreach ([
    'agent_enabled' => ['1', '0'], 'agent_submits_pra' => ['1', '0'],
    'agent_future_policy' => ['manual', 'automatic'],
    'pos_printer_settings' => ['{"kot_printer":"Kitchen"}', '{"kot_printer":"Wrong"}'],
    'feature_flags' => ['{"agent_offline_mode":true}', '{"agent_offline_mode":false}'],
    'pos_tax_rate' => ['16', '0'],
] as $column => [$oldValue, $newValue]) {
    $b = $before;
    $a = $after;
    foreach (range(1, 4) as $id) {
        $b['tables']['companies'][$id][$column] = $oldValue;
        $a['tables']['companies'][$id][$column] = $oldValue;
    }
    $a['tables']['companies'][2][$column] = $newValue;
    $diff = $service->diff($b, $a);
    $check(count($diff['changed']) === 1 && $diff['changed'][0]['company_id'] === '2'
        && $diff['changed'][0]['column'] === $column, 'Real second-tenant setting must remain protected: '.$column);
    $check(! $service->planProtectedRestore($b, $a)['ok'], 'Do not restore unsupported settings: '.$column);
    unset($a['tables']['companies'][1][$column], $a['tables']['companies'][2][$column],
        $a['tables']['companies'][3][$column], $a['tables']['companies'][4][$column]);
    $check(in_array($column, $service->diff($b, $a)['dropped_columns']['companies'], true), 'Real dropped setting must be caught: '.$column);
}
foreach (array_keys($observations) as $column) {
    $b = $before;
    $a = $after;
    $b['tables']['branches'][10] = ['_company_id' => '2', $column => 'before'];
    $a['tables']['branches'][10] = ['_company_id' => '2', $column => 'after'];
    $check(count($service->diff($b, $a)['changed']) === 1, 'Same-named branch setting must remain protected: '.$column);
}
$missingTable = $captured;
unset($missingTable['tables']['companies']);
$check($service->diff($before, $missingTable)['dropped_tables'] === ['companies'], 'Dropped table must remain fatal.');
$before['tables']['users'][10] = ['_company_id' => '2', 'pos_custom_access' => '["sales"]'];
$after['tables']['users'][10] = ['_company_id' => '2', 'pos_custom_access' => '["sales","service_jobs"]'];
$plan = $service->planProtectedRestore($before, $after);
$check($plan['ok'] && count($plan['restore']) === 1 && $plan['restore'][0]['column'] === 'pos_custom_access', 'Existing narrow permission recovery must remain available.');
echo "PASS: {$checks} real-service heartbeat/baseline/protection assertions.\n";
