<?php

namespace App\Services;

final class PosCustomerPhoneIdentity
{
    public static function canonical(?string $phone): string
    {
        return PkPhone::normalize($phone) ?? preg_replace('/\D+/', '', (string) $phone);
    }

    /** Match the existing customer-create mutex for equivalent typed formats. */
    public static function creationLockKeys(int|string $companyId, string $phone): array
    {
        $canonical = self::canonical($phone);
        $digits = preg_replace('/\D+/', '', $phone);
        $variants = [$digits, $canonical, '00'.$canonical];
        if (str_starts_with($canonical, '92')) {
            $national = substr($canonical, 2);
            $variants = array_merge($variants, ['0'.$national, $national, '920'.$national, '00920'.$national]);
        }
        $keys = array_map(fn ($number) => 'poscust:'.$companyId.':'.$number, array_unique($variants));
        sort($keys, SORT_STRING);
        return $keys;
    }
}
