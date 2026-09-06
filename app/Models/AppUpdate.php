<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUpdate extends Model
{
    /**
     * POS-side live window (Task 1286): updates auto-disappear from the
     * POS/FBR POS bell + popup this many days after publish (created_at).
     * Display-only — rows are never deleted; admin history keeps everything.
     */
    public const LIVE_DAYS = 7;

    protected $fillable = [
        'title', 'points', 'image_path', 'audience', 'target_categories', 'type', 'is_published', 'is_featured', 'created_by',
    ];

    protected $casts = [
        'points' => 'array',
        // Task 1585: NULL / [] = every shop of the audience panel; a non-empty
        // list narrows the elaan to those business categories.
        'target_categories' => 'array',
        'is_published' => 'boolean',
        // Featured "bara elaan" (Task 722): renders as a celebratory hero popup.
        'is_featured' => 'boolean',
    ];

    /**
     * Self-healing accessor (11 Aug 2026): a write path once DOUBLE-encoded
     * points (JSON string inside JSON) and the What's New foreach in the
     * pos-app/fbr-pos-app layouts 500'd EVERY panel page. Always return an
     * array here so one bad row can never take the panels down again.
     */
    public function getPointsAttribute($value)
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);
            $decoded = is_array($inner) ? $inner : [$decoded];
        }
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** Normalize writes: a JSON string is decoded (not re-encoded), a plain string becomes one point. */
    public function setPointsAttribute($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        $this->attributes['points'] = json_encode(array_values(array_filter((array) $value, fn ($p) => $p !== null && $p !== '')), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Update type (Task 1286): 'feature' or 'improvement'. Legacy/blank rows
     * (and a mid-deploy missing column — attribute arrives as null) always
     * normalize to 'improvement' so badge rendering can never error.
     */
    public function getTypeAttribute($value)
    {
        return $value === 'feature' ? 'feature' : 'improvement';
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * 7-day POS-side visibility window (Task 1286) — read-time filter only,
     * no cron: older rows simply stop matching. Admin history is unfiltered.
     */
    public function scopeLiveWindow($query)
    {
        return $query->where('created_at', '>=', now()->subDays(self::LIVE_DAYS));
    }

    /**
     * Task 1585: only known category keys are ever stored, and an empty list
     * is stored as NULL ("all shops") so the query side has ONE meaning of
     * "untargeted". Unknown slugs are dropped rather than silently narrowing
     * an elaan to a category nothing resolves to.
     */
    public static function normalizeCategories($raw): ?array
    {
        $list = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        if (!is_array($list)) {
            return null;
        }
        $clean = array_values(array_unique(array_filter(
            array_map(fn ($c) => is_string($c) ? trim($c) : null, $list),
            fn ($c) => $c !== null && $c !== '' && \App\Services\PosFeatureService::isKnownCategory($c)
        )));

        return $clean ?: null;
    }

    public function setTargetCategoriesAttribute($value): void
    {
        $clean = self::normalizeCategories($value);
        $this->attributes['target_categories'] = $clean === null ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Task 1585: the ONE "does THIS company see this elaan" predicate — panel
     * audience plus optional business-category targeting, resolved exactly the
     * way the POS itself resolves a shop's category (PosFeatureService), so a
     * shop with no stored category is never silently excluded.
     *
     * Used by the PRA layout, the FBR layout and the mark-seen endpoint.
     */
    public function scopeForCompany($query, ?Company $company, ?string $panel = null)
    {
        $panel = $panel ?: \App\Services\PosFeatureService::panelFor($company);
        $audiences = $panel === 'fbr' ? ['fbr_pos', 'all'] : ['pos', 'all'];
        $query->whereIn('audience', $audiences);

        if (!\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'target_categories')) {
            return $query; // mid-deploy: column not there yet = everything is universal
        }

        $category = \App\Services\PosFeatureService::resolveCategory($company);

        return $query->where(function ($w) use ($category) {
            $w->whereNull('target_categories')
              ->orWhere('target_categories', '')
              ->orWhere('target_categories', '[]')
              // Category keys are a fixed slug set (see PosFeatureService), so
              // a quoted-token LIKE matches the JSON list on both MySQL and
              // SQLite without needing JSON_CONTAINS.
              ->orWhere('target_categories', 'like', '%"' . $category . '"%');
        });
    }

    public function seens()
    {
        return $this->hasMany(AppUpdateSeen::class, 'app_update_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
