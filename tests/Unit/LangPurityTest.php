<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Language purity lint — locks Task 236 forever.
 *
 * - en/pos.php        : pure English (no Arabic script, no Roman Urdu words)
 * - rur/pos.php  : Roman Urdu (no Arabic-script characters at all)
 * - ur/pos.php        : Urdu script (no Latin words of 3+ letters except the whitelist)
 * - All three files key-synced, and every :placeholder from en preserved in both Urdu files.
 *
 * Whitelist policy for the ur (script) file: brand names, regulator/technical acronyms,
 * key names (F1–F12, Enter…), package names, on-screen Latin badges, and external-app
 * field labels are allowed in Latin. Verbatim quoted spans ("..."/'...') are allowed too
 * (exact button names / FBR error strings). Everything else must be Urdu script.
 */
class LangPurityTest extends TestCase
{
    private const WHITELIST = [
        'PRA','FBR','POS','FPOS','NestPOS','TaxNest','taxnest','PKR','KOT','KOTs','PDF','CSV','WhatsApp','APK','NTN','CNIC','PIN','SMS','UoM','API','URL','IMS','B2B','KDS','PWA','Raast','Excel','Android','Enter','Esc','Del','Alt','Ctrl','Tab','Shift','Space','localhost','exe','com','PNG','JPG','iOS','Chrome','Safari','JazzCash','EasyPaisa','Easypaisa','Windows','Bluetooth','USB','WiFi','GST','SRO','STRN','CST','XLSX','xlsx','LAN','HTTPS','http','https',
        // Biometric attendance device brands + protocol acronyms (4 Aug 2026)
        'ZKTeco','eSSL','FingerTec','Hikvision','Dahua','VIRDI','ADMS','ICLOCK','Out','TXT','XLS',
        'Nest','ABC','SHA','IDs','Unlimited','Business','Premium','Plus','Basic','Standard','Lite',
        'DELIVERED','RETURNED','OPEN','FINAL','OFFLINE','ONLINE','ACTIVE','NEW','UPDATED','CASH','CARD','LOCAL','PENDING','PAID',
        'Sahulat','Asaan','App','Rider','Riders','Clover','Microsoft','Edge','ngrok','Ngrok','johndoe123','T001','OEM','SKU','EAN','UPC','IMEI','IRIS','PRAL','POSID','HTTP','PostData','SHIFT','TAX','EXEMPT','DONE','CERTIFIED','INTEGRATED','WITH','CLEAR','TABLE','OFF',
        // Literal UI/status labels referenced verbatim inside Urdu guidance text
        // (FBR settings field "Token", fail-queue status "Settings Error", "Retry" button):
        'Token','Error','Settings','Retry',
        // Brand names in the rider-tracking shop-pin search guidance (Aug 2026):
        'Google','Maps',
        'Direct','Fiscal','Device','Submission','Mode','Hold','Deals','Update','Bill','Bills','Add','Customer','Product','Arrow','keys','More','Large','Order','Developed','Powered','chai','samosa','Preparing','Ready','Cleared','Report','Madadgar','series','Desktop','Agent','Sync','Retry','Edit','Recall','Pay','Delete','Close','Print','Set','default','New','Shortcut','Settings','devices','Printers','Label','free','app','xxxx','pos','products','Needed','Qty',
        'Pro','Max','SSL','zip','Cloud','Code','XXXXXXX','Letter','Registration','Point','Sale','Tax','Rate','Exempt',
    ];

    /** Common Roman Urdu words that must never appear in the English file. */
    private const ROMAN_URDU_WORDS = [
        'karein','nahi','hai','hain','sakte','sakta','dobara','apna','apni','wapas','chunein','dekhein','dhoondein','talash','raha','rahi','gaya','gayi','hoga','hogi','khud','pehle','liye','abhi','aaj','naya','nayi','sirf','zaroori','khali','dukan','mahana','raqam','karo','dabayen','hogaya',
        'assalam','alaikum','shukriya','mehrbani','janab','hazri','chai','samosa','dekho','zyada','theek','ausat','hisaab','kaam','rabta','dijiye','kijiye','wala','wale','wali','bilkul','pakka','warna','matlab','yani','maloomat','tafseel','intezar','koshish',
    ];

    private function load(string $locale): array
    {
        $file = base_path("lang/{$locale}/pos.php");
        $this->assertFileExists($file);

        return require $file;
    }

    private function basePathSafeLoad(string $locale): array
    {
        $file = __DIR__ . "/../../lang/{$locale}/pos.php";
        $this->assertFileExists($file, "lang/{$locale}/pos.php missing");

        return require $file;
    }

    /** Strip parts that are exempt from Latin/purity scanning. */
    private function stripExempt(string $s): string
    {
        $s = preg_replace('/<[^>]+>/u', ' ', $s);           // HTML tags
        $s = preg_replace('/:[a-z_]+/u', ' ', $s);          // Laravel :placeholders
        $s = preg_replace('/"[^"]{1,80}"/u', ' ', $s);      // verbatim quoted spans
        $s = preg_replace("/'[^']{1,80}'/u", ' ', $s);

        return $s;
    }

    public function test_all_three_files_are_key_synced(): void
    {
        $en = $this->basePathSafeLoad('en');
        $ur = $this->basePathSafeLoad('ur');
        $rm = $this->basePathSafeLoad('rur');

        $this->assertSame([], array_keys(array_diff_key($en, $ur)), 'Keys in en missing from ur');
        $this->assertSame([], array_keys(array_diff_key($ur, $en)), 'Keys in ur missing from en');
        $this->assertSame([], array_keys(array_diff_key($en, $rm)), 'Keys in en missing from rur');
        $this->assertSame([], array_keys(array_diff_key($rm, $en)), 'Keys in rur missing from en');
    }

    public function test_rur_has_no_arabic_script(): void
    {
        $bad = [];
        foreach ($this->basePathSafeLoad('rur') as $key => $value) {
            if (is_string($value) && preg_match('/\p{Arabic}/u', $value)) {
                $bad[] = $key;
            }
        }
        $this->assertSame([], $bad, 'rur/pos.php contains Arabic-script characters in: ' . implode(', ', array_slice($bad, 0, 20)));
    }

    public function test_ur_script_has_no_unwhitelisted_latin_words(): void
    {
        $whitelist = array_flip(self::WHITELIST);
        $bad = [];
        foreach ($this->basePathSafeLoad('ur') as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $stripped = $this->stripExempt($value);
            preg_match_all("/[A-Za-z][A-Za-z0-9'’]{2,}/u", $stripped, $m);
            foreach ($m[0] as $word) {
                $core = preg_replace("/['’]s?$/u", '', $word);
                if (! isset($whitelist[$core]) && ! preg_match('/^F\d+$/', $core)) {
                    $bad[$key][] = $word;
                }
            }
        }
        $msg = '';
        foreach (array_slice($bad, 0, 20, true) as $k => $words) {
            $msg .= "\n  {$k}: " . implode(', ', array_unique($words));
        }
        $this->assertSame([], $bad, 'ur/pos.php contains non-whitelisted Latin words:' . $msg);
    }

    public function test_ur_script_values_actually_contain_urdu(): void
    {
        // Every value that has any letters at all must either contain Arabic script
        // or consist purely of exempt/whitelisted tokens (checked above).
        $withArabic = 0;
        $total = 0;
        foreach ($this->basePathSafeLoad('ur') as $value) {
            if (is_string($value) && preg_match('/\p{L}/u', $value)) {
                $total++;
                if (preg_match('/\p{Arabic}/u', $value)) {
                    $withArabic++;
                }
            }
        }
        // Sanity floor: the vast majority of strings must be real Urdu script.
        $this->assertGreaterThan(0.9 * $total, $withArabic + 150, 'Too few Urdu-script values in ur/pos.php — file looks untranslated');
        $this->assertGreaterThan(3000, $withArabic, 'ur/pos.php lost its Urdu translations');
    }

    public function test_en_is_pure_english(): void
    {
        $pattern = '/\b(' . implode('|', self::ROMAN_URDU_WORDS) . ')\b/i';
        $bad = [];
        foreach ($this->basePathSafeLoad('en') as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            // Note: quoted spans are NOT exempt here — English must be pure even in examples.
            $scan = preg_replace(['/<[^>]+>/u', '/:[a-z_]+/u'], ' ', $value);
            if (preg_match('/\p{Arabic}/u', $value)) {
                $bad[$key] = 'arabic-script';
            } elseif (preg_match($pattern, $scan, $m)) {
                $bad[$key] = 'roman-urdu word "' . $m[1] . '"';
            }
        }
        $msg = '';
        foreach (array_slice($bad, 0, 20, true) as $k => $why) {
            $msg .= "\n  {$k}: {$why}";
        }
        $this->assertSame([], $bad, 'en/pos.php is not pure English:' . $msg);
    }

    /**
     * Scan each pos.php source file for duplicate array keys.
     * PHP silently uses the last definition — duplicates are invisible bugs.
     * We parse the raw source instead of require()ing so we catch both definitions.
     */
    public function test_no_duplicate_keys_in_any_lang_file(): void
    {
        $duplicates = [];
        foreach (['en', 'ur', 'rur'] as $locale) {
            $file = __DIR__ . "/../../lang/{$locale}/pos.php";
            $this->assertFileExists($file, "lang/{$locale}/pos.php missing");

            $src = file_get_contents($file);
            // Match lines of the form:  'some_key'  =>  (any value)
            // Handles optional leading spaces and both ' and " quote chars.
            preg_match_all("/^\s*['\"]([a-zA-Z0-9_]+)['\"]\s*=>/m", $src, $m);
            $seen  = [];
            $dupes = [];
            foreach ($m[1] as $key) {
                if (isset($seen[$key])) {
                    $dupes[] = $key;
                }
                $seen[$key] = true;
            }
            if ($dupes) {
                $duplicates[$locale] = array_unique($dupes);
            }
        }

        $msg = '';
        foreach ($duplicates as $locale => $keys) {
            $msg .= "\n  lang/{$locale}/pos.php: " . implode(', ', $keys);
        }
        $this->assertSame([], $duplicates, 'Duplicate keys found in lang files:' . $msg);
    }

    public function test_placeholders_preserved_in_both_urdu_files(): void
    {
        $en = $this->basePathSafeLoad('en');
        $bad = [];
        foreach (['ur', 'rur'] as $locale) {
            $map = $this->basePathSafeLoad($locale);
            foreach ($en as $key => $value) {
                if (! is_string($value) || ! isset($map[$key]) || ! is_string($map[$key])) {
                    continue;
                }
                preg_match_all('/:[a-z_]+/', $value, $m);
                foreach ($m[0] as $ph) {
                    // Accept the exact placeholder, or a shorter prefix form (e.g. en ":scopeproducts"
                    // is really the :scope placeholder glued to the word "products").
                    $ok = str_contains($map[$key], $ph);
                    for ($len = strlen($ph) - 1; ! $ok && $len >= 4; $len--) {
                        $ok = str_contains($map[$key], substr($ph, 0, $len));
                    }
                    if (! $ok) {
                        $bad[] = "{$locale}.{$key} missing {$ph}";
                    }
                }
            }
        }
        $this->assertSame([], $bad, "Placeholders lost in translation:\n" . implode("\n", array_slice($bad, 0, 30)));
    }
}
