<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * "Kaunsi cart line bawarchi ki parchi par jati hai" — is sawaal ka WAHID jawab.
 *
 * Kahani (ZFC PIZZA POINT, 1 Sep 2026): delivery order par "Delivery Charges"
 * ek synthetic MANUAL cart line hai. Woh bill par to lazmi hai, magar KOT/KDS
 * par jane ka koi matlab nahi — bawarchi ke liye woh koi dish nahi. Is ke liye
 * per-line `skip_kitchen` column aaya.
 *
 * Do sakht usool jo yahan ikathay rakhe gaye hain, warna paths bikhar kar
 * ek dusre se ulat chalne lagte hain:
 *
 *  1) CHHANT-PHATAK SOURCE PAR — order load hote hi. Skipped row kabhi
 *     `kot_printed_at` stamp nahi khati; agar woh delta/unprinted ki ginti tak
 *     pahunch jaye to hamesha "unprinted" nazar aati hai aur:
 *       • KOT Full Mode har delta par POORA ticket dobara chhapwa deta hai,
 *       • paid delivery order KDS board par hamesha latka rehta hai,
 *       • ek khali (204) print job baar baar banta rehta hai.
 *     Is liye har unseen/reprint/enqueue hisaab bhi isi filter se guzarta hai.
 *
 *  2) SERVER KHUD FAISLA KARTA HAI. Client ka bheja hua `skip_kitchen` ek
 *     darkhwast hai, hukm nahi. Sirf delivery-fee jaisi line chhup sakti hai:
 *     manual + order type delivery + tax-exempt + poore order mein SIRF EK.
 *     Warna ek cashier apni marzi ki chargeable dish kitchen se ghayab kar
 *     sakta tha.
 */
class PosKitchenLines
{
    /** Ek hi request mein baar baar hasColumn na chale. */
    private static array $colCache = [];

    public static function columnExists(string $table): bool
    {
        if (!array_key_exists($table, self::$colCache)) {
            try {
                self::$colCache[$table] = Schema::hasColumn($table, 'skip_kitchen');
            } catch (\Throwable $e) {
                // Deploy-before-migrate window: column ka pata na chale to purana
                // bartao (sab lines kitchen ki) — KOT kabhi gum na ho.
                self::$colCache[$table] = false;
            }
        }

        return self::$colCache[$table];
    }

    /**
     * Query par "sirf kitchen wali lines" ki shart. Column na ho to query
     * bilkul waisi hi rehti hai jaisi pehle thi.
     */
    public static function scope($query, string $table = 'restaurant_order_items')
    {
        if (self::columnExists($table)) {
            $query->where(fn ($q) => $q->whereNull('skip_kitchen')->orWhere('skip_kitchen', false));
        }

        return $query;
    }

    /** Loaded collection se non-kitchen lines nikal do. */
    public static function only($items)
    {
        if (!$items) {
            return $items;
        }

        return $items->reject(fn ($i) => (bool) (is_array($i) ? ($i['skip_kitchen'] ?? false) : ($i->skip_kitchen ?? false)))->values();
    }

    /** Order object ki `items` relation ko jagah par chhaant do. */
    public static function pruneOrder($order): void
    {
        if (!$order) {
            return;
        }
        try {
            $order->loadMissing('items');
            $order->setRelation('items', self::only($order->items));
        } catch (\Throwable $e) {
            // Chhant-phatak kabhi bill/KOT ko na rok de.
        }
    }

    /**
     * Delivery fee ke naam — SERVER ke apne, har zaban mein. Client jo bhi
     * bheje, line ko chhupne ke liye INHI mein se ek hona parta hai.
     *
     * Yeh jaan bujh kar `is_tax_exempt` par bharosa nahi karta: manual line par
     * woh flag cashier ka bheja hua hai, to us par shart lagana koi shart nahi
     * thi — cashier kisi bhi dish ko exempt keh kar kitchen se ghayab kar sakta
     * tha (code review, 1 Sep 2026).
     *
     * @return string[] lowercase, trimmed
     */
    public static function feeNames(): array
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = ['delivery charges']; // client ka literal (setDeliveryCharge)
        foreach (['en', 'rur', 'ur'] as $loc) {
            try {
                $label = trim((string) __('pos.delivery_charges', [], $loc));
                if ($label !== '' && $label !== 'pos.delivery_charges') {
                    $names[] = mb_strtolower($label);
                }
            } catch (\Throwable $e) {
                // zaban file na mile to literal hi kaafi hai
            }
        }

        return $names = array_values(array_unique($names));
    }

    /**
     * Server-side faisla: kya YEH line kitchen se chhup sakti hai?
     *
     * Char shartein, aur charon server ki apni:
     *   • line MANUAL ho (koi product/service/deal resolve na hua ho),
     *   • order DELIVERY ho,
     *   • line ka naam server ke apne delivery-fee naamon mein se ho,
     *   • poore order mein aisi SIRF EK line ho.
     *
     * Client ka `skip_kitchen` sirf ek darkhwast hai. Exemption bhi is line par
     * server KHUD lagata hai (dekho forceExempt) — usay shart nahi banaya jata.
     *
     * @param  array  $item        client ki bheji hui cart line
     * @param  string|null $orderType  order ka type (delivery / dine_in / takeaway…)
     * @param  bool  $alreadyUsed  is order mein pehle hi ek line chhupp chuki hai?
     */
    public static function allowSkip(array $item, ?string $orderType, bool $alreadyUsed = false): bool
    {
        if ($alreadyUsed) {
            return false; // ek order mein sirf EK delivery fee
        }
        if (empty($item['skip_kitchen'])) {
            return false;
        }
        if (($item['item_type'] ?? null) !== 'manual') {
            return false; // product/deal/service kabhi nahi — asli dish chhup na sake
        }
        if (strtolower((string) ($orderType ?? '')) !== 'delivery') {
            return false;
        }

        $name = mb_strtolower(trim((string) ($item['item_name'] ?? $item['name'] ?? '')));

        return $name !== '' && in_array($name, self::feeNames(), true);
    }
}
