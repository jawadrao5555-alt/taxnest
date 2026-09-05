<?php

use App\Services\PosFeatureService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill in the shops that told us their business type before the type
 * configured anything (Task 1562).
 *
 * Signup now stores the chosen type WITH that trade's module map and the
 * master switches derived from it, so a new shop opens on a working POS. Shops
 * that registered before that only ever had the type written — their module
 * map is simply ABSENT, so PosFeatureService::forCompany() resolves every
 * module off and the owner still stares at an empty POS.
 *
 * Eligibility is deliberately narrow:
 *   - NO stored map at all (NULL / empty / JSON null). A stored map is a
 *     decision the shop made and survives untouched — including one that has
 *     everything switched OFF, which is a real configuration, not an absence.
 *   - a stored type that resolves to a real preset (business_category first,
 *     then the pre-split pos_type). A shop whose type resolves to nothing is
 *     skipped rather than guessed at.
 *
 * What it writes is the shared registration preset itself
 * (PosFeatureService::registrationAttributes), so a filled-in shop and a shop
 * that signs up on the same type end up identical — except that a master
 * switch already ON is never taken away: a legacy shop tracking stock with no
 * stored map keeps its inventory (and a restaurant its kitchen) even when the
 * preset alone would not switch it on. Plan masking still decides what the
 * shop may actually use; nothing here touches gating.
 *
 * Idempotent: a filled-in shop now HAS a map, so a second run skips it.
 * Every column is hasColumn-guarded — a deployment whose migrations have not
 * fully landed neither fails nor half-writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The map is the whole point: with no column to write there is nothing
        // to fill in, and writing the master switches alone would just create
        // the drift the dual-switch rule exists to prevent.
        if (!Schema::hasColumn('companies', 'feature_flags')) {
            return;
        }

        $hasCategory  = Schema::hasColumn('companies', 'business_category');
        $hasPosType   = Schema::hasColumn('companies', 'pos_type');
        $hasInventory = Schema::hasColumn('companies', 'inventory_enabled');
        $hasRestaurant = Schema::hasColumn('companies', 'restaurant_mode');

        if (!$hasCategory && !$hasPosType) {
            return; // No stored type anywhere = nothing to derive a preset from.
        }

        $columns = ['id', 'feature_flags'];
        $hasProductType = Schema::hasColumn('companies', 'product_type');
        foreach ([
            'business_category' => $hasCategory,
            'pos_type'          => $hasPosType,
            'inventory_enabled' => $hasInventory,
            'restaurant_mode'   => $hasRestaurant,
        ] as $column => $present) {
            if ($present) {
                $columns[] = $column;
            }
        }

        $query = DB::table('companies')->select($columns)->orderBy('id');
        if ($hasProductType) {
            // POS shops only. Digital Invoice and healthcare companies have no
            // POS module map at all — a stray registration pos_type on one of
            // them must not turn into a configuration it never asked for.
            $query->whereIn('product_type', ['pos', 'fbrpos']);
        }

        $query->chunk(200, function ($rows) use ($hasCategory, $hasPosType, $hasInventory, $hasRestaurant) {
            foreach ($rows as $row) {
                if (!$this->mapIsAbsent($row->feature_flags ?? null)) {
                    continue; // A stored map — even an all-off one — is the shop's own decision.
                }

                $category = null;
                if ($hasCategory && PosFeatureService::isKnownCategory($row->business_category ?? null)) {
                    $category = $row->business_category;
                } elseif ($hasPosType && PosFeatureService::isKnownCategory($row->pos_type ?? null)) {
                    $category = $row->pos_type;
                }
                if ($category === null) {
                    continue; // Nothing to go on (DI/healthcare companies land here too).
                }

                $preset = PosFeatureService::registrationAttributes($category);
                $flags  = $preset['feature_flags'] ?? [];
                if ($flags === []) {
                    continue;
                }

                // Never take a module away: a master switch already ON is a
                // shop that is using it today, map or no map.
                if ($hasInventory && !empty($row->inventory_enabled)) {
                    $flags['inventory'] = true;
                }
                if ($hasRestaurant && !empty($row->restaurant_mode)) {
                    $flags['kitchen'] = true;
                }

                // The map is stored EXACTLY as signup stores it (no extra
                // canonicalization, or a filled-in shop would end up with a
                // different map than one that signed up on the same type), and
                // the master columns are derived from that same map through the
                // shared, hasColumn-guarded derivation.
                $update = ['feature_flags' => json_encode($flags)]
                    + PosFeatureService::masterSwitches($flags);

                DB::table('companies')->where('id', $row->id)->update($update);
            }
        });
    }

    /**
     * A map is ABSENT only when nothing was ever stored — NULL, empty, or a
     * JSON null. Anything that decodes to an array is a stored configuration.
     */
    private function mapIsAbsent($stored): bool
    {
        if ($stored === null) {
            return true;
        }
        if (is_array($stored)) {
            return false;
        }

        $raw = trim((string) $stored);
        if ($raw === '' || strcasecmp($raw, 'null') === 0) {
            return true;
        }

        return !is_array(json_decode($raw, true));
    }

    public function down(): void
    {
        // A fill-in of absent configuration — there is no earlier value to
        // restore, and wiping a shop's modules is never the safe direction.
    }
};
