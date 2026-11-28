<?php

namespace App\Services;

use App\Models\UpcomingProduct;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MadadgarUpcomingProductGuide
{
    public static function answer(string $question): ?string
    {
        $text = str_replace('-', ' ', Str::slug($question));
        $general = preg_match('/\b(?:upcoming products?|product roadmap|products? coming soon|new products? launch)\b/', $text);
        $availability = preg_match('/\b(?:upcoming|coming soon|beta|release[d]?|launch(?:ed)?|early access|available|live|kab|dastiyab)\b/', $text);
        if (!$general && !$availability) {
            return null;
        }
        if (!Schema::hasTable('upcoming_products')) {
            return $general ? 'Upcoming Products ka verified status abhi available nahin hai.' : null;
        }

        $products = UpcomingProduct::published()->orderBy('sort_order')->orderBy('id')->get(['slug', 'name', 'status']);
        $specific = $products->filter(function (UpcomingProduct $product) use ($text): bool {
            $name = preg_replace('/\btaxnest\s+/', '', str_replace('-', ' ', Str::slug($product->name)));
            $slug = str_replace('-', ' ', $product->slug);
            $aliases = array_filter([$name, $slug], fn ($phrase) => strlen($phrase) >= 5);

            return collect($aliases)->contains(fn ($phrase) => str_contains(' '.$text.' ', ' '.$phrase.' '));
        });
        if (!$general && $specific->isEmpty()) {
            return null;
        }

        $public = ($specific->isNotEmpty() ? $specific : $products)->take(8)
            ->map(fn (UpcomingProduct $product) => $product->name.' — '.UpcomingProduct::STATUSES[$product->status])
            ->implode('; ');
        if ($public === '') {
            return 'Upcoming Products par koi public product preview listed nahin hai. Updates ke liye /upcoming-products dekhein.';
        }

        return $public.'. Published details aur interest options /upcoming-products par hain. Package aur checkout ki availability alag se verify karein.';
    }
}
