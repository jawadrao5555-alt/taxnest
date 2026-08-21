<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosCustomer;
use App\Models\PosTransaction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PkPhone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Caller ID (Task 1039) — Android companion app + POS sale-screen popup.
 *
 * Two halves (rider-app pattern, PosRiderTrackingController):
 *  1. Stateless JSON API for the "TaxNest Caller ID" Android app
 *     (/api/caller-app/v1/*, bearer token; SHA-256 stored on the COMPANY row —
 *     one active device per shop). An admin/manager signs in with the normal
 *     portal login; login rotates the token.
 *  2. Sale-screen poll + settings toggle inside the /pos/* session group.
 *
 * Design notes:
 *  - Open to all POS plans for now (owner may Unlimited-gate later).
 *  - Ring events live in pos_caller_events, purged after ~2 days
 *    opportunistically (no cron dependency).
 *  - Client epoch-ms timestamps are converted with setTimezone(app TZ) —
 *    the rider-app 5h-early trap (offline-first-pos-billing).
 *  - Live PDO returns numeric columns as strings — cast ints in JSON.
 *  - Companies-row writes for telemetry go through DB::table so updated_at
 *    never churns (posConfigRev whitelist keeps the boot fingerprint safe,
 *    but heartbeats every ring are still not worth an Eloquent touch).
 */
class PosCallerIdController extends Controller
{
    private const EVENT_RETENTION_HOURS = 48;  // ring rows purged after this
    private const EVENT_FRESH_SECONDS = 120;   // poll never surfaces older rings
    private const DEDUPE_SECONDS = 20;         // same caller re-ring collapse
    // Task 1345 — DO builds, ek hi package id:
    //   default ("clean")  = sirf SIM calls, Play Protect ki blocked chaar
    //                        permissions mein se koi nahi → bina rukawat install
    //   plus               = SIM + WhatsApp (notification listener), install ke
    //                        liye Play Protect waqti tor par band karna parta hai
    private const APP_DOWNLOAD_URL = 'https://taxnest.com.pk/downloads/taxnest-caller.apk';
    private const APP_DOWNLOAD_URL_PLUS = 'https://taxnest.com.pk/downloads/taxnest-caller-plus.apk';
    private const DEVICE_CAP = 3;              // paired phones per shop (v2)
    // "Offline" = no ring/API contact for this long. The app has NO periodic
    // heartbeat (contacts only on rings + app-open /me), so keep this lenient
    // to avoid crying wolf on a quiet afternoon.
    public const OFFLINE_AFTER_MINUTES = 360;

    // ── Call back (Task 1381) ───────────────────────────────────────────────
    // Cashier POS par "Call back" dabata hai → paired counter-phone ek
    // tap-to-dial notification dikhata hai. Request durable queue se guzarti
    // hai (pos_caller_dial_requests), print-job claim pattern ki tarah.
    private const DIAL_EXPIRY_SECONDS = 120;  // is ke baad request khud khatam
    private const DIAL_READY_SECONDS = 75;    // itna taza poll = phone abhi le sakta hai
    private const DIAL_CLAIM_LIMIT = 3;       // ek poll mein zyada se zyada requests
    private const DIAL_POLL_MS = 5000;        // app ka poll waqfa (server se tunable)

    /** Device row (pos_caller_devices) the current bearer token matched, if any. */
    private ?object $authedDevice = null;

    // ─── Shared gates ───────────────────────────────────────────────────────

    /**
     * Resolve the company from the Bearer token or abort with JSON.
     * v2 (Task 1101): device rows first (multi-phone), then the LEGACY
     * companies-row token so an already-paired beta phone keeps working.
     */
    private function companyFromToken(Request $request): Company
    {
        $token = (string) $request->bearerToken();
        $companyId = (int) strtok($token, '|');
        $company = $companyId > 0 ? Company::find($companyId) : null;
        if (!$company) {
            abort(response()->json(['ok' => false, 'error' => 'unauthorized'], 401));
        }

        $hash = hash('sha256', $token);
        $this->authedDevice = null;
        if (Schema::hasTable('pos_caller_devices')) {
            $this->authedDevice = DB::table('pos_caller_devices')
                ->where('company_id', $company->id)
                ->where('token_hash', $hash)
                ->first();
        }
        if (!$this->authedDevice
            && !($company->caller_app_token
                && hash_equals((string) $company->caller_app_token, $hash))) {
            abort(response()->json(['ok' => false, 'error' => 'unauthorized'], 401));
        }
        $suspended = ($company->status ?? null) === 'suspended'
            || ($company->company_status ?? null) === 'suspended';
        if ($suspended) {
            abort(response()->json(['ok' => false, 'error' => 'unauthorized'], 401));
        }
        return $company;
    }

    /** Heartbeat: stamp last_seen on the matched device row (or legacy column). */
    private function touchDevice(Company $company): void
    {
        if ($this->authedDevice) {
            DB::table('pos_caller_devices')->where('id', $this->authedDevice->id)
                ->update(['last_seen_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('companies')->where('id', $company->id)
                ->update(['caller_app_last_seen_at' => now()]);
        }
    }

    /** Any paired phone (device row or legacy) contacted us recently? */
    public static function anyDeviceOnline(Company $company): bool
    {
        $cutoff = now()->subMinutes(self::OFFLINE_AFTER_MINUTES);
        if (($company->caller_app_last_seen_at ?? null)
            && Carbon::parse($company->caller_app_last_seen_at)->gt($cutoff)) {
            return true;
        }
        if (Schema::hasTable('pos_caller_devices')) {
            return DB::table('pos_caller_devices')
                ->where('company_id', $company->id)
                ->where('last_seen_at', '>', $cutoff)
                ->exists();
        }
        return false;
    }

    /**
     * Unlimited-package gate (owner, 17 Aug 2026): Caller ID is plan-locked
     * to Unlimited. planAllows keeps the usual escape hatches (internal
     * accounts, active overrides, active trials) and fails OPEN on schema lag.
     */
    private function planLocked(Company $company): bool
    {
        return !\App\Services\PosFeatureService::planAllows($company, 'caller_id_enabled');
    }

    /**
     * Task 1380: does this DB have the cleared_at flag yet? Cached 5 min like the
     * table-existence check (DDL never runs mid-session) so the 20-second poll
     * doesn't hit information_schema. Missing column (prod schema drift) simply
     * means "clearing unavailable" — the list stays read-only, nothing 500s.
     */
    private function clearSupported(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'caller_events_cleared_col',
            300,
            fn () => Schema::hasTable('pos_caller_events')
                && Schema::hasColumn('pos_caller_events', 'cleared_at')
        );
    }

    /**
     * Task 1381: is the call-back queue present in THIS database? Cached 5 min
     * like the other schema probes. Missing table (prod schema drift) simply
     * means "call back unavailable" — POS falls back to the copy-the-number
     * card, nothing 500s.
     */
    private function dialSupported(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'caller_dial_requests_ready',
            300,
            fn () => Schema::hasTable('pos_caller_dial_requests')
                && Schema::hasTable('pos_caller_devices')
                && Schema::hasColumn('pos_caller_devices', 'supports_dial')
                && Schema::hasColumn('pos_caller_devices', 'dial_seen_at')
        );
    }

    /** Task 1381: does pos_caller_events carry the "call back kiya" stamp yet? */
    private function calledBackSupported(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'caller_events_called_back_col',
            300,
            fn () => Schema::hasTable('pos_caller_events')
                && Schema::hasColumn('pos_caller_events', 'called_back_at')
        );
    }

    /**
     * Task 1397: bell par ka badge — AAJ ki woh rings jin par abhi tak kuch
     * nahi hua: na call back hui, na list se hatai gai. Rush mein "Haaliya
     * calls" list wohi cheez hai jo koi nahi kholta, is liye baqi ka number
     * bell par hi nazar aana chahiye.
     *
     * Yeh COUNT hamesha kisi MOJOOD response ke sath jata hai (ring poll,
     * recent-calls list, clear, call back) — badge ke liye alag request kabhi
     * nahi, warna har counter par ek aur poll chal parta.
     *
     * Ring poll par yeh isi liye afford hota hai ke EVENT_RETENTION_HOURS ka
     * purge har dukan ki rows 48 ghante par kaat deta hai — company_id index
     * ke peechay ginne ko dozen bhar rows hoti hain, hazaron nahi. Agar kabhi
     * retention barhe to yeh count dobara taulna hoga.
     *
     * "Aaj" = calendar din (ring_at >= aaj 00:00), app TZ mein. Panel 24
     * ghante dikhata hai, magar badge se poocha yeh ja raha hai ke AAJ kya
     * baqi reh gaya — raat 12 baje ginti saaf, kal ka bojh aaj par nahi.
     *
     * Schema drift par shart chhoot jati hai (500 nahi hota): cleared_at ya
     * called_back_at column na ho to us hisse ka filter lagta hi nahi.
     */
    private function pendingCallbacks(int $companyId): int
    {
        $tableExists = \Illuminate\Support\Facades\Cache::remember(
            'caller_events_table_exists',
            300,
            fn () => Schema::hasTable('pos_caller_events')
        );
        if (!$tableExists) {
            return 0;
        }

        return (int) DB::table('pos_caller_events')
            ->where('company_id', $companyId)
            ->where('ring_at', '>=', Carbon::today())
            ->when($this->clearSupported(), fn ($q) => $q->whereNull('cleared_at'))
            ->when($this->calledBackSupported(), fn ($q) => $q->whereNull('called_back_at'))
            ->count();
    }

    /**
     * Number the PHONE should dial. displayPhone() is for humans (0300-1234567);
     * the tel: URI wants clean digits — local form for PK numbers (shop ka SIM
     * PK ka hai), plain +international for anything else.
     */
    private function dialDigits(string $norm): string
    {
        if (str_starts_with($norm, '92') && strlen($norm) === 12) {
            return '0' . substr($norm, 2);
        }
        return '+' . $norm;
    }

    /**
     * Abhi ke paired phones ki haalat — sirf woh device jo call-back wali app
     * chala raha hai AUR abhi abhi poll kar chuka hai (last_seen_at ke 6 ghante
     * is faisle ke liye bohat dheele hain). dial_seen_at = "nai app ne poll
     * kiya"; supports_dial = "yeh phone abhi tap-to-dial DIKHA bhi sakta hai".
     *
     * Dono alag hain kyunke Android 13+ par POST_NOTIFICATIONS band ho to
     * notification chupke se ghayab ho jati hai — notify() koi error nahi deta.
     * Aisa phone poll to karta rahega magar cashier ko kuch nazar nahi aayega,
     * is liye woh 'blocked' hai: POS us par bharosa nahi karta aur number copy
     * karwa deta hai ("phone par notification band hai").
     *
     * @return array{ready:int,blocked:int}
     */
    private function dialDeviceState(int $companyId): array
    {
        if (!$this->dialSupported()) {
            return ['ready' => 0, 'blocked' => 0];
        }
        // Ek shop par device 1-3 hi hote hain — flags PHP mein ginna sab se
        // portable hai (sqlite + mysql par ek jaisa).
        $flags = DB::table('pos_caller_devices')
            ->where('company_id', $companyId)
            ->where('dial_seen_at', '>', now()->subSeconds(self::DIAL_READY_SECONDS))
            ->pluck('supports_dial');

        $ready = $flags->filter(fn ($f) => (int) $f === 1)->count();

        return ['ready' => $ready, 'blocked' => $flags->count() - $ready];
    }

    /** Kya abhi koi paired phone dial request LE (aur dikha) sakta hai? */
    private function dialReadyCount(int $companyId): int
    {
        return $this->dialDeviceState($companyId)['ready'];
    }

    // ─── Caller app API (stateless) ─────────────────────────────────────────

    /** POST /api/caller-app/v1/login {email, password, device?} */
    public function appLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:120',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user || !$user->is_active || !Hash::check($request->password, $user->password)) {
            return response()->json(['ok' => false, 'error' => 'invalid_credentials',
                'message' => __('pos.caller_bad_login')], 401);
        }
        // Only POS admins/managers may bind the shop's Caller ID phone.
        if (!$user->isPosAdmin()) {
            return response()->json(['ok' => false, 'error' => 'not_admin',
                'message' => __('pos.caller_admin_only')], 403);
        }

        $company = Company::find($user->company_id);
        $suspended = $company && (($company->status ?? null) === 'suspended'
            || ($company->company_status ?? null) === 'suspended');
        if (!$company || $suspended) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 403);
        }
        if (!Schema::hasColumn('companies', 'caller_app_token')) {
            return response()->json(['ok' => false, 'error' => 'server_not_ready'], 503);
        }
        // Unlimited gate: don't bind a device to a shop whose plan can't use it.
        if ($this->planLocked($company)) {
            return response()->json(['ok' => false, 'error' => 'plan_locked',
                'message' => __('pos.caller_plan_locked_api')], 403);
        }

        $plain = $company->id . '|' . Str::random(48);
        if (Schema::hasTable('pos_caller_devices')) {
            // v2 (Task 1101): each login pairs a NEW device row — the SIM phone
            // and the WhatsApp phone stay paired together. Small cap: at the
            // limit the LEAST-recently-seen device is bumped to make room.
            $count = DB::table('pos_caller_devices')->where('company_id', $company->id)->count();
            if ($count >= self::DEVICE_CAP) {
                $oldest = DB::table('pos_caller_devices')->where('company_id', $company->id)
                    ->orderByRaw('COALESCE(last_seen_at, created_at) asc')->orderBy('id')->first();
                if ($oldest) {
                    DB::table('pos_caller_devices')->where('id', $oldest->id)->delete();
                }
            }
            DB::table('pos_caller_devices')->insert([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'device' => (string) $request->input('device', ''),
                'token_hash' => hash('sha256', $plain),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Pre-migration window: legacy rotate (one active device).
            DB::table('companies')->where('id', $company->id)->update([
                'caller_app_token' => hash('sha256', $plain),
                'caller_app_user_id' => $user->id,
                'caller_app_device' => (string) $request->input('device', ''),
                'caller_app_last_seen_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'token' => $plain,
            'company' => $company->name ?? '',
            'user' => $user->name ?? '',
            'enabled' => (bool) ($company->caller_id_enabled ?? false),
        ]);
    }

    /** POST /api/caller-app/v1/ring {number?, name?, source, at?} */
    public function appRing(Request $request)
    {
        $company = $this->companyFromToken($request);

        $request->validate([
            'phone' => 'nullable|string|max:40',
            'number' => 'nullable|string|max:40',
            'name' => 'nullable|string|max:120',
            'source' => 'nullable|string|in:sim,whatsapp',
            'at' => 'nullable|numeric',
        ]);

        $this->touchDevice($company);

        if ($this->planLocked($company)) {
            // Plan downgraded after binding: keep the token, surface the lock.
            return response()->json(['ok' => true, 'accepted' => false, 'reason' => 'plan_locked']);
        }
        if (!($company->caller_id_enabled ?? false)) {
            // App keeps its token; popup feature is simply off right now.
            return response()->json(['ok' => true, 'accepted' => false, 'reason' => 'disabled']);
        }

        // App sends 'phone'; accept 'number' too (either key works).
        $phone = PkPhone::normalize($request->input('phone') ?? $request->input('number'));
        $name = trim((string) $request->input('name', ''));
        $name = $name !== '' ? mb_substr($name, 0, 120) : null;
        if (!$phone && !$name) {
            return response()->json(['ok' => false, 'error' => 'empty'], 422);
        }

        // Ring time: client epoch ms → app TZ (rider trap). Reject stale (>10 min).
        $ringAt = now();
        if ($request->filled('at')) {
            try {
                $candidate = Carbon::createFromTimestampMs((float) $request->input('at'))
                    ->setTimezone(config('app.timezone'));
                if ($candidate->gt(now()->subMinutes(10)) && $candidate->lt(now()->addMinutes(5))) {
                    $ringAt = $candidate;
                }
            } catch (\Throwable $e) {
                // keep now()
            }
        }

        // Dedupe: same caller within DEDUPE_SECONDS = one event (WhatsApp posts
        // several notification updates per ring; SIM ring re-posts too).
        $dupe = DB::table('pos_caller_events')
            ->where('company_id', $company->id)
            ->where('created_at', '>=', now()->subSeconds(self::DEDUPE_SECONDS))
            ->when($phone, fn ($q) => $q->where('phone', $phone))
            ->when(!$phone, fn ($q) => $q->whereNull('phone')->where('caller_name', $name))
            ->exists();
        if ($dupe) {
            return response()->json(['ok' => true, 'accepted' => false, 'reason' => 'duplicate']);
        }

        DB::table('pos_caller_events')->insert([
            'company_id' => $company->id,
            'phone' => $phone,
            'caller_name' => $name,
            'source' => $request->input('source') === 'whatsapp' ? 'whatsapp' : 'sim',
            'ring_at' => $ringAt,
            'created_at' => now(),
        ]);

        // Opportunistic purge (no cron dependency) — 1-in-10 lottery.
        if (random_int(1, 10) === 1) {
            DB::table('pos_caller_events')
                ->where('created_at', '<', now()->subHours(self::EVENT_RETENTION_HOURS))
                ->limit(500)->delete();
        }

        return response()->json(['ok' => true, 'accepted' => true]);
    }

    /** GET /api/caller-app/v1/me — status for the app's main screen. */
    public function appMe(Request $request)
    {
        $company = $this->companyFromToken($request);
        $this->touchDevice($company);

        $lastEvent = DB::table('pos_caller_events')
            ->where('company_id', $company->id)->orderByDesc('id')->first();

        $planLocked = $this->planLocked($company);
        return response()->json([
            'ok' => true,
            'company' => $company->name ?? '',
            'user' => optional(User::find($company->caller_app_user_id))->name ?? '',
            'enabled' => (bool) ($company->caller_id_enabled ?? false) && !$planLocked,
            'plan_locked' => $planLocked,
            'last_event_at' => $lastEvent ? Carbon::parse($lastEvent->created_at)->format('d M Y, h:i A') : null,
        ]);
    }

    /**
     * GET /api/caller-app/v1/dial-requests — Task 1381, paired phone ka poll.
     *
     * Do kaam ek saath:
     *  1. Device ki capability stamp karta hai. SIRF nai app yeh endpoint
     *     maarti hai, is liye poll hi "app nai hai" ka sab se sacha signal hai
     *     (dial_seen_at). Sath hi phone khud batata hai ke us par notification
     *     dikh bhi sakti hai ya nahi (`notif=1|0`) — Android 13+ par permission
     *     na ho to notify() khamoshi se ghayab ho jata hai, koi error nahi
     *     aata. Woh flag supports_dial mein jata hai, aur POS isi par faisla
     *     karta hai ke request bheji jaye ya number copy karwaya jaye.
     *     `notif` bheja hi na gaya ho to hum capability MAAN nahi lete —
     *     jhoota "bhej diya" cashier ko dead end par le jata hai.
     *  2. Pending requests ko atomically claim karta hai (do-qadam claim_token,
     *     print-job pattern) taake do phone ek hi request par call na lagayen.
     *
     * Expired requests deliver NAHI hotin — purani request phone par der se
     * pohanch kar random call na laga de.
     */
    public function appDialRequests(Request $request)
    {
        $company = $this->companyFromToken($request);
        $this->touchDevice($company);

        $pollMs = self::DIAL_POLL_MS;
        if (!$this->dialSupported()) {
            // Schema drift / purana server: app khamoshi se poll karti rahe.
            return response()->json(['ok' => true, 'requests' => [], 'poll_ms' => 30000]);
        }

        // Capability + fresh heartbeat. Legacy (companies-row) token wale phone
        // ka koi device row nahi hota — un ke liye call back support nahi
        // (POS "app purani hai" kehta hai), is liye stamp bhi nahi.
        $deviceId = $this->authedDevice->id ?? null;
        if ($deviceId) {
            DB::table('pos_caller_devices')->where('id', $deviceId)->update([
                // Poll = app nai hai; notif = us par offer DIKH bhi sakta hai.
                'supports_dial' => $request->boolean('notif'),
                'dial_seen_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($this->planLocked($company) || !($company->caller_id_enabled ?? false)) {
            return response()->json(['ok' => true, 'requests' => [], 'poll_ms' => 30000]);
        }
        if (!$deviceId) {
            return response()->json(['ok' => true, 'requests' => [], 'poll_ms' => 30000]);
        }

        // Expire stale pendings (koi cron nahi — jo poll kare wohi safai kare).
        DB::table('pos_caller_dial_requests')
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        // Do-qadam claim: ids chuno → status guard ke saath token stamp karo →
        // token se parho. UPDATE ... LIMIT se bacha gaya hai (sqlite portable).
        $ids = DB::table('pos_caller_dial_requests')
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->limit(self::DIAL_CLAIM_LIMIT)
            ->pluck('id');

        $out = [];
        if ($ids->isNotEmpty()) {
            $claimToken = Str::random(32);
            DB::table('pos_caller_dial_requests')
                ->whereIn('id', $ids)
                ->where('status', 'pending')          // race guard: ek row, ek phone
                ->update([
                    'status' => 'delivered',
                    'claim_token' => $claimToken,
                    'device_id' => $deviceId,
                    'delivered_at' => now(),
                    'updated_at' => now(),
                ]);

            $rows = DB::table('pos_caller_dial_requests')
                ->where('company_id', $company->id)
                ->where('claim_token', $claimToken)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int) $row->id,
                    'dial' => (string) $row->dial_digits,
                    'display' => $this->displayPhone($row->phone) ?: (string) $row->dial_digits,
                    'name' => $row->caller_name,
                    // App apni taraf se bhi budhi request giraati hai (phone ki
                    // ghari server se thodi alag ho sakti hai, is liye seconds).
                    // Whole seconds: the app reads this as an int (optInt) and
                    // a fractional value would just be truncated anyway.
                    'expires_in' => max(0, (int) floor(now()->diffInSeconds(Carbon::parse($row->expires_at), false))),
                ];
            }

            // Opportunistic purge — queue rows ka koi cron nahi.
            if (random_int(1, 10) === 1) {
                DB::table('pos_caller_dial_requests')
                    ->where('created_at', '<', now()->subHours(self::EVENT_RETENTION_HOURS))
                    ->limit(500)->delete();
            }
        }

        return response()->json(['ok' => true, 'requests' => $out, 'poll_ms' => $pollMs]);
    }

    /**
     * POST /api/caller-app/v1/dial-result {id, status: dialed|failed, error?}
     * Task 1381 — phone batata hai ke notification par tap hua (dialer khul
     * gaya) ya request nakaam rahi. Sirf usi shop ki, usi device ko di gai row
     * badalti hai.
     */
    public function appDialResult(Request $request)
    {
        $company = $this->companyFromToken($request);
        $this->touchDevice($company);

        $request->validate([
            'id' => 'required|integer',
            'status' => 'required|string|in:dialed,failed',
            'error' => 'nullable|string|max:190',
        ]);

        if (!$this->dialSupported()) {
            return response()->json(['ok' => true, 'updated' => 0]);
        }

        // Row usi device ki hai jis ne claim ki thi. Sirf company scoping kaafi
        // nahi: ek hi shop ke do phone paired ho sakte hain, aur doosre phone ko
        // yeh haq nahi ke pehle ki request "dialed" likh de. Legacy token (koi
        // device row nahi) kabhi claim kar hi nahi sakta — us ke liye 0.
        $deviceId = $this->authedDevice->id ?? null;
        if (!$deviceId) {
            return response()->json(['ok' => true, 'updated' => 0]);
        }

        $status = $request->input('status');
        $updated = (int) DB::table('pos_caller_dial_requests')
            ->where('company_id', $company->id)
            ->where('id', (int) $request->input('id'))
            ->where('device_id', $deviceId)
            ->where('status', 'delivered')
            ->update([
                'status' => $status,
                'dialed_at' => $status === 'dialed' ? now() : null,
                'error' => $status === 'failed' ? mb_substr((string) $request->input('error', ''), 0, 190) : null,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /** GET /api/caller-app/v1/version — semver update check (SystemSetting-driven). */
    public function appVersion(Request $request)
    {
        // PER-BUILD update check (Task 1345). Har build ka apna version record
        // aur apna APK — warna plus (WhatsApp wale) phone par clean build ka
        // update chala jata aur WhatsApp detection chupke se khatam ho jati.
        //
        // Legacy phones (v1.0.0) koi ?build nahi bhejte — woh SAB notification
        // listener wali build hain, is liye param na ho to PLUS.
        $build = $request->query('build') === 'sim' ? 'sim' : 'plus';

        return response()->json([
            'ok' => true,
            'build' => $build,
            'latest' => trim((string) SystemSetting::get(
                $build === 'sim' ? 'caller_app_latest_version' : 'caller_app_plus_latest_version',
                ''
            )),
            'apk_url' => $build === 'sim' ? self::APP_DOWNLOAD_URL : self::APP_DOWNLOAD_URL_PLUS,
        ]);
    }

    /** POST /api/caller-app/v1/logout */
    public function appLogout(Request $request)
    {
        $company = $this->companyFromToken($request);
        if ($this->authedDevice) {
            // v2: unpair ONLY this phone — the other paired phone keeps working.
            DB::table('pos_caller_devices')->where('id', $this->authedDevice->id)->delete();
        } else {
            DB::table('companies')->where('id', $company->id)->update([
                'caller_app_token' => null,
                'caller_app_user_id' => null,
                'caller_app_device' => null,
            ]);
        }
        return response()->json(['ok' => true]);
    }

    // ─── POS panel (session, /pos/* AND /fbr-pos/*) ─────────────────────────

    /**
     * Task 1353: the same endpoints now serve BOTH sale screens. Every panel
     * method resolves the company from app('currentCompanyId') — bound by
     * PosAuth *and* FbrPosAuth — so the only guard-specific bit is the signed-in
     * user. Resolve it from whichever panel guard owns this request instead of
     * hardcoding auth('pos'), or an FBR admin's toggle/revoke/dial silently
     * looks like a logged-out cashier (403 / requested_by NULL).
     */
    private function panelUser()
    {
        return auth('pos')->user() ?: auth('fbrpos')->user();
    }

    /** POST /pos/settings/caller-id (+ FBR twin) {enabled} — admin-only toggle. */
    public function toggle(Request $request)
    {
        // Company-wide integration switch = admin/manager ONLY. A cashier with
        // custom 'customize' access passes posCashierBlocked(), so enforce the
        // same isPosAdmin() boundary the app-login uses.
        $user = $this->panelUser();
        if (!$user || !$user->isPosAdmin() || $user->posCashierBlocked()) {
            return response()->json(['ok' => false], 403);
        }
        if (!Schema::hasColumn('companies', 'caller_id_enabled')) {
            return response()->json(['ok' => false, 'error' => 'server_not_ready'], 503);
        }
        $companyId = app('currentCompanyId');
        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);
        // Unlimited gate: turning ON needs the plan; turning OFF is always allowed.
        if ($enabled && $this->planLocked(Company::find($companyId))) {
            return response()->json(['ok' => false, 'error' => 'plan_locked',
                'message' => __('pos.caller_plan_locked_api')], 403);
        }
        // Eloquent update on purpose: caller_id_enabled is in the posConfigRev
        // whitelist, and updated_at must bump so cached sale screens refresh.
        Company::where('id', $companyId)->update(['caller_id_enabled' => $enabled]);
        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    /**
     * GET /pos/api/caller-events?after={id} — sale-screen popup poll.
     * Returns only FRESH rings (≤ 2 min) newer than the client's last seen id,
     * each enriched with the matched-customer stats.
     */
    public function events(Request $request)
    {
        $companyId = app('currentCompanyId');

        // ── Task 1097: cheap early-exit ───────────────────────────────────────
        // Avoid a full Company::find (all columns) and a Schema::hasTable
        // (information_schema query) on every 20-second poll.
        //
        // 1. Read only the one flag we need with a scalar query.
        // 2. Cache the table-existence check for 5 minutes — DDL never runs
        //    mid-session, so stale reads here are harmless.
        $enabled = (bool) DB::table('companies')
            ->where('id', $companyId)
            ->value('caller_id_enabled');

        if (!$enabled) {
            // Task 1397: sarahatan sifar — plan/toggle band ho jaye to bell ka
            // badge apni purani ginti par jama na reh jaye.
            return response()->json(['enabled' => false, 'events' => [], 'last_id' => 0, 'pending' => 0]);
        }

        $tableExists = \Illuminate\Support\Facades\Cache::remember(
            'caller_events_table_exists',
            300,
            fn () => Schema::hasTable('pos_caller_events')
        );
        if (!$tableExists) {
            return response()->json(['enabled' => false, 'events' => [], 'last_id' => 0, 'pending' => 0]);
        }

        // planLocked needs the full Company model — only load it when the flag is ON.
        $company = Company::find($companyId);
        if (!$company || $this->planLocked($company)) {
            return response()->json(['enabled' => false, 'events' => [], 'last_id' => 0, 'pending' => 0]);
        }

        $after = (int) $request->query('after', 0);
        $rows = DB::table('pos_caller_events')
            ->where('company_id', $companyId)
            ->where('id', '>', $after)
            ->where('created_at', '>=', now()->subSeconds(self::EVENT_FRESH_SECONDS))
            // Task 1380: a ring cleared on one counter must not pop on another.
            ->when($this->clearSupported(), fn ($q) => $q->whereNull('cleared_at'))
            ->orderBy('id')
            ->limit(5)
            ->get();

        // Cursor semantics: when rows were delivered, advance only through the
        // DELIVERED ids — a burst of >5 fresh rings must surface on the next
        // poll, never be skipped. Only when nothing fresh is pending may the
        // cursor jump past stale rows (so old unseen ids aren't re-scanned).
        //
        // Task 1097: scope the cursor-advance query to id > $after so it only
        // scans events the client hasn't seen yet.  This is both more efficient
        // than the original company-wide MAX scan AND correctly advances past any
        // stale (expired) events that arrived after the client's last cursor.
        // When no newer event exists the query returns null → $after is used,
        // which naturally handles both the ($after == 0, empty table) and
        // ($after > 0, nothing new) cases without a separate branch.
        if ($rows->isNotEmpty()) {
            $lastId = (int) $rows->max('id');
        } else {
            $lastId = (int) (DB::table('pos_caller_events')
                ->where('company_id', $companyId)
                ->where('id', '>', $after)
                ->max('id') ?? $after);
        }

        $events = $rows->map(function ($row) use ($companyId) {
            return [
                'id' => (int) $row->id,
                'phone' => $this->displayPhone($row->phone),
                'name' => $row->caller_name,
                'source' => $row->source,
                'at' => Carbon::parse($row->ring_at)->format('h:i A'),
                'match' => $this->matchCustomer($companyId, $row->phone, $row->caller_name),
            ];
        })->values();

        return response()->json([
            'enabled' => true,
            'last_id' => max($lastId, $after),
            'events' => $events,
            // v2: offline warning — has ANY paired phone contacted us recently?
            'online' => self::anyDeviceOnline($company),
            // Task 1397: bell ka badge har tick par SERVER se set hota hai, na
            // ke counter ki apni ginti se. Isi se teen soortein khud theek
            // rehti hain: doosray counter ne call back kar li, screen aadhi
            // raat paar kar gai, ya tab kuch der chupi rahi.
            'pending' => $this->pendingCallbacks($companyId),
        ]);
    }

    /**
     * GET /pos/api/caller-recent — missed/recent calls list (last 24h).
     * Same gating as events. Newest first, deduped display comes from the
     * DEDUPE_SECONDS collapse at ingest. Unseen counting is CLIENT-side
     * (localStorage cursor), so this endpoint is read-only.
     * Task 1380: calls the shop has cleared are skipped (shop-wide flag).
     */
    public function recentCalls(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company || !($company->caller_id_enabled ?? false)
            || $this->planLocked($company)
            || !Schema::hasTable('pos_caller_events')) {
            // Task 1397: sarahatan sifar — plan/toggle band ho jaye to bell ka
            // badge apni purani ginti par jama na reh jaye.
            return response()->json(['enabled' => false, 'calls' => [], 'pending' => 0]);
        }

        $rows = DB::table('pos_caller_events')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', now()->subHours(24))
            ->when($this->clearSupported(), fn ($q) => $q->whereNull('cleared_at'))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $calls = $rows->map(function ($row) use ($companyId) {
            // Task 1381: "call back kiya" ka nishan — missed vs handled ka farq.
            $backAt = $row->called_back_at ?? null;
            return [
                'id' => (int) $row->id,
                'phone' => $this->displayPhone($row->phone),
                'name' => $row->caller_name,
                'source' => $row->source,
                'at' => Carbon::parse($row->ring_at)->format('h:i A'),
                'called_back' => (bool) $backAt,
                'called_back_at' => $backAt ? Carbon::parse($backAt)->format('h:i A') : null,
                'match' => $this->matchCustomer($companyId, $row->phone, $row->caller_name),
            ];
        })->values();

        return response()->json([
            'enabled' => true,
            'calls' => $calls,
            // Task 1381: kya abhi koi phone call-back request le sakta hai?
            // (POS button phir bhi dikhta hai — nakaami par number + copy.)
            'dial_ready' => $this->dialReadyCount($companyId) > 0,
            // Task 1397: bell ka badge — AAJ ki kitni calls abhi baqi hain.
            // Usi response mein, taake badge ke liye doosri request na ho.
            'pending' => $this->pendingCallbacks($companyId),
        ]);
    }

    /**
     * POST /pos/api/caller-clear {id?: int, all?: bool} — Task 1380.
     *
     * Handle ho chuki (ya test) call ko "Haaliya calls" list se hata deta hai.
     * SHOP-wide flag: refresh ke baad bhi, aur usi shop ke doosre counter par
     * bhi wapas nahi aati. Rows delete NAHI hotin — ring retention purge hi
     * unka malik hai — sirf cleared_at stamp hota hai, is liye nayi ring aane
     * ka poll/cursor bilkul pehle jaisa chalta rehta hai.
     *
     * Boundary: koi bhi signed-in POS user (call counter par cashier hi handle
     * karta hai) — yeh sale-screen ka rozana ka kaam hai, settings ka nahi.
     */
    public function clearCalls(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company || !($company->caller_id_enabled ?? false)
            || $this->planLocked($company)
            || !Schema::hasTable('pos_caller_events')) {
            return response()->json(['ok' => false, 'error' => 'disabled'], 403);
        }
        if (!$this->clearSupported()) {
            // Schema drift: column abhi nahi hai — 500 ke bajaye saaf jawab.
            return response()->json(['ok' => false, 'error' => 'unsupported']);
        }

        $all = $request->boolean('all');
        $id = (int) $request->input('id', 0);
        if (!$all && $id <= 0) {
            return response()->json(['ok' => false, 'error' => 'empty'], 422);
        }

        $query = DB::table('pos_caller_events')
            ->where('company_id', $companyId)
            ->whereNull('cleared_at');
        if (!$all) {
            $query->where('id', $id);
        }
        $cleared = (int) $query->update(['cleared_at' => now()]);

        // Task 1397: badge foran theek — hatai gai call baqi mein nahi ginti.
        return response()->json([
            'ok' => true,
            'cleared' => $cleared,
            'pending' => $this->pendingCallbacks($companyId),
        ]);
    }

    /**
     * POST /pos/api/caller-dial {phone, event_id?, name?} — Task 1381.
     *
     * Counter ka phone hi call laga sakta hai: POS yahan ek dial request
     * queue karta hai, paired phone use uthata hai aur tap-to-dial
     * notification dikhata hai. Auto-dial kabhi nahi — phone par ek tap
     * hamesha zaroori hai.
     *
     * Jawab chaar shakal ka:
     *   sent=true               → phone par bhej diya
     *   sent=false, no_device   → koi phone paired/online nahi
     *   sent=false, old_app     → phone jura hua hai magar app purani hai
     *   sent=false, notif_off   → nai app hai magar us par notification band
     *                             hai, yani offer dikhega hi nahi
     * Dono nakaam suraton mein number wapas jata hai taake POS bara kar ke
     * copy button ke saath dikha de — cashier ka rasta band na ho.
     *
     * Boundary: koi bhi signed-in POS user (cashier hi counter par call
     * handle karta hai), bilkul clearCalls ki tarah. Company scoping har
     * query par — doosri shop ka number na dikhe, na jaye.
     */
    public function dialBack(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company || !($company->caller_id_enabled ?? false) || $this->planLocked($company)) {
            return response()->json(['ok' => false, 'error' => 'disabled'], 403);
        }

        $request->validate([
            'phone' => 'required|string|max:40',
            'event_id' => 'nullable|integer',
            'name' => 'nullable|string|max:120',
        ]);

        $phone = PkPhone::normalize($request->input('phone'));
        if (!$phone) {
            return response()->json(['ok' => false, 'error' => 'bad_phone'], 422);
        }
        $display = $this->displayPhone($phone) ?: $phone;
        $digits = $this->dialDigits($phone);
        $name = trim((string) $request->input('name', ''));
        $name = $name !== '' ? mb_substr($name, 0, 120) : null;

        // "Call back kiya" ka nishan: cashier ne is call par amal kar liya —
        // phone se jaye ya cashier khud mobile se milaye, list mein handled
        // dikhni chahiye. Sirf isi shop ki row (company scoping).
        $eventId = (int) $request->input('event_id', 0);
        $calledBackAt = now();
        if ($eventId > 0 && $this->calledBackSupported()) {
            DB::table('pos_caller_events')
                ->where('company_id', $companyId)
                ->where('id', $eventId)
                ->update(['called_back_at' => $calledBackAt]);
        }

        // Task 1397: stamp ke BAAD ginti — call back karte hi bell ka badge
        // gir jata hai, chahe request phone tak pohanchi ho ya nahi.
        $pending = $this->pendingCallbacks($companyId);

        $fallback = [
            'ok' => true,
            'sent' => false,
            'phone' => $display,
            'dial' => $digits,
            'called_back_at' => $calledBackAt->format('h:i A'),
            'pending' => $pending,
        ];

        if (!$this->dialSupported()) {
            return response()->json($fallback + ['reason' => 'no_device']);
        }
        $state = $this->dialDeviceState($companyId);
        if ($state['ready'] < 1) {
            // Tarteeb ahem hai: nai app magar notification band (blocked) ki
            // baat purani-app se zyada khaas hai — cashier ko theek wajah aur
            // theek hal batana hai.
            $reason = $state['blocked'] > 0
                ? 'notif_off'
                : (self::anyDeviceOnline($company) ? 'old_app' : 'no_device');
            return response()->json($fallback + ['reason' => $reason]);
        }

        // Ek waqt mein ek hi zinda request: nai request aate hi purani pending
        // expire. Warna der se jaagne wala phone qatar mein pari purani request
        // par ghalat call laga deta.
        DB::table('pos_caller_dial_requests')
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $id = (int) DB::table('pos_caller_dial_requests')->insertGetId([
            'company_id' => $companyId,
            'event_id' => $eventId > 0 ? $eventId : null,
            'phone' => $phone,
            'dial_digits' => $digits,
            'caller_name' => $name,
            'status' => 'pending',
            'requested_by' => optional($this->panelUser())->id,
            'expires_at' => now()->addSeconds(self::DIAL_EXPIRY_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'sent' => true,
            'id' => $id,
            'phone' => $display,
            'dial' => $digits,
            'called_back_at' => $calledBackAt->format('h:i A'),
            'pending' => $pending,
        ]);
    }

    /**
     * GET /pos/api/caller-last-order?customer_id=&phone= — repeat-order source.
     * Returns the caller's LAST completed (non-return) bill's product/service
     * lines as {item_type, item_id, name, quantity}. The CLIENT re-prices from
     * its baked catalog (current prices); deal/manual lines are reported as
     * skipped names — deals are billing-flow-only (server-enforced price +
     * snapshot, pos-deals-feature) and manual lines have no product identity.
     */
    public function lastOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company || !($company->caller_id_enabled ?? false) || $this->planLocked($company)) {
            return response()->json(['ok' => false, 'error' => 'disabled'], 403);
        }

        $customerId = (int) $request->query('customer_id', 0);
        $phone = PkPhone::normalize($request->query('phone'));
        $variants = $this->phoneVariants($phone);
        if ($customerId <= 0 && !$variants) {
            return response()->json(['ok' => false, 'error' => 'empty'], 422);
        }

        $q = PosTransaction::where('company_id', $companyId)->where('status', 'completed');
        if (Schema::hasColumn('pos_transactions', 'transaction_type')) {
            $q->where(function ($w) {
                $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
            });
        }
        $q->where(function ($w) use ($customerId, $variants) {
            if ($customerId > 0) {
                $w->orWhere('customer_id', $customerId);
            }
            if ($variants) {
                $w->orWhereIn('customer_phone', $variants);
            }
        });
        $last = $q->orderByDesc('id')->first();
        if (!$last) {
            return response()->json(['ok' => true, 'items' => [], 'skipped' => []]);
        }

        $items = [];
        $skipped = [];
        $lines = DB::table('pos_transaction_items')->where('transaction_id', $last->id)->get();
        foreach ($lines as $line) {
            $type = (string) ($line->item_type ?? 'product');
            if (in_array($type, ['product', 'service'], true) && ($line->item_id ?? null)) {
                $items[] = [
                    'item_type' => $type,
                    'item_id' => (int) $line->item_id,      // live-pdo-string-ints
                    'name' => (string) $line->item_name,
                    'quantity' => (float) $line->quantity,
                ];
            } else {
                $skipped[] = (string) $line->item_name;
            }
        }

        return response()->json([
            'ok' => true,
            'bill_at' => Carbon::parse($last->created_at)->format('d M, h:i A'),
            'items' => $items,
            'skipped' => $skipped,
        ]);
    }

    /**
     * POST /pos/settings/caller-devices/revoke {device_id: int | 'legacy'} —
     * admin-only, same boundary as toggle(). Unpairs ONE phone.
     */
    public function revokeDevice(Request $request)
    {
        $user = $this->panelUser();
        if (!$user || !$user->isPosAdmin() || $user->posCashierBlocked()) {
            return response()->json(['ok' => false], 403);
        }
        $companyId = app('currentCompanyId');
        $deviceId = $request->input('device_id');

        if ($deviceId === 'legacy') {
            DB::table('companies')->where('id', $companyId)->update([
                'caller_app_token' => null,
                'caller_app_user_id' => null,
                'caller_app_device' => null,
            ]);
            return response()->json(['ok' => true]);
        }
        if (Schema::hasTable('pos_caller_devices')) {
            DB::table('pos_caller_devices')
                ->where('company_id', $companyId)
                ->where('id', (int) $deviceId)
                ->delete();
        }
        return response()->json(['ok' => true]);
    }

    // ─── Customer matching ──────────────────────────────────────────────────

    /** 923001234567 → 0300-1234567 for the popup; passthrough otherwise. */
    private function displayPhone(?string $norm): ?string
    {
        if (!$norm) {
            return null;
        }
        if (str_starts_with($norm, '92') && strlen($norm) === 12) {
            $local = '0' . substr($norm, 2);
            return substr($local, 0, 4) . '-' . substr($local, 4);
        }
        return '+' . $norm;
    }

    /**
     * All raw formats a shop may have stored for a normalized number —
     * pos_customers.phone / pos_transactions.customer_phone are RAW user input
     * (PkPhone is not applied on save), so match by variant expansion.
     */
    private function phoneVariants(?string $norm): array
    {
        if (!$norm) {
            return [];
        }
        $v = [$norm, '+' . $norm, '00' . $norm];
        if (str_starts_with($norm, '92') && strlen($norm) === 12) {
            $local = '0' . substr($norm, 2);   // 03001234567
            $bare = substr($norm, 2);          // 3001234567
            $v[] = $local;
            $v[] = $bare;
            $v[] = substr($local, 0, 4) . '-' . substr($local, 4);   // 0300-1234567
            $v[] = substr($local, 0, 4) . ' ' . substr($local, 4);   // 0300 1234567
            $v[] = '+92 ' . substr($norm, 2, 3) . ' ' . substr($norm, 5); // +92 300 1234567
            $v[] = '92 ' . substr($norm, 2);   // 92 3001234567
        }
        return array_values(array_unique($v));
    }

    /**
     * Match a ring against pos_customers + bill history.
     * Phone first (variants, then normalize-compare fallback); WhatsApp
     * saved-contact rings carry only a NAME → unique-name match, flagged
     * matched_by='name' so the popup says so.
     */
    private function matchCustomer(int $companyId, ?string $phone, ?string $name): ?array
    {
        $variants = $this->phoneVariants($phone);
        $customer = null;
        $matchedBy = null;

        if ($variants) {
            $customer = PosCustomer::where('company_id', $companyId)
                ->whereIn('phone', $variants)->orderByDesc('id')->first();
            if (!$customer && strlen($phone) >= 9) {
                // Fallback: same last-7-digits candidates, normalize-compare in PHP
                // (covers stored oddballs like "0300 123 4567").
                $last7 = substr($phone, -7);
                $candidates = PosCustomer::where('company_id', $companyId)
                    ->whereNotNull('phone')->where('phone', 'like', '%' . $last7 . '%')
                    ->limit(25)->get();
                $customer = $candidates->first(fn ($c) => PkPhone::normalize($c->phone) === $phone);
            }
            if ($customer) {
                $matchedBy = 'phone';
            }
        }

        if (!$customer && !$variants && $name) {
            // Name-only (WhatsApp saved contact): only a UNIQUE exact match counts.
            $named = PosCustomer::where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->limit(2)->get();
            if ($named->count() === 1) {
                $customer = $named->first();
                $matchedBy = 'name';
            }
        }

        // Bill history: by customer_id when known, else by raw phone variants
        // (walk-in bills store the phone without a customer row).
        $txn = PosTransaction::where('company_id', $companyId)->where('status', 'completed');
        $hasIdentity = false;
        $txn->where(function ($q) use ($customer, $variants, &$hasIdentity) {
            if ($customer) {
                $q->orWhere('customer_id', $customer->id);
                if ($customer->phone) {
                    $q->orWhere('customer_phone', $customer->phone);
                }
                $hasIdentity = true;
            }
            if ($variants) {
                $q->orWhereIn('customer_phone', $variants);
                $hasIdentity = true;
            }
        });
        if (!$hasIdentity && !$customer) {
            return null;
        }

        $typeReady = Schema::hasColumn('pos_transactions', 'transaction_type');
        $signExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END" : '1';
        $saleRowExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN 0 ELSE 1 END" : '1';
        $agg = (clone $txn)->selectRaw(
            "COALESCE(SUM({$saleRowExpr}),0) as visits, COALESCE(SUM(({$signExpr}) * total_amount),0) as spent"
        )->first();

        $lastQ = clone $txn;
        if ($typeReady) {
            $lastQ->where(function ($w) {
                $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
            });
        }
        $last = $lastQ->orderByDesc('created_at')->first();

        $visits = (int) ($agg->visits ?? 0);
        if (!$customer && $visits === 0) {
            return null; // nothing known about this caller
        }

        return [
            'customer_id' => $customer ? (int) $customer->id : null,
            'name' => $customer->name ?? ($last->customer_name ?? null),
            'phone' => $customer->phone ?? ($last->customer_phone ?? $this->displayPhone($phone)),
            'address' => $customer->address ?? null,
            'matched_by' => $matchedBy ?? 'phone',
            // v2: udhaar visibility — whole rupees, cast defensively
            // (live PDO returns decimals as strings).
            'khata_balance' => $customer ? (int) round((float) ($customer->khata_balance ?? 0)) : 0,
            'visits' => $visits,
            'total_spent' => (int) round((float) ($agg->spent ?? 0)),
            'last_order_at' => $last ? Carbon::parse($last->created_at)->format('d M, h:i A') : null,
            'last_order_amount' => $last ? (int) round((float) $last->total_amount) : null,
        ];
    }
}
