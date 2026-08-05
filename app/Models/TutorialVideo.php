<?php

namespace App\Models;

use App\Services\PosFeatureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * One Urdu tutorial video shown on the public /tutorials page and inside the
 * POS panel (/pos/tutorials). Videos are static mp4 files under
 * public/videos/ (committed to the repo); rows are seeded via migration and
 * managed from the super-admin panel (/admin/tutorial-videos).
 *
 * Visibility rules (owner, 3 Aug 2026):
 *  - Public landing page: is_published AND show_public (super-admin allowlist).
 *  - Inside a company login: is_published AND the company's subscription
 *    actually includes the feature (required_feature plan-gate; NULL = core
 *    feature, everyone sees it).
 */
class TutorialVideo extends Model
{
    protected $fillable = [
        'slug', 'product', 'title', 'description', 'video_url', 'category',
        'required_feature', 'min_role', 'sort', 'is_published', 'show_public',
        'duration_seconds', 'controls_applied',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'show_public' => 'boolean',
        'controls_applied' => 'boolean',
    ];

    /** Product folders on the public page, in display order. */
    public const PRODUCTS = [
        'nestpos' => 'NestPOS',
        'fbrpos'  => 'FBR POS',
        'di'      => 'Digital Invoicing',
    ];

    /** Display order + Roman Urdu labels of the category sections. */
    public const CATEGORIES = [
        'shuruat'    => 'Shuruat — pehle qadam',
        'billing'    => 'Bill banana (Sale Screen)',
        'customers'  => 'Customers',
        'products'   => 'Products aur Inventory',
        'restaurant' => 'Restaurant (KOT, Tables, Recipes)',
        'riders'     => 'Delivery Riders',
        'deals'      => 'Deals aur Combos',
        'reports'    => 'Reports aur Day Close',
        'settings'   => 'Settings aur Customize',
    ];

    /**
     * Owner default gates by category (3 Aug 2026): a video row that arrives
     * from a task-merge migration WITHOUT an explicit required_feature gets
     * one from its category. Core categories (shuruat/billing/customers/
     * products/reports/settings) stay NULL — everyone sees them.
     */
    public const CATEGORY_GATES = [
        'restaurant' => 'restaurant',
        'riders'     => 'riders_enabled',
        'deals'      => 'deals_enabled',
    ];

    /** Slug-pattern gates — more specific than the category defaults. */
    public const SLUG_GATES = [
        'tracking'      => 'rider_tracking_enabled',
        'custom-access' => 'custom_access_enabled',
        'custom_access' => 'custom_access_enabled',
        'qr-menu'       => 'qr_menu_enabled',
        'qr_menu'       => 'qr_menu_enabled',
    ];

    private static ?bool $controlsColumnExists = null;

    /**
     * One-time-per-row enforcement of the owner's 3 Aug 2026 controls.
     *
     * Video rows are seeded by task-merge migrations that were authored in
     * isolated environments and know nothing about these controls — and they
     * can merge AFTER this task's migration has already run, so a one-shot
     * migration cannot catch them. This self-heal runs on every tutorials
     * page load, processes only rows with controls_applied=0, then marks
     * them applied — so the super admin's later manual toggles from
     * /admin/tutorial-videos are never overridden.
     *
     * Rules:
     *  - required_feature default: slug-pattern gate, else category gate
     *    (only when the row shipped with NULL).
     *  - OFFLINE LOCKDOWN (owner's order): any slug containing "offline" is
     *    force-unpublished everywhere (is_published=0, show_public=0) until
     *    the owner enables it himself from the admin panel.
     */
    public static function applyOwnerControls(): void
    {
        try {
            self::$controlsColumnExists ??= Schema::hasColumn((new self)->getTable(), 'controls_applied');
            if (!self::$controlsColumnExists) {
                return; // migration not run yet
            }

            foreach (self::query()->where('controls_applied', false)->get() as $video) {
                $updates = ['controls_applied' => true];
                $slug = (string) $video->slug;

                if ($video->required_feature === null) {
                    $gate = null;
                    foreach (self::SLUG_GATES as $needle => $slugGate) {
                        if (str_contains($slug, $needle)) {
                            $gate = $slugGate;
                            break;
                        }
                    }
                    $gate ??= self::CATEGORY_GATES[(string) $video->category] ?? null;
                    if ($gate !== null) {
                        $updates['required_feature'] = $gate;
                    }
                }

                if (str_contains($slug, 'offline')) {
                    $updates['is_published'] = false;
                    $updates['show_public'] = false;
                }

                $video->forceFill($updates)->saveQuietly();
            }
        } catch (\Throwable $e) {
            report($e); // never 500 a tutorials page over the self-heal
        }
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /** Landing-page set: published AND super-admin allowed. */
    public function scopePublicVisible($query)
    {
        return $query->where('is_published', true)->where('show_public', true);
    }

    /**
     * May this video be shown inside the given company's login?
     * NULL gate = core feature (every subscription). 'restaurant' uses the
     * restaurant plan check; anything else is a PLAN_GATES pricing column.
     * Unknown/broken company => only ungated videos (fail closed).
     */
    public function visibleToCompany(?Company $company): bool
    {
        $gate = trim((string) $this->required_feature);
        if ($gate === '') {
            return true;
        }
        if (!$company) {
            return false;
        }
        try {
            if ($gate === 'restaurant' || $gate === 'restaurant_enabled') {
                return PosFeatureService::restaurantAllowed($company);
            }

            return PosFeatureService::planAllows($company, $gate);
        } catch (\Throwable $e) {
            return false; // never 500 the tutorials page over a bad gate key
        }
    }

    /**
     * Role tier gate (ZFC, 5 Aug 2026): waiters/kitchen/riders sirf apne kaam
     * ki videos dekhein — PRA Mode/settings jaisi cheezen unke saamne na aayen.
     * 'any' = everyone, 'cashier' = cashier+manager+admin, 'admin' = manager+admin.
     * hasColumn-safe: column na ho (pre-migration prod window) to sab dikhta hai.
     */
    public function visibleToRole(?User $user): bool
    {
        $min = (string) ($this->min_role ?? 'any');
        if ($min === '' || $min === 'any') {
            return true;
        }
        if (!$user) {
            return false;
        }
        $posRole = (string) ($user->pos_role ?? '');
        $isAdmin = ($user->role ?? '') === 'company_admin'
            || in_array($posRole, ['pos_admin', 'pos_manager'], true);
        if ($min === 'admin') {
            return $isAdmin;
        }

        return $isAdmin || $posRole === 'pos_cashier'; // 'cashier' tier
    }

    /**
     * Local video files must actually exist before the card is shown —
     * rows are seeded by migration ahead of their MP4s landing in the repo
     * (each video task commits its own file), so a missing file would
     * otherwise render a broken player. External URLs pass through.
     */
    public function fileAvailable(): bool
    {
        $url = (string) $this->video_url;
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return is_file(public_path(ltrim($url, '/')));
        }

        return true; // absolute/external URL — trust it
    }

    /**
     * Group an already-filtered collection by category, in display order.
     * Rows whose local MP4 is missing are dropped here so EVERY display
     * path (public landing + in-app) keeps the broken-player guard.
     * Returns [category => ['label' => ..., 'videos' => Collection]].
     */
    public static function groupedFrom(Collection $videos): array
    {
        $byCat = $videos
            ->filter(fn (self $v) => $v->fileAvailable())
            ->groupBy('category');

        $out = [];
        foreach (self::CATEGORIES as $key => $label) {
            if ($byCat->has($key) && $byCat[$key]->isNotEmpty()) {
                $out[$key] = ['label' => $label, 'videos' => $byCat[$key]];
            }
        }
        // Any category not in the fixed list goes last (future-proof).
        foreach ($byCat as $key => $list) {
            if (!isset(self::CATEGORIES[$key])) {
                $out[$key] = ['label' => ucfirst((string) $key), 'videos' => $list];
            }
        }

        return $out;
    }
}
