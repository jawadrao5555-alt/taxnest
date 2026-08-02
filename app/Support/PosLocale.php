<?php

namespace App\Support;

/**
 * POS panel locales (owner, 2 Aug 2026): every user picks their own language.
 *  - 'en'  = pure English
 *  - 'rur' = Roman Urdu (default — the original POS tone)
 *  - 'ur'  = real Urdu script (اردو)
 *
 * MIGRATION NOTE: before Aug 2026, 'ur' meant Roman Urdu. Stored preferences
 * were mapped 'ur' → 'rur' by 2026_08_02_090000_split_urdu_script_locale, so a
 * stored 'ur' now ALWAYS means Urdu script (an explicit new choice).
 */
final class PosLocale
{
    public const ENGLISH = 'en';
    public const ROMAN_URDU = 'rur';
    public const URDU_SCRIPT = 'ur';

    public const ALL = [self::ENGLISH, self::ROMAN_URDU, self::URDU_SCRIPT];

    public const DEFAULT = self::ROMAN_URDU;

    /** Session key. v2: old 'pos_locale' values predate the ur→rur split ('ur'
     *  meant Roman back then) and must NOT be read as Urdu script. */
    public const SESSION_KEY = 'pos_locale_v2';

    public static function isValid(?string $lang): bool
    {
        return in_array($lang, self::ALL, true);
    }

    /** Normalize any stored/submitted value to a safe locale. */
    public static function normalize(?string $lang, string $default = self::DEFAULT): string
    {
        return self::isValid($lang) ? $lang : $default;
    }
}
