<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
final class PosTaxIntegrationEndpoints
{
    public static function url(string $kind, string $environment): string
    {
        abort_unless(in_array($environment,['sandbox','production'],true) && in_array($kind,['di','ims'],true),422);
        return match($kind.':'.$environment) {
            'di:sandbox'=>'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
            'di:production'=>'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
            'ims:sandbox'=>'https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData',
            'ims:production'=>'https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData',
        };
    }
    public static function checked(string $kind,string $environment,?string $value): string
    {
        $url=self::url($kind,$environment);
        if ($value !== null && $value !== '' && $value !== $url) {
            throw ValidationException::withMessages([$environment.'_url'=>'Use the displayed official '.$environment.' '.$kind.' endpoint. Tokens cannot be sent to another host or environment.']);
        }
        return $url;
    }
}
