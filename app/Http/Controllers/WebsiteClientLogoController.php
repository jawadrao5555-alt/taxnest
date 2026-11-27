<?php

namespace App\Http\Controllers;

use App\Models\WebsiteClient;

class WebsiteClientLogoController extends Controller
{
    public function __invoke(int $client, int $revision)
    {
        $entry = WebsiteClient::query()->published()->whereKey($client)->where('revision', $revision)
            ->whereNotNull('logo_data')->firstOrFail();

        return self::imageResponse($entry);
    }

    public static function imageResponse(WebsiteClient $entry)
    {
        return response(base64_decode($entry->logo_data, true), 200, [
            'Content-Type' => $entry->logo_mime,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
