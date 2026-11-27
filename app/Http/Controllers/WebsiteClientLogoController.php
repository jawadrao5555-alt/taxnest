<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\WebsiteClient;
use App\Services\WebsiteClientLogo;
use Illuminate\Http\Response;

class WebsiteClientLogoController extends Controller
{
    public function __invoke(int $client, int $revision): Response
    {
        $entry = WebsiteClient::publicEntry($client, $revision);

        return self::respond($entry ? WebsiteClientLogo::publicImage($entry) : null);
    }

    public function preview(int $company): Response
    {
        AdminUser::requireReleaseAdministrator();
        $source = WebsiteClient::companySources()->addSelect('companies.product_type', 'companies.logo_path', 'companies.di_branding')
            ->find($company);

        return self::respond(WebsiteClientLogo::companyImage($source));
    }

    public static function imageResponse(WebsiteClient $entry): Response
    {
        return self::respond(WebsiteClientLogo::manualImage($entry));
    }

    /** @param array{bytes: string, mime: string}|null $image */
    private static function respond(?array $image): Response
    {
        return response($image['bytes'] ?? '', $image ? 200 : 404, [
            'Content-Type' => $image['mime'] ?? 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
