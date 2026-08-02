<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Urdu tutorial video shown on the public /tutorials page and inside the
 * POS panel (/pos/tutorials). Videos are static mp4 files under
 * public/videos/ (committed to the repo); rows are seeded via migration.
 */
class TutorialVideo extends Model
{
    protected $fillable = [
        'slug', 'title', 'description', 'video_url', 'category',
        'sort', 'is_published', 'duration_seconds',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    /** Display order + Roman Urdu labels of the category sections. */
    public const CATEGORIES = [
        'shuruat'    => 'Shuruat — pehle qadam',
        'billing'    => 'Bill banana (Sale Screen)',
        'customers'  => 'Customers',
        'products'   => 'Products aur Inventory',
        'restaurant' => 'Restaurant (KOT, Tables, Recipes)',
        'reports'    => 'Reports aur Day Close',
        'settings'   => 'Settings aur Customize',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * All published videos grouped by category, in display order.
     * Returns [category => ['label' => ..., 'videos' => Collection]].
     */
    public static function groupedForDisplay(): array
    {
        $videos = static::published()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->groupBy('category');

        $out = [];
        foreach (self::CATEGORIES as $key => $label) {
            if ($videos->has($key) && $videos[$key]->isNotEmpty()) {
                $out[$key] = ['label' => $label, 'videos' => $videos[$key]];
            }
        }
        // Any category not in the fixed list goes last (future-proof).
        foreach ($videos as $key => $list) {
            if (!isset(self::CATEGORIES[$key])) {
                $out[$key] = ['label' => ucfirst($key), 'videos' => $list];
            }
        }

        return $out;
    }
}
