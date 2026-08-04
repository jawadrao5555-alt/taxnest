<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PosBiometricDevice;
use App\Models\PosBiometricUserMap;
use App\Models\PosBiometricPunch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Biometric Hazri auto-sync (4 Aug 2026).
 *
 * Three responsibilities:
 *  1. ADMS push endpoint (public, token-secured) — ZKTeco and compatible
 *     devices push punches here. Token is in the URL so no HTTP auth headers
 *     are needed (many older firmware only support basic GET/POST).
 *  2. Admin setup page — register devices, map device PINs → POS users.
 *  3. CSV/Excel import fallback — for devices without internet / ADMS push.
 */
class PosBiometricController extends Controller
{
    // ─── ADMS Push Endpoint (PUBLIC — no POS auth middleware) ─────────────

    /**
     * GET /bio-sync/{token}/iclock/cdata
     * ZKTeco ADMS handshake: device identifies itself, server responds with
     * push config (Stamp=0 = send all records, pushver, server time).
     * Many other brands (eSSL, Hikvision, VIRDI) also support this protocol.
     */
    public function admsHandshake(Request $request, string $token)
    {
        $device = PosBiometricDevice::where('push_token', $token)
            ->where('is_active', true)
            ->first();

        if (!$device) {
            return response('ERROR', 403)->header('Content-Type', 'text/plain');
        }

        // Update SN from device if provided
        $sn = $request->query('SN');
        if ($sn && !$device->device_sn) {
            $device->device_sn = $sn;
            $device->save();
        }

        // Standard ADMS response: tell device to send all records from stamp 0
        $serverTime = now()->format('Y-m-d H:i:s');
        $body = "GET OPTION FROM:ZKTIMEII\r\n"
              . "Stamp=9999999999\r\n"
              . "DBADDLOGIN=0\r\n"
              . "GETDBWRITERESULT=0\r\n"
              . "OPTIONFLAGS=3\r\n"
              . "ServerVer=2.4.1\r\n"
              . "ServerName=NestPOS\r\n"
              . "ServerTime={$serverTime}\r\n\r\n";

        return response($body, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST /bio-sync/{token}/iclock/cdata
     * Device pushes attendance log (table=ATTLOG). Each line:
     *   PIN\tDateTime\tVerified\tStatus\tWorkCode\tReserved\r\n
     * Status: 0=check-in, 1=check-out, 2=break-out, 3=break-in, 4=overtime-in, 5=overtime-out
     * We treat 0/4 as check_in, 1/2/3/5 as check_out, anything else as unknown.
     */
    public function admsReceivePunches(Request $request, string $token)
    {
        $device = PosBiometricDevice::where('push_token', $token)
            ->where('is_active', true)
            ->first();

        if (!$device) {
            return response('ERROR', 403)->header('Content-Type', 'text/plain');
        }

        $table = $request->query('table', '');
        if (strtoupper($table) !== 'ATTLOG') {
            // Other tables (OPERLOG, USERINFO, etc.) — acknowledge but ignore
            return response("OK: 0\r\n", 200)->header('Content-Type', 'text/plain');
        }

        $body = $request->getContent();
        if (empty($body)) {
            return response("OK: 0\r\n", 200)->header('Content-Type', 'text/plain');
        }

        // Build PIN→user_id lookup for this device
        $maps = PosBiometricUserMap::where('device_id', $device->id)
            ->pluck('user_id', 'device_pin');

        $saved = 0;
        $lines = preg_split('/\r?\n/', trim($body));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                continue;
            }

            $pin        = trim($parts[0]);
            $datetimeRaw = trim($parts[1]);
            $status     = isset($parts[3]) ? (int) trim($parts[3]) : -1;

            try {
                $punchedAt = Carbon::createFromFormat('Y-m-d H:i:s', $datetimeRaw, config('app.timezone'));
            } catch (\Throwable $e) {
                try {
                    $punchedAt = Carbon::parse($datetimeRaw);
                } catch (\Throwable $e2) {
                    continue; // unparseable timestamp — skip
                }
            }

            $punchType = match($status) {
                0, 4    => 'check_in',
                1, 2, 3, 5 => 'check_out',
                default => 'unknown',
            };

            $userId = $maps->get($pin); // null if unmapped

            try {
                PosBiometricPunch::updateOrCreate(
                    [
                        'device_id'  => $device->id,
                        'device_pin' => $pin,
                        'punched_at' => $punchedAt->format('Y-m-d H:i:s'),
                    ],
                    [
                        'company_id' => $device->company_id,
                        'user_id'    => $userId,
                        'punch_type' => $punchType,
                        'raw_data'   => mb_substr($line, 0, 490),
                        'source'     => 'adms',
                    ]
                );
                $saved++;
            } catch (\Throwable $e) {
                Log::warning("biometric punch insert failed: {$e->getMessage()} | line={$line}");
            }
        }

        return response("OK: {$saved}\r\n", 200)->header('Content-Type', 'text/plain');
    }

    // ─── Admin Setup Page ──────────────────────────────────────────────────

    /**
     * GET /pos/bio-sync
     * Device registration + PIN→user mapping page (admin only).
     */
    public function setup(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();
        if (!$user->isPosAdmin()) {
            abort(403);
        }

        $devices = PosBiometricDevice::where('company_id', $companyId)
            ->orderBy('created_at')
            ->get()
            ->map(function ($d) use ($companyId) {
                $d->maps = PosBiometricUserMap::where('device_id', $d->id)
                    ->with('user')
                    ->get();
                $d->last_punch = PosBiometricPunch::where('device_id', $d->id)
                    ->orderByDesc('punched_at')
                    ->value('punched_at');
                return $d;
            });

        $posUsers = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier', 'pos_waiter', 'pos_kitchen', 'pos_delivery', 'pos_rider'])
            ->orWhere(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->where('role', 'company_admin');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role', 'role']);

        $company = \App\Models\Company::find($companyId);

        return view('pos.biometric-setup', compact('company', 'devices', 'posUsers'));
    }

    /**
     * POST /pos/bio-sync/device — register a new device.
     */
    public function storeDevice(Request $request)
    {
        $companyId = app('currentCompanyId');
        if (!auth('pos')->user()->isPosAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'label'     => 'required|string|max:100',
            'device_sn' => 'nullable|string|max:100',
        ]);

        PosBiometricDevice::create([
            'company_id' => $companyId,
            'label'      => trim($validated['label']),
            'device_sn'  => $validated['device_sn'] ? trim($validated['device_sn']) : null,
            'push_token' => PosBiometricDevice::generateToken(),
            'is_active'  => true,
        ]);

        return redirect()->route('pos.bio-sync.setup')
            ->with('success', __('pos.bio_device_added'));
    }

    /**
     * POST /pos/bio-sync/device/{id}/toggle — enable / disable device.
     */
    public function toggleDevice(Request $request, int $id)
    {
        $companyId = app('currentCompanyId');
        if (!auth('pos')->user()->isPosAdmin()) {
            abort(403);
        }

        $device = PosBiometricDevice::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $device->is_active = !$device->is_active;
        $device->save();

        return redirect()->route('pos.bio-sync.setup')
            ->with('success', $device->is_active ? __('pos.bio_device_enabled') : __('pos.bio_device_disabled'));
    }

    /**
     * DELETE /pos/bio-sync/device/{id} — delete device + all its punches.
     */
    public function destroyDevice(Request $request, int $id)
    {
        $companyId = app('currentCompanyId');
        if (!auth('pos')->user()->isPosAdmin()) {
            abort(403);
        }

        $device = PosBiometricDevice::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        PosBiometricPunch::where('device_id', $device->id)->delete();
        PosBiometricUserMap::where('device_id', $device->id)->delete();
        $device->delete();

        return redirect()->route('pos.bio-sync.setup')
            ->with('success', __('pos.bio_device_deleted'));
    }

    /**
     * POST /pos/bio-sync/device/{id}/map — save PIN→user mappings for a device.
     */
    public function saveMapping(Request $request, int $id)
    {
        $companyId = app('currentCompanyId');
        if (!auth('pos')->user()->isPosAdmin()) {
            abort(403);
        }

        $device = PosBiometricDevice::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $validated = $request->validate([
            'mappings'              => 'nullable|array',
            'mappings.*.device_pin' => 'required|string|max:50',
            'mappings.*.user_id'    => 'required|integer',
        ]);

        $mappings = $validated['mappings'] ?? [];

        DB::transaction(function () use ($device, $companyId, $mappings) {
            // Delete all existing mappings for this device, then re-insert
            PosBiometricUserMap::where('device_id', $device->id)->delete();

            foreach ($mappings as $m) {
                $pin    = trim($m['device_pin']);
                $userId = (int) $m['user_id'];
                if ($pin === '' || $userId === 0) {
                    continue;
                }
                // Verify user belongs to this company
                if (!User::where('id', $userId)->where('company_id', $companyId)->exists()) {
                    continue;
                }
                PosBiometricUserMap::create([
                    'company_id' => $companyId,
                    'device_id'  => $device->id,
                    'device_pin' => $pin,
                    'user_id'    => $userId,
                ]);
            }

            // Back-fill user_id on existing punches that are now mapped
            foreach ($mappings as $m) {
                $pin    = trim($m['device_pin']);
                $userId = (int) $m['user_id'];
                if ($pin === '' || $userId === 0) {
                    continue;
                }
                PosBiometricPunch::where('device_id', $device->id)
                    ->where('device_pin', $pin)
                    ->whereNull('user_id')
                    ->update(['user_id' => $userId]);
            }
        });

        return redirect()->route('pos.bio-sync.setup')
            ->with('success', __('pos.bio_mapping_saved'));
    }

    // ─── CSV / Excel Import Fallback ───────────────────────────────────────

    /**
     * GET /pos/bio-sync/import — show import form.
     */
    public function showImport(Request $request)
    {
        $companyId = app('currentCompanyId');
        if (!auth('pos')->user()->isPosAdmin()) {
            abort(403);
        }

        $company = \App\Models\Company::find($companyId);
        $devices = PosBiometricDevice::where('company_id', $companyId)->orderBy('label')->get();
        $posUsers = User::where('company_id', $companyId)
            ->where(function ($q) {
                $q->whereNotNull('pos_role')->orWhere('role', 'company_admin');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role', 'role']);

        return view('pos.biometric-import', compact('company', 'devices', 'posUsers'));
    }

    /**
     * POST /pos/bio-sync/import — process CSV/Excel punch import.
     *
     * Expected columns (case-insensitive, any order):
     *   date      — 2026-08-04 or 04/08/2026 or 04-Aug-2026
     *   time      — 09:30 or 09:30:00
     *   pin       — employee number on device (or "name" column if no pin)
     *   name      — employee name (used as hint when pin missing)
     *   type/status — In/Out/0/1/check_in/check_out (optional; guessed if omitted)
     */
    public function processImport(Request $request)
    {
        $companyId = app('currentCompanyId');
        if (!auth('pos')->user()->isPosAdmin()) {
            abort(403);
        }

        $request->validate([
            'punch_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
            'device_id'  => 'nullable|integer',
        ]);

        $deviceId = $request->input('device_id') ? (int) $request->input('device_id') : null;
        if ($deviceId) {
            $device = PosBiometricDevice::where('id', $deviceId)
                ->where('company_id', $companyId)
                ->first();
            if (!$device) {
                $deviceId = null;
            }
        }

        $file = $request->file('punch_file');
        $ext  = strtolower($file->getClientOriginalExtension());

        try {
            if (in_array($ext, ['xlsx', 'xls'])) {
                $rows = $this->parseExcel($file->getRealPath());
            } else {
                $rows = $this->parseCsv($file->getRealPath());
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['punch_file' => __('pos.bio_import_parse_error') . ': ' . $e->getMessage()]);
        }

        if (empty($rows)) {
            return back()->withErrors(['punch_file' => __('pos.bio_import_empty')]);
        }

        // PIN→user_id lookup: device maps first, then company-wide PIN-match fallback
        $maps = collect();
        if ($deviceId) {
            $maps = PosBiometricUserMap::where('device_id', $deviceId)->pluck('user_id', 'device_pin');
        }

        // Name→user_id fallback lookup
        $usersByName = User::where('company_id', $companyId)
            ->get(['id', 'name'])
            ->keyBy(fn ($u) => mb_strtolower(trim($u->name)));

        $saved = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $pin    = isset($row['pin']) ? trim((string) $row['pin']) : '';
            $name   = isset($row['name']) ? mb_strtolower(trim((string) $row['name'])) : '';
            $dateRaw = $row['date'] ?? '';
            $timeRaw = $row['time'] ?? '';
            $typeRaw = $row['type'] ?? ($row['status'] ?? '');

            if ($dateRaw === '' && $timeRaw === '') {
                $skipped++;
                continue;
            }

            // Parse timestamp
            try {
                if ($timeRaw !== '') {
                    $punchedAt = Carbon::parse("{$dateRaw} {$timeRaw}", config('app.timezone'));
                } else {
                    $punchedAt = Carbon::parse($dateRaw, config('app.timezone'));
                }
            } catch (\Throwable $e) {
                $skipped++;
                continue;
            }

            // Resolve punch type
            $typeStr   = mb_strtolower(trim((string) $typeRaw));
            $punchType = match(true) {
                in_array($typeStr, ['in', 'check in', 'check_in', '0', '4', 'c/in', 'i'])  => 'check_in',
                in_array($typeStr, ['out', 'check out', 'check_out', '1', '2', '3', '5', 'c/out', 'o']) => 'check_out',
                default => 'unknown',
            };

            // Resolve user
            $userId = null;
            if ($pin !== '') {
                $userId = $maps->get($pin);
            }
            if ($userId === null && $name !== '') {
                $userId = $usersByName->get($name)?->id;
            }

            $rawData = mb_substr(implode(',', array_map('strval', $row)), 0, 490);

            try {
                $attrs = [
                    'company_id' => $companyId,
                    'device_id'  => $deviceId,
                    'user_id'    => $userId,
                    'punch_type' => $punchType,
                    'raw_data'   => $rawData,
                    'source'     => 'csv_import',
                ];

                if ($deviceId && $pin !== '') {
                    // With device+pin: use unique key for dedup
                    PosBiometricPunch::updateOrCreate(
                        ['device_id' => $deviceId, 'device_pin' => $pin, 'punched_at' => $punchedAt->format('Y-m-d H:i:s')],
                        $attrs
                    );
                } else {
                    // No device key: just insert (we can't dedup reliably)
                    PosBiometricPunch::create(array_merge($attrs, [
                        'device_pin' => $pin ?: null,
                        'punched_at' => $punchedAt,
                    ]));
                }
                $saved++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        return redirect()->route('pos.bio-sync.setup')
            ->with('success', __('pos.bio_import_done', ['saved' => $saved, 'skipped' => $skipped]));
    }

    // ─── Private helpers ───────────────────────────────────────────────────

    /** Parse CSV file into array of assoc rows with normalised column keys. */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Cannot open file');
        }

        $headers = null;
        $rows    = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => $this->normalizeHeader($h), $line);
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    /** Parse Excel file using PhpSpreadsheet (if available) or fallback CSV. */
    private function parseExcel(string $path): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException('PhpSpreadsheet not installed — please upload a CSV file instead.');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            return [];
        }

        $headers = array_map(fn ($h) => $this->normalizeHeader((string) ($h ?? '')), array_shift($data));
        $rows    = [];
        foreach ($data as $line) {
            if (array_filter($line, fn ($v) => $v !== null && $v !== '') === []) {
                continue; // skip blank rows
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Normalise column headers to lowercase single words. */
    private function normalizeHeader(string $raw): string
    {
        $raw = mb_strtolower(trim($raw));
        $map = [
            'employee id'   => 'pin',
            'employee no'   => 'pin',
            'emp id'        => 'pin',
            'emp no'        => 'pin',
            'user id'       => 'pin',
            'userid'        => 'pin',
            'pin no'        => 'pin',
            'staff id'      => 'pin',
            'employee name' => 'name',
            'emp name'      => 'name',
            'staff name'    => 'name',
            'punch time'    => 'time',
            'punch date'    => 'date',
            'check time'    => 'time',
            'check date'    => 'date',
            'in/out'        => 'type',
            'attendance'    => 'type',
            'direction'     => 'type',
            'verify mode'   => 'type',
        ];
        return $map[$raw] ?? $raw;
    }
}
