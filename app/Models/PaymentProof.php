<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PaymentProof extends Model
{
    protected $fillable = [
        'company_id',
        'amount',
        'payment_method',
        'auto_access_until',
        'reference',
        'payment_date',
        'proof_path',
        'status',
        'pricing_plan_id',
        'billing_cycle',
        // Add-on lanes (Aug 2026): 'subscription' (default), 'extra_branch'
        // + kitne slots maange gaye, ya 'pos_addon' + kaun se feature codes.
        'request_type',
        'extra_branch_qty',
        'addon_codes',
        'addon_quote_snapshot',
        'subscription_id',
        'notes',
        'verified_by',
        'verified_at',
        'reject_reason',
        'file_pruned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'auto_access_until' => 'datetime',
        'verified_at' => 'datetime',
        'file_pruned_at' => 'datetime',
        'extra_branch_qty' => 'integer',
        'addon_quote_snapshot' => 'array',
    ];

    /** Non-package request lanes. Anything NOT listed here is a renewal proof. */
    public const ADDON_KINDS = ['extra_branch', 'pos_addon'];

    /** hasColumn() per call = DB round trip; per-request memo. */
    private static ?bool $kindColumn = null;

    private static ?bool $addonCodesColumn = null;

    private static ?bool $addonQuoteSnapshotColumn = null;

    public static function kindColumnExists(): bool
    {
        if (self::$kindColumn === null) {
            try {
                self::$kindColumn = Schema::hasColumn('payment_proofs', 'request_type');
            } catch (\Throwable $e) {
                self::$kindColumn = false;
            }
        }

        return self::$kindColumn;
    }

    public static function addonCodesColumnExists(): bool
    {
        if (self::$addonCodesColumn === null) {
            try {
                self::$addonCodesColumn = self::kindColumnExists()
                    && Schema::hasColumn('payment_proofs', 'addon_codes');
            } catch (\Throwable $e) {
                self::$addonCodesColumn = false;
            }
        }

        return self::$addonCodesColumn;
    }

    public static function addonQuoteSnapshotColumnExists(): bool
    {
        if (self::$addonQuoteSnapshotColumn === null) {
            try {
                self::$addonQuoteSnapshotColumn = self::addonCodesColumnExists()
                    && Schema::hasColumn('payment_proofs', 'addon_quote_snapshot');
            } catch (\Throwable $e) {
                self::$addonQuoteSnapshotColumn = false;
            }
        }

        return self::$addonQuoteSnapshotColumn;
    }

    /** 'subscription' | 'extra_branch' | 'pos_addon' — pre-migration rows = subscription. */
    public function kind(): string
    {
        if (self::kindColumnExists() && in_array($this->request_type, self::ADDON_KINDS, true)) {
            return $this->request_type;
        }

        return 'subscription';
    }

    public function isExtraBranch(): bool
    {
        return $this->kind() === 'extra_branch';
    }

    public function isPosAddon(): bool
    {
        return $this->kind() === 'pos_addon';
    }

    /** Feature codes requested on a pos_addon proof (JSON column → clean list). */
    public function addonCodeList(): array
    {
        if (!$this->isPosAddon()) {
            return [];
        }

        $decoded = json_decode((string) $this->addon_codes, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $decoded,
            fn ($code) => is_string($code) && isset(\App\Services\PosAddonPricingService::ADDONS[$code])
        )));
    }

    /**
     * Server quote captured when the shop submitted this add-on proof.
     * Old rows intentionally return null and use the legacy live-quote path.
     */
    public function addonQuoteSnapshot(): ?array
    {
        if (!$this->isPosAddon() || !self::addonQuoteSnapshotColumnExists()
            || !is_array($this->addon_quote_snapshot)) {
            return null;
        }

        $snapshot = $this->addon_quote_snapshot;
        $cycle = \App\Services\PosAddonPricingService::normalizeCycle($snapshot['cycle'] ?? null);
        $codes = array_values(array_unique(array_filter(
            $snapshot['codes'] ?? [],
            fn ($code) => is_string($code) && isset(\App\Services\PosAddonPricingService::ADDONS[$code])
        )));
        $lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        $safeLines = [];
        foreach ($codes as $code) {
            if (!array_key_exists($code, $lines) || !is_numeric($lines[$code])) {
                return null;
            }
            $safeLines[$code] = max(0, (int) round((float) $lines[$code]));
        }

        $total = array_sum($safeLines);
        if (empty($codes) || !isset($snapshot['total']) || !is_numeric($snapshot['total'])
            || $total !== (int) round((float) $snapshot['total'])
            || !isset($snapshot['months'], $snapshot['until'])
            || (int) $snapshot['months'] < 1
            || !is_string($snapshot['until'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot['until'])) {
            return null;
        }

        $snapshot['cycle'] = $cycle;
        $snapshot['codes'] = $codes;
        $snapshot['lines'] = $safeLines;
        $snapshot['total'] = $total;
        $snapshot['months'] = (int) $snapshot['months'];

        return $snapshot;
    }

    /**
     * Package/renewal proofs only. Har wo jagah jahan "pending proof" ka matlab
     * "renewal review mein hai" hai (lock modal, expiry popup, one-pending rule)
     * ko is scope se guzarna chahiye — warna ek add-on request renewal ka
     * form chhupa deti hai.
     */
    public function scopeSubscriptionKind(Builder $q): Builder
    {
        if (!self::kindColumnExists()) {
            return $q;
        }

        return $q->where(function ($w) {
            $w->whereNull('request_type')->orWhereNotIn('request_type', self::ADDON_KINDS);
        });
    }

    public function scopeExtraBranchKind(Builder $q): Builder
    {
        if (!self::kindColumnExists()) {
            // Column abhi migrate nahi hua — koi extra-branch row ho hi nahi sakti.
            return $q->whereRaw('1 = 0');
        }

        return $q->where('request_type', 'extra_branch');
    }

    public function scopePosAddonKind(Builder $q): Builder
    {
        if (!self::addonCodesColumnExists()) {
            // Lane abhi migrate nahi hui — koi pos_addon row ho hi nahi sakti.
            return $q->whereRaw('1 = 0');
        }

        return $q->where('request_type', 'pos_addon');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }
}
