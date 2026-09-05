<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProductImageService
{
    public static function fetchForProduct(string $productName, int $companyId): ?string
    {
        $company = \App\Models\Company::find($companyId);
        // Same resolver as every other preset consumer: a pre-split shop with no
        // business_category must still get ITS OWN image hints, not retail ones.
        $category = $company
            ? strtolower(PosFeatureService::resolveCategory($company))
            : 'retail';
        $hints = self::categoryKeywords($category);

        $cleanName = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $productName);
        $cleanName = trim(preg_replace('/\s+/', ' ', strtolower($cleanName)));
        $nameParts = array_slice(array_filter(explode(' ', $cleanName)), 0, 3);
        $nameKey = implode(',', $nameParts);

        $keywords = trim($nameKey . ',' . $hints, ',');

        $image = self::fetchFromLoremFlickr($keywords, $companyId, $productName);
        if ($image) {
            return $image;
        }

        $image = self::fetchFromUnsplash($keywords, $companyId, $productName);
        if ($image) {
            return $image;
        }

        return self::fetchFromPicsum($companyId, $productName);
    }

    private static function categoryKeywords(string $category): string
    {
        return match ($category) {
            'restaurant', 'cafe', 'food', 'hotel' => 'food,dish,meal,cuisine',
            'bakery'                              => 'bakery,bread,pastry,cake',
            'grocery', 'supermarket'              => 'grocery,food,market',
            'pharmacy', 'medical'                 => 'medicine,pharmacy,health',
            'clothing', 'apparel', 'fashion'      => 'fashion,clothing,apparel',
            'electronics', 'mobile', 'computer'   => 'electronics,gadget,device',
            'beauty', 'cosmetics', 'salon'        => 'beauty,cosmetics,product',
            'hardware', 'construction'            => 'tools,hardware,construction',
            'jewelry', 'jewellery'                => 'jewelry,gold,accessories',
            'auto', 'automobile', 'workshop'      => 'auto,car,vehicle',
            'stationery', 'books'                 => 'stationery,book,office',
            'toys'                                => 'toys,kids,play',
            'sports'                              => 'sports,fitness,gear',
            // ---- PRA service families (Sep 2026) ----
            'courier'                             => 'courier,parcel,delivery',
            'photography'                         => 'photography,camera,studio',
            'event_management'                    => 'event,celebration,decoration',
            'travel_agent'                        => 'travel,tour,tourism',
            'rent_a_car'                          => 'car,rental,vehicle',
            'property_dealer'                     => 'property,realestate,house',
            'advertising'                         => 'advertising,billboard,marketing',
            'it_services'                         => 'computer,software,technology',
            'security_services'                   => 'security,guard,cctv',
            'retail', 'shop', 'store', ''         => 'product,retail,shop',
            default                               => 'product',
        };
    }

    private static function fetchFromLoremFlickr(string $keywords, int $companyId, string $productName): ?string
    {
        try {
            $url = 'https://loremflickr.com/400/400/' . urlencode($keywords);
            $response = Http::timeout(10)->withOptions(['allow_redirects' => true])->get($url);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type') ?? '';
                if (str_contains($contentType, 'image')) {
                    return self::storeImage($response->body(), $contentType, $companyId, $productName);
                }
            }
        } catch (\Exception $e) {
            Log::warning('LoremFlickr fetch failed: ' . $e->getMessage());
        }
        return null;
    }

    private static function fetchFromUnsplash(string $keywords, int $companyId, string $productName): ?string
    {
        try {
            $url = 'https://source.unsplash.com/featured/400x400/?' . urlencode(str_replace(',', ' ', $keywords));
            $response = Http::timeout(8)->withOptions(['allow_redirects' => true])->get($url);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type') ?? '';
                if (str_contains($contentType, 'image')) {
                    return self::storeImage($response->body(), $contentType, $companyId, $productName);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Unsplash fallback failed: ' . $e->getMessage());
        }
        return null;
    }

    private static function fetchFromPicsum(int $companyId, string $productName = ''): ?string
    {
        try {
            $seed = $companyId . '_' . substr(md5($productName . time()), 0, 8);
            $url = "https://picsum.photos/seed/{$seed}/400/400";

            $response = Http::timeout(8)->withOptions(['allow_redirects' => true])->get($url);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type') ?? '';
                if (str_contains($contentType, 'image')) {
                    return self::storeImage($response->body(), $contentType, $companyId, $productName);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Picsum fallback failed: ' . $e->getMessage());
        }
        return null;
    }

    private static function storeImage(string $body, string $contentType, int $companyId, string $productName): string
    {
        $ext = 'jpg';
        if (str_contains($contentType, 'png'))  $ext = 'png';
        elseif (str_contains($contentType, 'webp')) $ext = 'webp';

        $filename = $companyId . '_auto_' . time() . '_' . substr(md5($productName . microtime()), 0, 8) . '.' . $ext;
        Storage::disk('public')->put('products/' . $filename, $body);
        return $filename;
    }

    public static function refreshImage(int $productId, int $companyId): ?string
    {
        $product = \App\Models\PosProduct::where('company_id', $companyId)->find($productId);
        if (!$product) return null;

        if ($product->image && str_contains($product->image, '_auto_')) {
            Storage::disk('public')->delete('products/' . $product->image);
        }

        $newImage = self::fetchForProduct($product->name, $companyId);
        if ($newImage) {
            $product->update(['image' => $newImage]);
        }

        return $newImage;
    }
}
