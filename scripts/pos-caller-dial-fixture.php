<?php
/**
 * Task 1396 — dev-only fixture for the browser-level "Call back" check.
 *
 * scripts/pos-caller-dial-check.mjs drives a REAL browser against the dev
 * server; this script puts the dev POS shop into (and back out of) the state
 * that check needs. Caller ID is Unlimited-gated AND company-toggled, and the
 * dev shop has both OFF by default, so without this the sale screen never even
 * renders the button.
 *
 *   setup       plan gate + company toggle ON, one saved-customer missed call,
 *               one paired phone that CAN take a dial request.
 *   dial-ready  freshen the paired phone  -> dialBack answers sent=true.
 *   dial-dead   age the paired phone      -> dialBack answers the amber
 *               fallback card (reason=no_device). Ageing dial_seen_at (75s
 *               window) is the whole difference between the two paths.
 *   status      JSON snapshot; the check asserts the SERVER half against it
 *               (called_back_at stamped, dial request actually queued).
 *   teardown    restore every column this script touched, delete its rows.
 *
 * Original values are recorded in storage/app/pos-caller-dial-fixture.json, so
 * a crashed run is repaired by a plain `teardown` — the dev shop is never left
 * with Caller ID switched on.
 *
 * Usage (note the PG env-strip prefix — same as plan-gate-check.php):
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *       -u PGPASSWORD -u PGDATABASE php scripts/pos-caller-dial-fixture.php setup
 *
 * The shop is resolved from POS_CHECK_LOGIN / DEV_POS_LOGIN — the same login
 * pos-white-screen-check.sh uses, so both checks always target one shop.
 *
 * Exit codes: 0 = ok (JSON on stdout), 2 = could not run.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PricingPlan;
use App\Models\User;
use App\Services\PkPhone;
use App\Services\PlanLimitService;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const STATE_FILE = 'pos-caller-dial-fixture.json';
const DEVICE_LABEL = 'QA call-back fixture phone';
/** Mirrors PosCallerIdController::DIAL_READY_SECONDS. */
const DIAL_READY_SECONDS = 75;
/** Mirrors PosCallerIdController::OFFLINE_AFTER_MINUTES. */
const OFFLINE_AFTER_MINUTES = 360;

function bail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(2);
}

function out(array $payload): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

function statePath(): string
{
    return storage_path('app/' . STATE_FILE);
}

function readState(): ?array
{
    $p = statePath();
    if (!is_file($p)) {
        return null;
    }
    $raw = json_decode((string) file_get_contents($p), true);

    return is_array($raw) ? $raw : null;
}

function writeState(array $state): void
{
    file_put_contents(statePath(), json_encode($state, JSON_PRETTY_PRINT));
}

// ── Safety: must be the staging MySQL DB (never sqlite / Replit Postgres) ──
try {
    $driver = DB::connection()->getDriverName();
    if ($driver !== 'mysql') {
        bail("expected mysql connection, got '{$driver}' — run with the PG env-strip prefix.");
    }
    DB::connection()->getPdo();
} catch (\Throwable $e) {
    bail('cannot reach the staging MySQL DB (' . $e->getMessage() . ") — start the 'MySQL Staging' workflow and retry.");
}

foreach (['pos_caller_events', 'pos_caller_devices', 'pos_caller_dial_requests'] as $t) {
    if (!\Illuminate\Support\Facades\Schema::hasTable($t)) {
        bail("table {$t} is missing — run migrations first.");
    }
}

$cmd = $argv[1] ?? '';
$state = readState();

// ── Resolve the shop from the check's login ─────────────────────────────
$login = getenv('POS_CHECK_LOGIN') ?: getenv('DEV_POS_LOGIN') ?: '';
$company = null;
if ($login !== '') {
    $user = User::where('email', $login)->orWhere('phone', $login)->first();
    $company = $user ? Company::find($user->company_id) : null;
}
if (!$company && $state && !empty($state['company_id'])) {
    $company = Company::find($state['company_id']);  // teardown still works without creds
}
if (!$company) {
    bail('cannot resolve the dev POS shop — set POS_CHECK_LOGIN (or DEV_POS_LOGIN in .local/qa-creds.env).');
}
if (($company->product_type ?? null) !== 'pos') {
    bail("company {$company->id} is not a PRA POS shop (product_type={$company->product_type}).");
}

/** Live view of the fixture's paired phone + what the server would answer. */
function deviceSnapshot(int $companyId, ?int $deviceId): array
{
    $ready = DB::table('pos_caller_devices')
        ->where('company_id', $companyId)
        ->where('supports_dial', 1)
        ->where('dial_seen_at', '>', now()->subSeconds(DIAL_READY_SECONDS))
        ->count();
    $online = DB::table('pos_caller_devices')
        ->where('company_id', $companyId)
        ->where('last_seen_at', '>', now()->subMinutes(OFFLINE_AFTER_MINUTES))
        ->exists();
    $row = $deviceId ? DB::table('pos_caller_devices')->find($deviceId) : null;

    return [
        'device_id' => $deviceId,
        'supports_dial' => $row ? (int) $row->supports_dial : null,
        'dial_seen_at' => $row->dial_seen_at ?? null,
        'last_seen_at' => $row->last_seen_at ?? null,
        // What POST /pos/api/caller-dial will decide with this state.
        'dial_ready' => $ready > 0,
        'any_device_online' => $online,
        'expected_reason' => $ready > 0 ? null : ($online ? 'old_app' : 'no_device'),
    ];
}

function statusPayload(Company $company, ?array $state): array
{
    // The gate cache is per-request; this script flips the plan mid-process.
    PosFeatureService::flushGateCaches();
    $cid = (int) $company->id;
    $eventId = $state['event_id'] ?? null;
    $event = $eventId ? DB::table('pos_caller_events')->find($eventId) : null;
    $requests = DB::table('pos_caller_dial_requests')
        ->where('company_id', $cid)
        ->when($eventId, fn ($q) => $q->where('event_id', $eventId))
        ->orderByDesc('id')->limit(5)
        ->get(['id', 'status', 'phone', 'dial_digits', 'event_id', 'created_at'])
        ->map(fn ($r) => (array) $r)->all();

    return [
        'ok' => true,
        'company_id' => $cid,
        'caller_id_enabled' => (bool) $company->caller_id_enabled,
        'plan_allows_caller_id' => PosFeatureService::planAllows($company, 'caller_id_enabled'),
        'event_id' => $eventId,
        'event_phone' => $event->phone ?? null,
        'called_back_at' => $event->called_back_at ?? null,
        'dial_requests' => $requests,
    ] + deviceSnapshot($cid, $state['device_id'] ?? null);
}

// ─────────────────────────────────────────────────────────────────────────
switch ($cmd) {
    case 'setup':
        if ($state) {
            bail('a fixture is already active (' . statePath() . ') — run `teardown` first.');
        }
        $state = [
            'company_id' => (int) $company->id,
            'started_at' => now()->toDateTimeString(),
            'orig_caller_id_enabled' => (int) $company->caller_id_enabled,
            'subscription_id' => null,
            'orig_pricing_plan_id' => null,
            'customer_id' => null,
            'created_customer' => false,
            'event_id' => null,
            'device_id' => null,
        ];

        // 1. Plan gate. Caller ID is Unlimited-only, so borrow a POS plan that
        //    genuinely carries the column (never an internal-account bypass —
        //    the check must exercise the real gate the shops hit).
        if (!PosFeatureService::planAllows($company, 'caller_id_enabled')) {
            $sub = PlanLimitService::getActiveSubscription($company->id);
            if (!$sub) {
                bail("company {$company->id} has no active subscription — cannot grant the Caller ID plan gate.");
            }
            $plan = PricingPlan::where('product_type', 'pos')->where('caller_id_enabled', 1)->orderBy('id')->first();
            if (!$plan) {
                bail('no POS pricing plan carries caller_id_enabled — the gate matrix changed.');
            }
            $state['subscription_id'] = (int) $sub->id;
            $state['orig_pricing_plan_id'] = $sub->pricing_plan_id;
            DB::table('subscriptions')->where('id', $sub->id)->update(['pricing_plan_id' => $plan->id]);
        }

        // 2. Company toggle. DB::table so updated_at (the sale-screen boot
        //    fingerprint) never churns for a throwaway fixture.
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => 1]);

        // 3. A saved customer to ring in — that is what makes the click attach
        //    someone to the bill instead of a bare walk-in phone number.
        $customer = PosCustomer::where('company_id', $company->id)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->orderBy('id')->limit(50)->get()
            ->first(fn ($c) => (bool) PkPhone::normalize($c->phone));
        if (!$customer) {
            $customer = PosCustomer::create([
                'company_id' => $company->id,
                'name' => 'QA Call-back Fixture',
                'phone' => '03001234567',
            ]);
            $state['created_customer'] = true;
        }
        $state['customer_id'] = (int) $customer->id;
        $phone = PkPhone::normalize($customer->phone);

        // 4. The missed call. ring_at is deliberately OLD (> the 120s
        //    poll-freshness window) so the incoming-call popup never fires
        //    mid-test and covers the recent-calls panel.
        $state['event_id'] = (int) DB::table('pos_caller_events')->insertGetId([
            'company_id' => $company->id,
            'phone' => $phone,
            'caller_name' => $customer->name,
            'source' => 'sim',
            'ring_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'called_back_at' => null,
            'cleared_at' => null,
        ]);

        // 5. The paired counter phone, dial-ready.
        $state['device_id'] = (int) DB::table('pos_caller_devices')->insertGetId([
            'company_id' => $company->id,
            'user_id' => null,
            'device' => DEVICE_LABEL,
            'supports_dial' => 1,
            'token_hash' => hash('sha256', 'pos-caller-dial-fixture-' . Str::random(32)),
            'last_seen_at' => now(),
            'dial_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        writeState($state);
        $company->refresh();
        out(statusPayload($company, $state) + [
            'customer_id' => $state['customer_id'],
            'customer_name' => $customer->name,
            // Exactly what the sale screen will render for this call.
            'display_phone' => (strlen($phone) === 12 && str_starts_with($phone, '92'))
                ? substr('0' . substr($phone, 2), 0, 4) . '-' . substr('0' . substr($phone, 2), 4)
                : '+' . $phone,
        ]);

        // no break — out() exits

    case 'dial-ready':
    case 'dial-dead':
        if (!$state || empty($state['device_id'])) {
            bail('no active fixture — run `setup` first.');
        }
        $dead = $cmd === 'dial-dead';
        DB::table('pos_caller_devices')->where('id', $state['device_id'])->update([
            // dial-dead: both stamps age out, so the shop has NO dial-capable
            // phone at all (reason=no_device) rather than an old-app phone.
            'dial_seen_at' => $dead ? now()->subSeconds(DIAL_READY_SECONDS * 4) : now(),
            'last_seen_at' => $dead ? now()->subMinutes(OFFLINE_AFTER_MINUTES * 2) : now(),
            'updated_at' => now(),
        ]);
        // Fresh slate for the next click: no stale stamp, no stale request.
        DB::table('pos_caller_events')->where('id', $state['event_id'])
            ->update(['called_back_at' => null]);
        DB::table('pos_caller_dial_requests')
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $state['started_at'])
            ->delete();
        out(statusPayload($company, $state));

    case 'status':
        out(statusPayload($company, $state));

    case 'teardown':
        if (!$state) {
            out(['ok' => true, 'note' => 'no active fixture — nothing to restore']);
        }
        $cid = (int) $state['company_id'];
        DB::table('pos_caller_dial_requests')->where('company_id', $cid)
            ->where('created_at', '>=', $state['started_at'])->delete();
        if (!empty($state['event_id'])) {
            DB::table('pos_caller_events')->where('id', $state['event_id'])->delete();
        }
        if (!empty($state['device_id'])) {
            DB::table('pos_caller_devices')->where('id', $state['device_id'])->delete();
        }
        if (!empty($state['created_customer']) && !empty($state['customer_id'])) {
            PosCustomer::where('id', $state['customer_id'])->where('company_id', $cid)->delete();
        }
        if (!empty($state['subscription_id'])) {
            DB::table('subscriptions')->where('id', $state['subscription_id'])
                ->update(['pricing_plan_id' => $state['orig_pricing_plan_id']]);
        }
        DB::table('companies')->where('id', $cid)
            ->update(['caller_id_enabled' => (int) $state['orig_caller_id_enabled']]);
        @unlink(statePath());
        out(['ok' => true, 'restored' => true, 'company_id' => $cid]);

    default:
        bail("usage: php scripts/pos-caller-dial-fixture.php setup|dial-ready|dial-dead|status|teardown");
}
