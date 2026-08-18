<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Counter/Station KOT routing (owner, Jul 2026).
 *
 * A station ("counter") claims a set of pos_products.category strings.
 * Order items route to the station that claims their product's category;
 * anything unmatched (manual items, services, blank/unknown categories)
 * falls to the implicit DEFAULT station — the main Kitchen (id 0 in URLs
 * and maps). A company with zero active stations = feature dormant.
 *
 * Shared-table rule: NO foreign keys; all lookups company-scoped.
 */
class PosStation extends Model
{
    public const DEFAULT_ID = 0;          // implicit main Kitchen bucket
    public const DEFAULT_LABEL = 'KITCHEN';

    protected $fillable = [
        'company_id', 'name', 'categories', 'printer_name', 'is_active', 'sort',
        // Task 1194: owning counter of printer_name (multi-counter shops).
        // NULL = legacy pick → jobs unstamped, claimable by any agent.
        'printer_device_uid',
    ];

    protected $casts = [
        'categories' => 'array',
        'is_active' => 'boolean',
    ];

    /** Active stations for a company, in display order. */
    public static function activeFor(int $companyId)
    {
        return static::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** Normalized category key (case/space-insensitive matching). */
    public static function catKey(?string $category): string
    {
        return mb_strtolower(trim((string) $category));
    }

    /**
     * category-key => station_id map for a set of stations. First claim wins
     * (admin CRUD enforces one-active-station-per-category, so overlaps only
     * exist transiently).
     */
    public static function categoryMap($stations): array
    {
        $map = [];
        foreach ($stations as $st) {
            foreach ((array) ($st->categories ?? []) as $cat) {
                $key = static::catKey($cat);
                if ($key !== '' && !isset($map[$key])) {
                    $map[$key] = (int) $st->id;
                }
            }
        }
        return $map;
    }

    /**
     * Resolve station_id per order item (DEFAULT_ID when unmatched).
     * $items = RestaurantOrderItem collection (any orders, same company).
     * ONE bulk product-category lookup — never per-item queries.
     *
     * @return array [item_id => station_id]
     */
    public static function mapItems(int $companyId, $stations, $items): array
    {
        $catMap = static::categoryMap($stations);

        $productIds = $items->where('item_type', 'product')
            ->pluck('item_id')->filter()->unique()->values();
        $prodCats = $productIds->isEmpty()
            ? collect()
            : PosProduct::where('company_id', $companyId)
                ->whereIn('id', $productIds)
                ->pluck('category', 'id');

        $out = [];
        foreach ($items as $it) {
            $sid = static::DEFAULT_ID;
            if ($it->item_type === 'product' && $it->item_id) {
                $key = static::catKey($prodCats[$it->item_id] ?? null);
                if ($key !== '' && isset($catMap[$key])) {
                    $sid = $catMap[$key];
                }
            }
            $out[$it->id] = $sid;
        }
        return $out;
    }

    /**
     * Shared KOT-ticket preparation for BOTH render paths (kitchenTicket route
     * and the Desktop Agent's job render). Applies the optional ?station=
     * filter and builds the grouped sections the ticket blade prints.
     *
     * Zero active stations => legacy behavior byte-identical: group by raw
     * product category ('Services' / 'General' buckets), no station filter
     * (but with ONE bulk lookup instead of the old per-item N+1).
     *
     * @param  int    $companyId
     * @param  \Illuminate\Support\Collection $ticketItems  already delta-filtered
     * @param  string|null $stationParam  ''/null = all; numeric id; '0' = default Kitchen
     * @return array{items: \Illuminate\Support\Collection, grouped: \Illuminate\Support\Collection, stationLabel: ?string, stations: \Illuminate\Support\Collection}
     */
    public static function prepareTicket(int $companyId, $ticketItems, $stationParam = null): array
    {
        $stations = static::activeFor($companyId);

        if ($stations->isEmpty()) {
            // ZFC feedback (Jul 2026): NO category sections on the KOT when the
            // company has no stations configured — the reversed (white-on-black)
            // category headers printed blurry on cheap thermal printers and the
            // kitchen doesn't need them. One flat list; the blade hides the
            // section header whenever there is only a single group. Station
            // grouping (real routing) below stays untouched.
            $grouped = $ticketItems->isEmpty() ? collect() : collect(['ALL' => $ticketItems->values()]);
            return ['items' => $ticketItems, 'grouped' => $grouped, 'stationLabel' => null, 'stations' => $stations];
        }

        $itemMap = static::mapItems($companyId, $stations, $ticketItems);
        $labels = [static::DEFAULT_ID => static::DEFAULT_LABEL];
        foreach ($stations as $st) {
            $labels[(int) $st->id] = $st->name;
        }

        $stationLabel = null;
        if ($stationParam !== null && $stationParam !== '' && $stationParam !== 'all') {
            $sid = (int) $stationParam;
            $ticketItems = $ticketItems->filter(fn ($i) => ($itemMap[$i->id] ?? static::DEFAULT_ID) === $sid)->values();
            $stationLabel = $labels[$sid] ?? static::DEFAULT_LABEL;
        }

        $grouped = $ticketItems->groupBy(function ($i) use ($itemMap, $labels) {
            $sid = $itemMap[$i->id] ?? static::DEFAULT_ID;
            return $labels[$sid] ?? static::DEFAULT_LABEL;
        });

        return ['items' => $ticketItems, 'grouped' => $grouped, 'stationLabel' => $stationLabel, 'stations' => $stations];
    }
}
