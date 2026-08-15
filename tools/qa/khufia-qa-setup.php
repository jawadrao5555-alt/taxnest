<?php
/**
 * Khufia-key QA setup (Task 738) — idempotent.
 *
 * Ensures the three STANDING QA users on company 11 (NestPOS Enterprise Store)
 * used by the repeatable khufia-key browser check (tools/qa/khufia-key-check.md):
 *
 *   khufia.pra@taxnest.com      pos_cashier, billing scope 'pra'   (counterpart target)
 *   khufia.local@taxnest.com    pos_cashier, billing scope 'local', linked -> khufia.pra
 *   khufia.unlinked@taxnest.com pos_cashier, billing scope 'local', NO counterpart
 *
 * Password comes from KHUFIA_QA_PASS in the environment or .local/qa-creds.env
 * (NEVER hardcode it here — public repo). Existing users are repaired in place
 * (scope/link/active/role drift fixed, password re-set) so the check stays green
 * even if someone edited them via the Team page.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *       -u PGPASSWORD -u PGDATABASE php tools/qa/khufia-qa-setup.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const COMPANY_ID = 11;

$pass = getenv('KHUFIA_QA_PASS');
if (!$pass) {
    $credsFile = __DIR__.'/../../.local/qa-creds.env';
    if (is_file($credsFile)) {
        foreach (file($credsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^KHUFIA_QA_PASS=(.+)$/', trim($line), $m)) {
                $pass = $m[1];
                break;
            }
        }
    }
}
if (!$pass) {
    fwrite(STDERR, "FATAL: KHUFIA_QA_PASS not set (env or .local/qa-creds.env).\n");
    exit(1);
}

$company = \App\Models\Company::find(COMPANY_ID);
if (!$company) {
    fwrite(STDERR, 'FATAL: company '.COMPANY_ID." not found.\n");
    exit(1);
}

$ensure = function (string $email, string $name, string $scope, string $posRole = 'pos_cashier') use ($pass) {
    $u = \App\Models\User::where('email', $email)->first();
    if (!$u) {
        $u = new \App\Models\User();
        $u->email = $email;
    }
    $u->name = $name;
    $u->role = 'employee';
    $u->pos_role = $posRole;
    $u->company_id = COMPANY_ID;
    $u->is_active = true;
    $u->password = \Illuminate\Support\Facades\Hash::make($pass);
    $u->save();
    // Columns possibly outside $fillable — write directly + verify via fresh read.
    \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->update([
        'pos_billing_scope' => $scope,
        'pos_counterpart_user_id' => null, // links wired below
    ]);

    return $u->fresh();
};

$manager = $ensure('khufia.manager@taxnest.com', 'Khufia Manager QA', 'both', 'pos_manager');
$pra = $ensure('khufia.pra@taxnest.com', 'Khufia PRA QA', 'pra');
$local = $ensure('khufia.local@taxnest.com', 'Khufia Local QA', 'local');
$unlinked = $ensure('khufia.unlinked@taxnest.com', 'Khufia Unlinked QA', 'local');

\Illuminate\Support\Facades\DB::table('users')->where('id', $local->id)
    ->update(['pos_counterpart_user_id' => $pra->id]);

// ── Verify via fresh DB reads (Eloquent silent-drop guard) ──
$rows = \Illuminate\Support\Facades\DB::table('users')
    ->whereIn('id', [$manager->id, $pra->id, $local->id, $unlinked->id])
    ->get(['id', 'email', 'pos_role', 'pos_billing_scope', 'pos_counterpart_user_id', 'is_active', 'company_id']);
$ok = true;
foreach ($rows as $r) {
    printf(
        "%-28s id=%-6d role=%s scope=%-5s counterpart=%s active=%d company=%d\n",
        $r->email, $r->id, $r->pos_role, $r->pos_billing_scope,
        $r->pos_counterpart_user_id ?: '-', $r->is_active, $r->company_id
    );
}
$byEmail = $rows->keyBy('email');
$checks = [
    ['khufia.manager@taxnest.com', 'both', null, 'pos_manager'],
    ['khufia.pra@taxnest.com', 'pra', null, 'pos_cashier'],
    ['khufia.local@taxnest.com', 'local', $pra->id, 'pos_cashier'],
    ['khufia.unlinked@taxnest.com', 'local', null, 'pos_cashier'],
];
foreach ($checks as [$email, $scope, $cp, $role]) {
    $r = $byEmail[$email] ?? null;
    if (!$r || $r->pos_billing_scope !== $scope
        || (int) ($r->pos_counterpart_user_id ?? 0) !== (int) ($cp ?? 0)
        || !$r->is_active || (int) $r->company_id !== COMPANY_ID
        || $r->pos_role !== $role) {
        fwrite(STDERR, "MISMATCH: $email\n");
        $ok = false;
    }
}
echo $ok ? "OK: khufia QA users ready.\n" : "FAILED verification.\n";
exit($ok ? 0 : 1);
