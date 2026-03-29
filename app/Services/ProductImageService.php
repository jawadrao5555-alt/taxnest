<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProductImageService
{
    public static function fetchForProduct(string $productName, int $companyId): ?string
    {
        try {
            $query = $productName . ' food dish';
            $url = 'https://source.unsplash.com/400x400/?' . urlencode($query);

            $response = Http::timeout(8)->withOptions(['allow_redirects' => true])->get($url);

            if ($response->successful()) {
                $imageData = $response->body();
                $contentType = $response->header('Content-Type');

                if (!$contentType || !str_contains($contentType, 'image')) {
                    return self::fetchFromPicsum($companyId);
                }

                $ext = 'jpg';
                if (str_contains($contentType, 'png')) $ext = 'png';
                elseif (str_contains($contentType, 'webp')) $ext = 'webp';

                $filename = $companyId . '_auto_' . time() . '_' . substr(md5($productName), 0, 8) . '.' . $ext;

                Storage::disk('public')->put('products/' . $filename, $imageData);

                return $filename;
            }

            return self::fetchFromPicsum($companyId);
        } catch (\Exception $e) {
            Log::warning('ProductImageService fetch failed: ' . $e->getMessage());
            return self::fetchFromPicsum($companyId);
        }
    }

    private static function fetchFromPicsum(int $companyId): ?string
    {
        try {
            $seed = $companyId . '_' . time();
            $url = "https://picsum.photos/seed/{$seed}/400/400";

            $response = Http::timeout(8)->withOptions(['allow_redirects' => true])->get($url);

            if ($response->successful() && str_contains($response->header('Content-Type') ?? '', 'image')) {
                $filename = $companyId . '_auto_' . time() . '_' . substr(md5($seed), 0, 8) . '.jpg';
                Storage::disk('public')->put('products/' . $filename, $response->body());
                return $filename;
            }
        } catch (\Exception $e) {
            Log::warning('Picsum fallback failed: ' . $e->getMessage());
        }

        return null;
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
