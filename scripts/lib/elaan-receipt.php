<?php
// Run only inside the approved deployment runner. Never print DB credentials/errors.
function elaanReceiptPrepare(): void
{
    $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
    if (! $schema->hasTable('app_updates')) throw new RuntimeException('ELAAN_SCHEMA_TABLE_MISSING');
    if (! $schema->hasColumn('app_updates', 'deployment_key')) {
        $schema->table('app_updates', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->string('deployment_key', 64)->nullable()->unique('app_updates_deploy_key_uidx');
        });
    }
    if (! $schema->hasColumn('app_updates', 'target_categories')) {
        $schema->table('app_updates', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->json('target_categories')->nullable();
        });
    }
}

function elaanReceiptCount(string $scope, string $sha, ?string $since): int
{
    if (! preg_match('/^[a-f0-9]{40}$/D', $sha)) throw new RuntimeException('ELAAN_INVALID_SHA');
    if (! Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'deployment_key')) {
        throw new RuntimeException('ELAAN_SCHEMA_KEY_MISSING');
    }
    $query = Illuminate\Support\Facades\DB::table('app_updates')->whereRaw($scope)->where('deployment_key', $sha);
    if (Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'archived_at')) $query->whereNull('archived_at');
    if ($since !== null) $query->where('created_at', '>', $since);
    return $query->count();
}

// Deliberately exclude exception messages, SQL, bindings, paths and credentials.
function elaanReceiptFailure(Throwable $error, string $stage): string
{
    $codes = ['ELAAN_SCHEMA_TABLE_MISSING', 'ELAAN_SCHEMA_KEY_MISSING', 'ELAAN_INVALID_SHA', 'ELAAN_INVALID_SCOPE'];
    if ($error instanceof RuntimeException && in_array($error->getMessage(), $codes, true)) return $error->getMessage();
    $stages = ['bootstrap', 'prepare', 'marker', 'scope', 'fresh_query', 'existing_query'];
    $stage = in_array($stage, $stages, true) ? $stage : 'unknown';
    $result = 'ELAAN_DB_ERROR stage='.$stage;
    for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
        if ($cause instanceof PDOException && is_array($cause->errorInfo)) {
            $state = (string) ($cause->errorInfo[0] ?? '');
            $driver = (string) ($cause->errorInfo[1] ?? '');
            if (preg_match('/^[A-Z0-9]{5}$/D', $state)) $result .= ' sqlstate='.$state;
            if (preg_match('/^[0-9]{1,6}$/D', $driver)) $result .= ' driver='.$driver;
            break;
        }
    }
    return $result;
}

// Include functions in isolated tests without opening any application/database.
if (defined('ELAAN_RECEIPT_FUNCTIONS_ONLY')) return;
$stage = 'bootstrap';
try {
    $base = getenv('LIVE_DIR');
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (getenv('RECEIPT_MODE') === 'prepare') {
        $stage = 'prepare';
        elaanReceiptPrepare();
        echo "ELAAN_SCHEMA_READY\n";
        exit(0);
    }
    $stage = 'marker';
    $marker = getenv('MARKER_FILE');
    if (! is_file($marker)) { echo "ELAAN_NO_MARKER\n"; exit(0); }
    $parts = explode('|', trim(file($marker)[0] ?? ''));
    if (! ctype_digit($parts[0] ?? '') || ! preg_match('/^[a-f0-9]{40}$/D', $parts[1] ?? '')) {
        echo "ELAAN_MARKER_PARSE_ERROR\n"; exit(0);
    }
    $stage = 'scope';
    $scope = base64_decode(getenv('SCOPE_B64'), true);
    if (! is_string($scope) || $scope === '') throw new RuntimeException('ELAAN_INVALID_SCOPE');
    $since = Carbon\CarbonImmutable::createFromTimestamp((int)$parts[0], config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    $sha = getenv('TARGET_SHA');
    $stage = 'fresh_query';
    $fresh = elaanReceiptCount($scope, $sha, $since);
    $stage = 'existing_query';
    $existing = elaanReceiptCount($scope, $sha, null);
    echo "ELAAN_COUNT=$fresh MARKER_COMMIT={$parts[1]} RECEIPT_COUNT=$existing\n";
} catch (Throwable $e) {
    echo elaanReceiptFailure($e, $stage)."\n";
    exit(1);
}
