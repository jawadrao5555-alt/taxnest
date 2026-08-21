<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Delivery rider (PRA POS restaurant module).
 *
 * Plain per-company record; optionally linked to a confined `pos_rider`
 * login via user_id (limit-exempt, like kitchen/waiter accounts).
 */
class PosRider extends Model
{
    protected $fillable = [
        'company_id', 'name', 'phone', 'cnic', 'vehicle_no', 'is_active', 'user_id', 'login_link_issue',
        // Live tracking (Aug 2026)
        'on_duty', 'duty_started_at', 'last_lat', 'last_lng', 'last_located_at', 'app_token',
        // Task #1102: night sweep auto-ended duty stamp
        'duty_auto_off_at',
        // Task #1106: instant push + battery reporting
        'fcm_token', 'last_battery_pct',
        // Task #1357: upload diagnostics — when the phone last reached us, and
        // why we refused it (duty off / plan locked / point too old).
        'last_upload_at', 'last_reject_reason', 'last_reject_at',
        // Task #1405: rider app build last seen (from the TaxNestRider user agent).
        'app_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'on_duty' => 'boolean',
        'duty_started_at' => 'datetime',
        'last_located_at' => 'datetime',
        'duty_auto_off_at' => 'datetime',
        'last_battery_pct' => 'integer',
        'last_upload_at' => 'datetime',
        'last_reject_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the confined login only when this row is its safe, exclusive owner.
     *
     * A historical or manually changed user_id can point at a deleted account,
     * another company, another POS role, or the same account as a second rider.
     * Those links must never be displayed as this rider's identity or mutated by
     * rider-management actions.
     *
     * @return array{user: ?User, issue: ?string}
     */
    public function riderLoginStatus(?User $candidate = null): array
    {
        if (!$this->user_id) {
            return [
                'user' => null,
                'issue' => $this->getAttributes()['login_link_issue'] ?? null,
            ];
        }

        $user = $candidate ?: User::find($this->user_id);
        if (!$user) {
            return ['user' => null, 'issue' => 'missing'];
        }
        if ((int) $user->id !== (int) $this->user_id) {
            return ['user' => null, 'issue' => 'missing'];
        }
        if ((int) $user->company_id !== (int) $this->company_id) {
            return ['user' => null, 'issue' => 'cross_company'];
        }
        if ($user->pos_role !== 'pos_rider') {
            return ['user' => null, 'issue' => 'wrong_role'];
        }
        if (static::where('user_id', $user->id)->whereKeyNot($this->id)->exists()) {
            return ['user' => null, 'issue' => 'multiple_riders'];
        }

        return ['user' => $user, 'issue' => null];
    }

    public function settlements()
    {
        return $this->hasMany(PosRiderSettlement::class, 'rider_id');
    }

    /**
     * Open CASH khata bills for this rider (unsettled, not returned).
     * Bypasses hide_archived — day-close archives bills while the rider
     * still owes the cash.
     */
    public function openCashBills()
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $this->company_id)
            ->where('rider_id', $this->id)
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($q) {
                $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            });
    }

    /**
     * SQL expression for a bill's khata remaining — partial receipts
     * (rider_partial_paid, Task 525) already handed over are deducted.
     * Schema-guarded for prod drift (column may not exist yet).
     */
    public static function remainingExpr(string $table = 'pos_transactions'): string
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, 'rider_partial_paid')
            ? '(total_amount - COALESCE(rider_partial_paid, 0))'
            : 'total_amount';
    }

    /** Rider's khata remaining (open cash bills minus partial receipts). */
    public function openCashRemaining(): float
    {
        return (float) $this->openCashBills()
            ->selectRaw('COALESCE(SUM(' . self::remainingExpr('pos_transactions') . '), 0) as rem')
            ->value('rem');
    }

    // ── Task #1405: rider app build tracking ─────────────────────────────────
    // The Android app has always identified itself as `TaxNestRider/<version>`
    // on every API call; these helpers are the ONE place that reads that user
    // agent and decides whether a build is behind the released one, so the
    // riders list and the live map can never disagree.

    /**
     * Version out of a `TaxNestRider/1.7.0` user agent (NULL when the caller
     * is not the rider app, or sent no parseable version).
     *
     * Only digits and dots are accepted: a junk/spoofed agent must never write
     * a 200-char string into the column, and the value has to stay comparable
     * with version_compare.
     */
    public static function parseAppVersion(?string $userAgent): ?string
    {
        if (!$userAgent || !preg_match('~TaxNestRider/(\d+(?:\.\d+)*)~i', $userAgent, $m)) {
            return null;
        }

        $version = trim($m[1], '.');

        return ($version === '' || strlen($version) > 20) ? null : $version;
    }

    /** Released rider-app version (admin-editable); '' = release gate not set. */
    public static function latestAppVersion(): string
    {
        return trim((string) SystemSetting::get('rider_app_latest_version', ''));
    }

    /**
     * Is this rider's build behind the released one?
     *
     * Unknown build (never opened the app) is NOT "outdated" — it is its own,
     * louder state on both screens. An empty setting means the owner has not
     * published a version yet, so nothing can be judged behind it (same rule
     * the app's own update check follows).
     */
    public static function appVersionOutdated(?string $version, ?string $latest = null): bool
    {
        $version = trim((string) $version);
        $latest = $latest === null ? self::latestAppVersion() : trim($latest);

        if ($version === '' || $latest === '') {
            return false;
        }

        return version_compare($version, $latest, '<');
    }

    /**
     * Great-circle distance in km between two lat/lng points (haversine).
     * Used by the deliveries-board "nearest free rider" hint (Task 1104):
     * denormalized pos_riders.last_lat/lng vs the saved shop pin.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // ── Customer ETA (Task 1105) ─────────────────────────────────────────────
    // Straight-line km × road factor ÷ city speed — deliberately NO paid
    // routing API. Single truth used by the deliveries board chips AND the
    // public /track page so both always show the same number.
    public const ETA_ROAD_FACTOR = 1.3;   // straight line → real streets
    public const ETA_CITY_SPEED_KMH = 22; // bike through PK city traffic

    /** Rough minutes for a rider to cover $km straight-line km in city traffic. */
    public static function etaMinutes(float $km): int
    {
        return max(2, (int) round($km * self::ETA_ROAD_FACTOR / self::ETA_CITY_SPEED_KMH * 60));
    }
}
