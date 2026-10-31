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

// Include functions in isolated tests without opening any application/database.
if (defined('ELAAN_RECEIPT_FUNCTIONS_ONLY')) return;
try {
    $base = getenv('LIVE_DIR');
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (getenv('RECEIPT_MODE') === 'prepare') {
        elaanReceiptPrepare();
        echo "ELAAN_SCHEMA_READY\n";
        exit(0);
    }
    $marker = getenv('MARKER_FILE');
    if (! is_file($marker)) { echo "ELAAN_NO_MARKER\n"; exit(0); }
    $parts = explode('|', trim(file($marker)[0] ?? ''));
    if (! ctype_digit($parts[0] ?? '') || ! preg_match('/^[a-f0-9]{40}$/D', $parts[1] ?? '')) {
        echo "ELAAN_MARKER_PARSE_ERROR\n"; exit(0);
    }
    $scope = base64_decode(getenv('SCOPE_B64'), true);
    if (! is_string($scope) || $scope === '') throw new RuntimeException('ELAAN_INVALID_SCOPE');
    $since = Carbon\CarbonImmutable::createFromTimestamp((int)$parts[0], config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    $sha = getenv('TARGET_SHA');
    $fresh = elaanReceiptCount($scope, $sha, $since);
    $existing = elaanReceiptCount($scope, $sha, null);
    echo "ELAAN_COUNT=$fresh MARKER_COMMIT={$parts[1]} RECEIPT_COUNT=$existing\n";
} catch (Throwable $e) {
    $code = $e instanceof RuntimeException && preg_match('/^ELAAN_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'ELAAN_DB_ERROR';
    echo $code."\n";
    exit(1);
}
