<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Desktop Agent install (counter PC) of a multi-counter shop.
 * Registered automatically the first time an agent that sends a device_uid
 * heartbeats / reports printers. Same company agent_api_key for all counters
 * (Option A) — the UID is what tells them apart.
 */
class PosAgentDevice extends Model
{
    protected $table = 'pos_agent_devices';

    protected $fillable = [
        'company_id',
        'device_uid',
        'hostname',
        'name',
        'agent_version',
        'last_seen_at',
        'printers',
        'printers_reported_at',
        'receipt_printer',
    ];

    protected $casts = [
        'printers' => 'array',
        'last_seen_at' => 'datetime',
        'printers_reported_at' => 'datetime',
    ];

    /**
     * Same 2-minute freshness window as Company::agentOnline() — routing to
     * an offline counter would strand the bill, so both checks must agree.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    /** Friendly label for UI: admin name, else hostname, else short UID. */
    public function label(): string
    {
        return $this->name ?: ($this->hostname ?: substr($this->device_uid, 0, 12));
    }

    /**
     * Task 1194 — union KOT-family printer picker options: every printer
     * reported by every registered counter, each labeled with its counter
     * ("POS-80 — Counter 2") and value-encoded with its owning device
     * ("uid::name"). Owner mandate: the shop wires printers however it wants
     * (USB or LAN) and picks ANY counter's printer from ANY picker.
     *
     * Falls back to today's company-wide list (plain name values, no owner)
     * when the registry is empty or holds fewer than TWO counters — a
     * single-counter shop must see ZERO change. Printers a pre-1.9.0 agent
     * reported only into the company-wide list stay pickable as plain
     * (legacy-routed) options in mixed fleets.
     *
     * @return array<int, array{value: string, name: string, device_uid: ?string, label: string, isTextOnly: bool}>
     */
    public static function kotPrinterOptions(Company $company): array
    {
        $legacy = collect($company->printerSettings()['available_printers'])
            ->filter(fn ($p) => !empty($p['name']))
            ->map(fn ($p) => [
                'value' => (string) $p['name'],
                'name' => (string) $p['name'],
                'device_uid' => null,
                'label' => ($p['displayName'] ?? $p['name']) . (!empty($p['isDefault']) ? ' ' . __('pos.default_paren') : ''),
                'isTextOnly' => !empty($p['isTextOnly']),
            ])->values();

        try {
            if (!\App\Http\Controllers\AgentController::deviceRoutingReady()) {
                return $legacy->all();
            }
            $devices = static::where('company_id', $company->id)->orderBy('id')->get();
            if ($devices->count() < 2) {
                return $legacy->all();
            }
            $union = collect();
            foreach ($devices as $device) {
                foreach ((array) ($device->printers ?? []) as $p) {
                    if (empty($p['name'])) {
                        continue;
                    }
                    $union->push([
                        'value' => static::encodePick($p['name'], $device->device_uid),
                        'name' => (string) $p['name'],
                        'device_uid' => $device->device_uid,
                        'label' => ($p['displayName'] ?? $p['name']) . ' — ' . $device->label(),
                        'isTextOnly' => !empty($p['isTextOnly']),
                    ]);
                }
            }
            if ($union->isEmpty()) {
                return $legacy->all();
            }
            $covered = $union->pluck('name')->flip();
            foreach ($legacy as $opt) {
                if (!isset($covered[$opt['name']])) {
                    $union->push($opt);
                }
            }
            return $union->values()->all();
        } catch (\Throwable $e) {
            return $legacy->all(); // registry hiccup must never blank the pickers
        }
    }

    /**
     * Form value for a saved KOT-family pick: "uid::name" when the pick has
     * an owning counter, plain name for legacy saves, '' when unset.
     */
    public static function encodePick(?string $name, ?string $deviceUid): string
    {
        $name = (string) $name;
        if ($name === '') {
            return '';
        }
        return $deviceUid ? $deviceUid . '::' . $name : $name;
    }

    /**
     * Parse + validate a submitted KOT-family printer pick.
     *
     * "uid::name" where uid matches a registered counter → device pick,
     * valid only when that counter's OWN reported list contains the name
     * (a printer another counter reported can never be saved onto this one).
     * Anything else (incl. an unmatched "uid::" prefix — printer names may
     * legally contain '::') → legacy plain name, validated against the
     * company-wide list exactly as before. '' → valid unset.
     *
     * @return array{name: ?string, device_uid: ?string, valid: bool}
     */
    public static function resolvePick(Company $company, ?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['name' => null, 'device_uid' => null, 'valid' => true];
        }
        try {
            if (\App\Http\Controllers\AgentController::deviceRoutingReady() && str_contains($raw, '::')) {
                [$uid, $name] = explode('::', $raw, 2);
                $device = ($uid !== '' && $name !== '')
                    ? static::where('company_id', $company->id)->where('device_uid', $uid)->first()
                    : null;
                if ($device) {
                    $own = collect($device->printers ?? [])->pluck('name')->all();
                    return in_array($name, $own, true)
                        ? ['name' => $name, 'device_uid' => $device->device_uid, 'valid' => true]
                        : ['name' => $name, 'device_uid' => null, 'valid' => false];
                }
                // No such device → fall through to legacy interpretation below.
            }
        } catch (\Throwable $e) {
            // Registry unavailable — legacy interpretation below.
        }
        $known = collect($company->printerSettings()['available_printers'])->pluck('name')->all();
        return ['name' => $raw, 'device_uid' => null, 'valid' => in_array($raw, $known, true)];
    }
}
