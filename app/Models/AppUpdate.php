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
        // Task 1582: category family this update is for (all / food_service /
        // goods_retail / pharmacy / services). Legacy rows read as 'all'.
        'audience_family', 'deployment_key', 'notification_key', 'manual_publish_key', 'announcement_revision', 'archived_at',
    ];

    protected $hidden = ['deployment_key', 'notification_key', 'manual_publish_key', 'announcement_revision'];

    protected $casts = [
        'points' => 'array',
        'archived_at' => 'datetime',
        // Task 1585: NULL / [] = every shop of the audience panel; a non-empty
        // list narrows the elaan to those business categories.
        'target_categories' => 'array',
        'is_published' => 'boolean',
        // Featured "bara elaan" (Task 722): renders as a celebratory hero popup.
        'is_featured' => 'boolean',
    ];

    public function isCustomerDuplicate(): bool
    {
        return $this->is_published
            && \Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'notification_key')
            && $this->notification_key
            && self::where('notification_key', $this->notification_key)
                ->where('id', '<', $this->id)->where('is_published', true)->exists();
    }

    public static function seenIdsForUser(int $userId): array
    {
        $seen = AppUpdateSeen::where('user_id', $userId)->pluck('app_update_id')->all();
        if (!$seen || !\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'notification_key')) {
            return $seen;
        }
        $keys = self::whereIn('id', $seen)->whereNotNull('notification_key')->pluck('notification_key');
        return array_values(array_unique(array_merge($seen,
            self::whereIn('notification_key', $keys)->pluck('id')->all())));
    }

    protected static function booted(): void
    {
        static::saving(function (self $update) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'notification_key')) {
                $update->notification_key = $update->contentKey();
            }
        });
    }

    /** Content identity excludes deployment SHA, creation date and publisher. */
    public function contentKey(): string
    {
        $categories = (array) $this->target_categories;
        sort($categories);
        return hash('sha256', json_encode([
            'title' => trim($this->customerTitle()),
            'points' => array_map(fn ($point) => trim((string) $point), (array) $this->points),
            'image' => $this->image_path ?: null,
            'audience' => $this->audience ?: 'pos',
            'family' => $this->audience_family,
            'categories' => $categories,
            'type' => $this->type,
            'featured' => (bool) $this->is_featured,
            'revision' => $this->announcement_revision ?: null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Keep release receipt rows/history, but deliver identical published content
     * once. The first row owns the customer window: a new SHA cannot restart it.
     */
    public function scopeCustomerCanonical($query)
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'notification_key')) {
            return $query;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'archived_at')) {
            $query->whereNull('app_updates.archived_at')->whereNotExists(function ($archive) {
                $archive->selectRaw('1')->from('app_updates as archived')
                    ->whereColumn('archived.notification_key', 'app_updates.notification_key')
                    ->whereNotNull('archived.archived_at');
            });
        }
        return $query->where(function ($q) {
            $q->whereNull('app_updates.notification_key')->orWhereNotExists(function ($duplicate) {
                $duplicate->selectRaw('1')->from('app_updates as original')
                    ->whereColumn('original.notification_key', 'app_updates.notification_key')
                    ->whereColumn('original.id', '<', 'app_updates.id')
                    ->where('original.is_published', true);
            });
        });
    }

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

    /**
     * Category-family audience (Task 1582). Blank / missing column = 'all'.
     */
    public function getAudienceFamilyAttribute($value): string
    {
        $v = is_string($value) ? trim($value) : '';
        return in_array($v, \App\Services\PosCategoryProfiles::AUDIENCE_FAMILIES, true) ? $v : 'all';
    }

    /** Does this update reach the given company's category family? */
    public function reachesCompany(?\App\Models\Company $company): bool
    {
        try {
            return $this->audience_family === 'all' || in_array($this->audience_family, self::recipientFamilies($company), true);
        } catch (\Throwable $e) {
            return false; // an audience resolution error must not widen delivery
        }
    }

    /**
     * Rows for one company: panel audience + category family. Family is
     * filtered in SQL when the column exists (mid-deploy safe) — an unknown
     * family value is treated as 'all'.
     */
    public function scopeForCompanyFamily($query, ?\App\Models\Company $company)
    {
        if (!$company || !\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'audience_family')) {
            return $query;
        }
        $families = array_merge(['all'], self::recipientFamilies($company));
        return $query->where(function ($q) use ($families) {
            $q->whereNull('audience_family')->orWhere('audience_family', '')
              ->orWhereIn('audience_family', $families)
              ->orWhereNotIn('audience_family', \App\Services\PosCategoryProfiles::AUDIENCE_FAMILIES);
        });
    }

    /** A hotel's food outlet is an enabled module, not its business identity. */
    private static function hotelHasFoodOutlet(?Company $company): bool
    {
        return $company
            && \App\Services\PosFeatureService::resolveCategory($company) === 'hotel'
            && \App\Services\PosFeatureService::restaurantModeFrom(
                (array) \App\Services\PosFeatureService::forCompany($company));
    }

    public static function recipientCategories(?Company $company): array
    {
        $categories = [\App\Services\PosFeatureService::resolveCategory($company)];
        if (self::hotelHasFoodOutlet($company)) {
            $categories[] = 'restaurant';
        }
        return $categories;
    }

    public static function recipientFamilies(?Company $company): array
    {
        $families = \App\Services\PosFeatureService::audiencesFor($company);
        if (\App\Services\PosFeatureService::resolveCategory($company) === 'hotel'
            && !self::hotelHasFoodOutlet($company)) {
            $families = array_values(array_diff($families, ['food_service']));
        }
        return $families;
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
        $query->whereIn('audience', $audiences)->customerCanonical();

        if (!\Illuminate\Support\Facades\Schema::hasColumn('app_updates', 'target_categories')) {
            return $query; // mid-deploy: column not there yet = everything is universal
        }

        $categories = self::recipientCategories($company);

        return $query->where(function ($w) use ($categories) {
            $w->whereNull('target_categories')
              ->orWhere('target_categories', '')
              ->orWhere('target_categories', '[]');
              // Category keys are a fixed slug set (see PosFeatureService), so
              // a quoted-token LIKE matches the JSON list on both MySQL and
              // SQLite without needing JSON_CONTAINS.
            foreach ($categories as $category) {
                $w->orWhere('target_categories', 'like', '%"' . $category . '"%');
            }
        });
    }


    /**
     * Customer announcements describe benefits and usage only. Operational
     * deployment provenance belongs in internal release/audit records.
     */
    public static function containsOperationalDetails(string $title, array $points = []): bool
    {
        $text = strtolower($title . "\n" . implode("\n", array_map('strval', $points)));

        return (bool) preg_match(
            '/(?:\\bdeploy(?:ment|ed|ing)?\\b|\\blive[ _-]?ops\\b|\\bworking[ _-]?tree\\b|\\bworkflow\\b|\\bcallback\\b|\\bdatabase\\b|\\bmigration\\b|\\bserver\\b|\\bcache[_ -]?version\\b|\\bsha\\b|\\bcommit\\b|\\b[0-9a-f]{40}\\b)/i',
            $text
        );
    }

    public function hasOperationalDetails(): bool
    {
        return self::containsOperationalDetails((string) $this->title, (array) $this->points);
    }

    /**
     * Shop-facing title. Strips the CI `[deploy {sha}]` suffix so customers
     * never see release provenance. The stored title is unchanged.
     */
    public function customerTitle(): string
    {
        return \App\Support\CustomerFacingUpdateTitle::display((string) $this->title);
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
