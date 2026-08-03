<?php

namespace App\Models;

use App\Services\PosFeatureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        'required_feature', 'sort', 'is_published', 'show_public', 'duration_seconds',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'show_public' => 'boolean',
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
