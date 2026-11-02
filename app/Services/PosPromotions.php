<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\PosPromotion;
use App\Models\RestaurantOrder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Scheduled product offers. Bundle price wins; offers and manual discounts never stack. */
class PosPromotions
{
    public static function enabled(Company $company): bool
    {
        return Schema::hasTable('pos_promotions') && PosFeatureService::moduleRelevant($company, 'deals_enabled')
            && PosFeatureService::planAllows($company, 'deals_enabled');
    }

    public static function quotes(Company $company, iterable $products, ?CarbonImmutable $at = null): array
    {
        if (!self::enabled($company)) return [];
        $now = ($at ?? CarbonImmutable::now('UTC'))->utc()->format('Y-m-d H:i:s');
        $offers = PosPromotion::where('company_id', $company->id)->where('is_active', true)
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->orderByDesc('priority')->orderByDesc('percent')->orderBy('id')->get();
        $result = [];
        foreach ($products as $product) {
            if ((int) $product->company_id !== (int) $company->id || !$product->is_active) continue;
            $offer = $offers->first(fn ($o) => $o->applies_to === 'all' || in_array((int) $product->id, array_map('intval', $o->product_ids ?? []), true));
            if (!$offer) continue;
            $result[$product->id] = ['id'=>(int)$offer->id,'title'=>$offer->title,'percent'=>(float)$offer->percent,
                'priority'=>(int)$offer->priority,'timezone'=>$offer->timezone,'starts_at'=>$offer->starts_at,'ends_at'=>$offer->ends_at,
                'version'=>$offer->version(),'product_id'=>(int)$product->id,'unit_price'=>(float)$product->price];
        }
        return $result;
    }

    private static function same(?array $posted, ?array $current): bool
    {
        if (!$posted || !$current) return !$posted && !$current;
        return (int) ($posted['id'] ?? 0) === $current['id']
            && (int) ($posted['product_id'] ?? 0) === $current['product_id']
            && hash_equals($current['version'], (string) ($posted['version'] ?? ''))
            && abs((float) ($posted['unit_price'] ?? -1) - $current['unit_price']) < .001
            && abs((float) ($posted['percent'] ?? -1) - $current['percent']) < .001;
    }

    public static function stale(): never
    {
        throw ValidationException::withMessages(['items' => __('pos_promotions.stale')]);
    }

    /** Operates on canonical resolved rows. Legacy signed/queued non-offer history is not repriced. */
    public static function apply(array $rows, array $posted, Company $company, Request $request, bool $held = false, bool $signedHistory = false): array
    {
        if ($signedHistory) return $rows;
        if (!Schema::hasTable('pos_promotions') && !collect($posted)->contains(fn ($r) => !empty($r['promotion_quote']))) return $rows;
        $products = PosProduct::where('company_id', $company->id)
            ->whereIn('id', collect($rows)->where('type','product')->pluck('item_id')->filter())->get()->keyBy('id');
        $quotes = self::quotes($company, $products);
        $offline = $request->filled('offline_queued_at');
        $hasOffer = false;
        foreach ($rows as $index => &$row) {
            $input = $posted[$index] ?? [];
            $postedQuote = is_array($input['promotion_quote'] ?? null) ? $input['promotion_quote'] : null;
            if ($offline) {
                if ($postedQuote) throw ValidationException::withMessages(['items' => __('pos_promotions.online_only')]);
                continue;
            }
            if (($row['type'] ?? '') !== 'product') {
                if ($postedQuote) self::stale();
                continue; // Deals keep their own bundle price; services/manual lines are outside product offers.
            }
            $quote = $quotes[$row['item_id']] ?? null;
            // A held order is not paid yet: waiter/legacy hold clients may obtain the current offer server-side.
            // Explicit cart quotes are still checked; direct payments always require an exact quote.
            if ((!$held || $postedQuote) && !self::same($postedQuote, $quote)) self::stale();
            if (!$quote) continue;
            $hasOffer = true;
            if ((float) ($row['item_discount_amount'] ?? 0) > 0 || (float) ($input['item_discount_value'] ?? 0) > 0) {
                throw ValidationException::withMessages(['items' => __('pos_promotions.no_stack')]);
            }
            if (!$held && abs((float) $row['price'] - $quote['unit_price']) > .001) self::stale();
            $gross = round((float) $row['quantity'] * $quote['unit_price'], 2);
            $discount = round($gross * $quote['percent'] / 100, 2);
            $row['price'] = $quote['unit_price']; $row['lineTotal'] = round($gross - $discount, 2);
            $row['promotion_discount'] = $discount;
            $row['promotion_snapshot'] = $quote + ['gross'=>$gross,'discount'=>$discount,'net'=>$row['lineTotal']];
        }
        unset($row);
        if ($hasOffer && ((float) $request->input('discount_value', 0) > 0 || (float) $request->input('discount_amount', 0) > 0)) {
            throw ValidationException::withMessages(['discount_value' => __('pos_promotions.no_stack')]);
        }
        return $rows;
    }

    /** Recheck under the company lock shared with offer edits; never reprice a prepared payment. */
    public static function assertRowsCurrent(array $rows, Company $company): void
    {
        if (!Schema::hasTable('pos_promotions')) return;
        $products=PosProduct::where('company_id',$company->id)->whereIn('id',collect($rows)->where('type','product')->pluck('item_id'))->get();
        $quotes=self::quotes($company,$products);
        foreach($rows as $row) {
            if (($row['type'] ?? '')==='product' && !self::same($row['promotion_snapshot'] ?? null,$quotes[$row['item_id']] ?? null)) self::stale();
        }
    }

    public static function heldRows(array $rows, Company $company, Request $request): array
    {
        if (!Schema::hasTable('pos_promotions') && !collect($request->input('items',[]))->contains(fn ($r)=>!empty($r['promotion_quote']))) return $rows;
        $canonical=array_map(fn($r)=>['type'=>$r['item_type'],'item_id'=>$r['item_id'],'price'=>$r['unit_price'],
            'quantity'=>$r['quantity'],'lineTotal'=>$r['subtotal'],'item_discount_amount'=>$r['item_discount_amount'] ?? 0],$rows);
        $priced=self::apply($canonical,$request->items,$company,$request,true);
        foreach($priced as $index=>$line) {
            if (isset($line['promotion_snapshot'])) {
                $rows[$index]['unit_price']=$line['price'];$rows[$index]['subtotal']=$line['lineTotal'];
                $rows[$index]['promotion_snapshot']=$line['promotion_snapshot'];$rows[$index]['promotion_discount']=$line['promotion_discount'];
            }
        }
        return $rows;
    }

    /** Never silently change a held order's agreed amount at payment time. */
    public static function assertHeldCurrent(RestaurantOrder $order, Company $company): void
    {
        if (!Schema::hasColumn('restaurant_order_items','promotion_snapshot')) return;
        $order->loadMissing('items');
        $products = PosProduct::where('company_id', $company->id)
            ->whereIn('id',$order->items->where('item_type','product')->pluck('item_id'))->get();
        $quotes = self::quotes($company,$products);
        foreach ($order->items as $line) {
            if ($line->item_type !== 'product') continue;
            if (!self::same($line->promotion_snapshot, $quotes[$line->item_id] ?? null)) self::stale();
        }
    }
}
