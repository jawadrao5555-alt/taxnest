<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class SaleCampaign extends Model
{
    protected $fillable = [
        'name',
        'scope',
        'discount_percent',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Per-request memo of all currently-active campaigns.
     * @var array<int,\App\Models\SaleCampaign>|null
     */
    protected static ?array $activeCache = null;

    /**
     * All campaigns that are active RIGHT NOW (is_active, within date window, percent > 0).
     * Auto-expires purely by date at read time — no cron required.
     * Drift-safe: returns [] if the table does not yet exist (prod schema drift).
     */
    public static function currentlyActive(): array
    {
        if (self::$activeCache !== null) {
            return self::$activeCache;
        }

        try {
            if (!Schema::hasTable('sale_campaigns')) {
                return self::$activeCache = [];
            }

            $now = Carbon::now();

            self::$activeCache = self::query()
                ->where('is_active', true)
                ->where('discount_percent', '>', 0)
                ->where(function ($q) use ($now) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                })
                ->get()
                ->all();
        } catch (\Throwable $e) {
            self::$activeCache = [];
        }

        return self::$activeCache;
    }

    /**
     * Best active campaign for a product type.
     * A scope-specific campaign beats a global ('all') one; ties break to the highest percent.
     */
    public static function activeFor(string $productType): ?self
    {
        $best = null;

        foreach (self::currentlyActive() as $c) {
            if ($c->scope !== 'all' && $c->scope !== $productType) {
                continue;
            }

            if ($best === null) {
                $best = $c;
                continue;
            }

            $cSpecific = $c->scope !== 'all';
            $bestSpecific = $best->scope !== 'all';

            if ($cSpecific !== $bestSpecific) {
                if ($cSpecific) {
                    $best = $c;
                }
                continue;
            }

            if ((float) $c->discount_percent > (float) $best->discount_percent) {
                $best = $c;
            }
        }

        return $best;
    }

    public static function clearActiveCache(): void
    {
        self::$activeCache = null;
    }

    /** Human status for admin UI. */
    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) {
            return 'Paused';
        }
        $now = Carbon::now();
        if ($this->starts_at && $this->starts_at->gt($now)) {
            return 'Scheduled';
        }
        if ($this->ends_at && $this->ends_at->lt($now)) {
            return 'Expired';
        }
        return 'Live';
    }
}
