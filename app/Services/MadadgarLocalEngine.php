<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Madadgar LOCAL answer engine (task: hybrid local answer layer, Aug 2026).
 *
 * Pure-PHP, OpenAI-free retrieval over resources/madadgar/knowledge-pos.md:
 *  - Roman-Urdu-aware normalization (spelling variants, synonym folding,
 *    stopwords) applied to BOTH the question and the KB.
 *  - Curated FAQ layer first (hand-written answers for the most common
 *    questions), then IDF-weighted section/line retrieval.
 *  - LOW CONFIDENCE => null (decline). A confident-but-wrong answer is worse
 *    than the polite fallback, so thresholds are deliberately conservative.
 *  - Answer cache keyed on (KB content hash + normalized question) — any KB
 *    edit changes the hash and silently invalidates every stale entry.
 *  - Rule-based escalation intent detection for modes where OpenAI's
 *    escalate_to_admin tool isn't in the loop. Card only — the
 *    FeatureSuggestion row is still created EXCLUSIVELY on the Haan endpoint.
 *
 * Answers are vocabulary/profile aware. Cache keys include that context so a
 * restaurant answer can never be replayed to a pharmacy or services shop.
 */
class MadadgarLocalEngine
{
    private const CACHE_TTL_DAYS = 30;

    /** Runtime memos (per request), keyed by product ('pos' / 'fbrpos'). */
    private static array $index = [];
    private static array $kbHash = [];

    // ==================== PUBLIC API ====================

    /** md5 of the KB file — cache keys ride on this so KB edits invalidate. */
    public static function kbHash(string $product = 'pos'): string
    {
        if (!isset(self::$kbHash[$product])) {
            self::$kbHash[$product] = md5(self::kbRaw($product));
        }

        return self::$kbHash[$product];
    }

    /**
     * Try to answer locally. Returns plain-text Roman Urdu or NULL when not
     * confident (caller decides: OpenAI / polite fallback).
     */
    public static function answer(
        string $question,
        string $product = 'pos',
        array $vocabulary = [],
        ?array $availableModules = null
    ): ?string
    {
        $tokens = self::questionTokens($question);
        if (empty($tokens)) {
            return null;
        }

        if (self::asksForUnavailableModule($tokens, $availableModules)) {
            return (string) __('pos.feature_not_for_business', [], 'rur');
        }

        $faq = self::faqAnswer($tokens, $product, $vocabulary);
        if ($faq !== null) {
            return $faq;
        }

        return self::retrieve($tokens, $product, $vocabulary);
    }

    /** Cached answer for this question under the CURRENT KB hash, or null. */
    public static function cachedAnswer(
        string $question,
        string $product = 'pos',
        array $vocabulary = [],
        ?array $availableModules = null
    ): ?string
    {
        $key = self::cacheKey($question, $product, $vocabulary, $availableModules);
        if ($key === null) {
            return null;
        }
        try {
            $hit = Cache::get($key);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($hit) && isset($hit['text']) && trim((string) $hit['text']) !== ''
            ? (string) $hit['text'] : null;
    }

    /**
     * Store a successful plain-text answer. Escalation cards and error replies
     * must NEVER reach this method (caller guarantees).
     */
    public static function cacheAnswer(
        string $question,
        string $text,
        string $source = 'local',
        string $product = 'pos',
        array $vocabulary = [],
        ?array $availableModules = null
    ): void
    {
        // Hard guard: ONLY deterministic KB-derived local answers may enter the
        // shared cache. OpenAI output can embed per-session/user context and
        // must never be replayed across users or companies.
        if ($source !== 'local') {
            return;
        }
        $key = self::cacheKey($question, $product, $vocabulary, $availableModules);
        if ($key === null || trim($text) === '') {
            return;
        }
        try {
            Cache::put($key, ['text' => $text, 'src' => $source], now()->addDays(self::CACHE_TTL_DAYS));
        } catch (\Throwable $e) {
            // cache is an optimization — never let it break the chat turn
        }
    }

    /**
     * Rule-based escalation trigger for turns OpenAI didn't handle.
     * Returns a pending card {title, summary, kind} or null.
     *
     * Triggers when:
     *  - the user explicitly asks to inform the admin/team, OR
     *  - clear complaint wording (shikayat/complaint/bug), OR
     *  - $noAnswer (fallback turn): always offer the card so the user is
     *    never at a dead end.
     */
    public static function ruleEscalation(string $question, bool $noAnswer): ?array
    {
        $q = mb_strtolower(trim($question), 'UTF-8');
        if ($q === '') {
            return null;
        }

        $explicitAdmin = (bool) preg_match(
            '/(admin|malik|owner|team)\s*(ko|se|tak)?\s*\S{0,20}\s*(bata|batao|batay|bhej|bhijwa|bhejo|pohncha|inform|report|likh|forward)|escalate/u',
            $q
        );
        $strongComplaint = (bool) preg_match('/shikayat|complaint|\bbug\b|\bbugs\b/u', $q);

        if (!$explicitAdmin && !$strongComplaint && !$noAnswer) {
            return null;
        }

        $isFeature = (bool) preg_match(
            '/feature|request|demand|suggest|tajweez|hona\s+chah?iy?e|hona\s+chahye|add\s+(karo|karen|karein|hona)|(nay[ai]|new)\s+(option|feature|button|report|page)|banwa/u',
            $q
        );

        return [
            'title' => mb_substr(trim($question), 0, 150),
            'summary' => mb_substr(trim($question), 0, 1500),
            'kind' => $isFeature ? 'feature_request' : 'problem',
        ];
    }

    /**
     * Polite Roman-Urdu "I don't know" — used when no local answer and OpenAI
     * is off/unavailable. Never a dead end: WhatsApp + escalation offered
     * (the pending card is attached separately, cap permitting).
     */
    public static function fallbackText(): string
    {
        return 'Maazrat, yeh baat mujhe theek se maloom nahi. Aap Madadgar ke menu se WhatsApp support par rabta kar sakte hain, ya apni baat admin team ko bhijwa sakte hain — team khud aap se raabta karegi.';
    }

    // ==================== CACHE KEY ====================

    /** Null when the question is unsafe to cache (follow-up / too thin). */
    private static function cacheKey(
        string $question,
        string $product = 'pos',
        array $vocabulary = [],
        ?array $availableModules = null
    ): ?string
    {
        $q = mb_strtolower(trim($question), 'UTF-8');
        if (mb_strlen($q) < 6) {
            return null;
        }
        // Follow-up-ish questions depend on chat context — never cache.
        if (preg_match('/\b(phir|uska|uski|iska|iski|iske|uske|ye\s+wala|wo\s+wala|upar\s+wala|pichla|dobara\s+batao|aur\s+batao)\b/u', $q)
            || preg_match('/^\s*(aur|phir|to\b)/u', $q)) {
            return null;
        }
        $tokens = self::questionTokens($question);
        if (count($tokens) < 2) {
            return null;
        }
        sort($tokens);

        // Per-product isolation is automatic: kbHash($product) differs per KB
        // file, so 'pos' and 'fbrpos' answers can never cross-contaminate.
        $context = self::contextKey($vocabulary, $availableModules);
        return 'madadgar_ans:'.md5(self::kbHash($product).'|'.$context.'|'.implode(' ', $tokens));
    }

    // ==================== FAQ LAYER ====================

    /**
     * Curated FAQ: token patterns (canonical form!) => polished answer.
     * First pattern fully contained in the question tokens wins, so more
     * specific patterns must come first.
     */
    private static function faqAnswer(array $tokens, string $product = 'pos', array $vocabulary = []): ?string
    {
        $set = array_flip($tokens);
        foreach (self::faqs($product) as $faq) {
            foreach ($faq['p'] as $pattern) {
                $ok = true;
                foreach ($pattern as $t) {
                    if (!isset($set[$t])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return self::fillPlaceholders($faq['a'], $vocabulary);
                }
            }
        }

        return null;
    }

    private static function faqs(string $product = 'pos'): array
    {
        if ($product === 'fbrpos') {
            return self::fbrFaqs();
        }

        return [
            // --- specific before generic ---
            ['p' => [['rider', 'settle'], ['rider', 'cash'], ['rider', 'khata']],
             'a' => "Rider ka cash settle karne ke liye sale screen ka GREEN \"Deliveries\" button (Delivery Board) ya /pos/deliveries kholein — rider ke card par \"Settle Cash\" dabayen, jo bills settle ho rahe hain tick karein, note (optional) likh kar \"Confirm Settlement\". Partial settlement bhi ho sakta hai. Day-close par rider ka poora khata (owed vs settled) nazar aata hai."],

            ['p' => [['barcode', 'sticker'], ['barcode', 'label'], ['product', 'label']],
             'a' => "{item} barcode stickers /pos/products/labels se print hote hain — sticker par naam, price, barcode aur SKU aata hai. \"Columns\" box se sheet ke columns (1-5) set karein aur Print dabayen."],

            ['p' => [['kot', 'nahi']],
             'a' => "KOT kitchen par na aaye to yeh check karein:\n1. /pos/restaurant/kitchen-settings par \"KDS Auto-Print\" — agar ON hai to KOT sirf KDS wali device se print hota hai; KDS use nahi karte to isay OFF karein.\n2. \"Kitchen Printer\" ON ho aur Desktop Agent PC par chalta ho.\n3. Setting badalne ke baad sale screen refresh (F5) karein."],

            ['p' => [['tax', 'nahi', 'bill'], ['tax', 'chhupana'], ['tax', 'hide']],
             'a' => "Receipt par tax dikhana/chhupana /pos/receipt-settings se hota hai — \"Show Tax\" toggle (PRA aur Local tab alag alag hain, sahi tab dekhein). Show Tax OFF ho to customer copy par sirf grand TOTAL nazar aata hai; lekin PRA ko tax hamesha POORA submit hota hai."],

            ['p' => [['tax', 'price'], ['tax', 'set']],
             'a' => "Tax rates /pos/features par set hote hain — \"Cash Rate (%)\" aur \"Card / Digital Rate (%)\" (aam default: cash 16%, card 8%). Kisi ek product ko tax-free karna ho to product edit mein \"Tax Exempt\" ON karein. Tax pricing ke 3 modes /pos/customize se milte hain: Standard (tax upar se), Menu Rate Final — Sab Same, aur Menu Rate Final — Card Bachat."],

            ['p' => [['opening', 'cash']],
             'a' => "Opening Cash din ke shuru mein Dashboard ke \"Opening Cash\" card par enter hota hai — drawer ka cash amount (aur chahein to note) likh kar Save karein. Cashier bhi kar sakta hai; sirf aaj ke liye hota hai aur din close hone ke baad lock ho jata hai."],

            ['p' => [['dayclose'], ['zreport']],
             'a' => "Day close /pos/day-close par hota hai:\n1. /pos/day-close kholein — Z-report ka khulasa nazar aayega.\n2. Cash reconciliation mein GINA HUA cash dalein (variance khud nikalta hai).\n3. Rider khata dekh kar \"Close Day\" dabayen.\nKhule orders ke sath din band nahi hota — pehle unhe final ya cancel karein. Auto day-close ke liye /pos/customize par \"Auto Day-Close (24h)\" ON karein. Z-report PDF (A4) aur Thermal dono par print hoti hai; purani closes isi page par \"View\" se milti hain."],

            ['p' => [['silent']],
             'a' => "Silent printing (bina print dialog ke) ke liye:\n1. Desktop Sync Agent install karein (download /pos/agent se).\n2. /pos/printer-settings par \"Enable Silent Printing\" ON karein.\n3. \"Bill / Receipt Printer\" aur \"Kitchen (KOT) Printer\" dropdowns se printers chunein — list Agent se aati hai.\nSetting badalne ke baad sale screen refresh (F5) karein. List khali ho to purana agent hai — Agent Setup se naya ZIP le kar install.bat chalayen."],

            ['p' => [['print', 'lagana'], ['print', 'setup'], ['print', 'install'], ['print', 'connect']],
             'a' => "Printer lagane ka tareeqa:\n1. Printer PC se connect kar ke driver install karein.\n2. Browser ke print dialog mein wohi printer select karein.\n3. /pos/receipt-settings par paper size (80mm ya 58mm) set karein.\nNestPOS har aam thermal printer (USB, network ya Bluetooth) ke sath chalta hai. Bina dialog ke seedha print chahiye to Desktop Sync Agent install kar ke /pos/printer-settings par Silent Printing ON karein."],

            ['p' => [['makefinal'], ['promote'], ['provisional', 'final'], ['local', 'bill', 'final']],
             'a' => "Local/provisional bill ko final karne ke liye sale screen par F10 dabayen → bill select karein → Enter (Make Final) → method chunein: Cash (1), Card (2), ya \"Finalize LOCAL — don't send to PRA\" (3 ya L). Cash/Card se promote par PRA fiscal serial milta hai aur monthly quota use hota hai — promote sirf USI mahine ke andar ho sakta hai. /pos/transactions par bhi local bill ke saamne \"Submit to PRA\" button hota hai."],

            ['p' => [['provisional'], ['local', 'bill'], ['f9']],
             'a' => "Provisional (local) bill: sale screen par \"Save Provisional\" button ya F9 — bill L-series par save hota hai, PRA ko report NAHI hota aur quota nahi katta. Dekhne ke liye F10 modal (search bhi hai) ya /pos/local-bills portal. Baad mein F10 se Make Final (promote) kar sakte hain — Cash (1), Card (2), ya Finalize LOCAL (3/L)."],

            ['p' => [['discount']],
             'a' => "Discount BILL level par lagta hai — cart ke neeche % Discount button ya D key (Alt+D har jagah chalta hai). Percentage aur Rs amount dono ho sakte hain, magar discount limit lagti hai (default subtotal ka 50%) — limit se zyada par Manager PIN ka modal khulta hai, PIN dalne par usi bill ke liye override ho jata hai."],

            ['p' => [['password']],
             'a' => "Password bhool jayein to /pos/login par \"Forgot Password\" dabayen — email par OTP aata hai, phir naya password set karein. Apna password badalna ho to /pos/my-profile par Current Password + New Password + Confirm se change hota hai. Team members ke passwords company admin /pos/team par dekh sakta hai."],

            ['p' => [['login']],
             'a' => "POS login /pos/login par hota hai — Email, Phone, Username, CNIC ya NTN se (CNIC/NTN se company ka admin login hota hai). 5 ghalat koshishon par login thori der ke liye lock ho jata hai — kuch minute intezar kar ke dobara koshish karein. Password bhool gaye hon to \"Forgot Password\" se OTP ke zariye reset karein. Kisi aur panel par login karne se \"Invalid credentials\" aayega — sirf /pos/login use karein."],

            ['p' => [['scanner'], ['barcode']],
             'a' => "Barcode scanner ke liye alag setup nahi chahiye — koi bhi USB/Bluetooth scanner jo keyboard ki tarah type karta hai seedha chal jata hai. Sale screen ke search box mein scan karein — exact match foran cart mein chala jata hai. Product barcode stickers /pos/products/labels se print hote hain."],

            ['p' => [['product', 'add'], ['product', 'naya'], ['product', 'banana']],
             'a' => "Naya {item} /pos/products par banta hai — \"+ Add {item}\" dabayen: naam aur Price zaroori hain; Category, SKU, Barcode, Cost Price, Tax Rate/Tax Exempt, Unit, Opening Stock, Low-Stock Alert aur Image bhi de sakte hain. Bohat se {items} ek sath dalne hon to isi page se Excel template download kar ke \"Upload & Import\" karein (CSV use NA karein — barcode kharab ho jate hain)."],

            ['p' => [['stock'], ['inventory']],
             'a' => "Stock /pos/inventory/adjust se update hota hai — product chunein → Add (+) / Remove (−) / Set Exact (=) → quantity → Reason (New Purchase, Physical Count, Damaged/Expired waghera) → save. Sale par stock khud katta hai. Poora stock /pos/inventory/stock par dikhta hai (search + Low/Out filter), har tabdeeli ka log /pos/inventory/movements par. Inventory Tracking /pos/features se ON/OFF hota hai."],

            ['p' => [['logo']],
             'a' => "Business logo /pos/business-profile par upload hota hai (wahin se remove bhi) — yehi logo receipt par aata hai. Receipt par logo ka size /pos/receipt-settings ke \"Logo Style\" (Compact/Large) se badlein. Receipt ka default style bold + center logo hai; chahen to plain style bhi choose kar sakte hain."],

            ['p' => [['58mm'], ['80mm'], ['paper', 'size']],
             'a' => "Receipt paper size /pos/receipt-settings par set hota hai — 80mm (Standard) ya 58mm (Compact); PRA Settings se bhi badal sakte hain. Agar print ke aage/peeche LAMBA khali kaghaz nikle to Windows mein printer ki Printing Preferences kholein aur paper size A4 se badal kar 80mm Receipt (ya POS80) set kar dein."],

            ['p' => [['table', 'order'], ['dine', 'order']],
             'a' => "Dine-In order ka tareeqa: sale screen par order type \"Dine In\" chunein → table picker khud khulta hai, table select karein → items dalein → \"Send to Kitchen\" (KOT) ya Hold (F5). Khana ban jaye to TABLE board (Alt+B ya TABLE button) mein table par click kar ke \"FINAL karo\" se payment karein — table khud free ho jata hai. Tables /pos/restaurant/table-management par banti hain."],

            ['p' => [['table', 'add'], ['floor']],
             'a' => "Tables /pos/restaurant/table-management par banti hain: \"+ Add Floor\" se floor banayen (jaise Ground Floor), phir \"+ Add Table\" se table (number jaise T1, seats 1-50). Table delete card ke × button se. Iske liye Table Management feature ON hona chahiye (/pos/features; Pro/Unlimited package ya trial)."],

            ['p' => [['rider']],
             'a' => "Riders /pos/riders par bante hain — naam, phone, CNIC, vehicle number; \"Create Login\" se rider ka login banayen (rider sirf /pos/rider portal dekhta hai, team limit mein nahi ginta). Rider assign bill banne ke BAAD hota hai — sale screen ke GREEN \"Deliveries\" button (Delivery Board) ya /pos/deliveries se. Wahin se Dispatch/Delivered mark aur \"Settle Cash\" bhi hota hai. Riders feature Business, Pro aur Unlimited packages mein shamil hai."],

            ['p' => [['team']],
             'a' => "Naya team member /pos/team par add hota hai — naam, email, phone, password aur role chun kar save karein. Roles: Manager (admin jaisa) aur Cashier (sirf billing) package ki account limit mein ginte hain; Kitchen, Waiter, Rider aur Delivery Manager FREE hain (limit mein nahi ginte). Cashier ON/OFF toggle aur per-cashier PRA reporting toggle bhi isi page par hain."],

            ['p' => [['package'], ['upgrade']],
             'a' => "Packages (saalana): Starter basic package hai; Business se Restaurant, Delivery Riders, QR Menu aur unlimited final bills milte hain; Pro se Staff Hazri, 20 team accounts aur 3 branches bhi milti hain; Unlimited mein team accounts unlimited aur 5 branches hoti hain. WhatsApp Bill, Rider Live Tracking aur Caller ID alag paid add-ons hain. Tafseel aur payment /pos/billing par dekhein."],

            ['p' => [['limit', 'khatam'], ['limit', 'poori'], ['limit', 'bill'], ['limit']],
             'a' => "Sirf FINAL bills monthly quota mein ginte hain — provisional (F9) bills FREE hain jab tak promote na hon. Bills ki limit khatam ho jaye to filhal provisional bills banayen (baad mein promote karein) ya /pos/billing se package upgrade karein. Quota har mahine reset hota hai. Team accounts ki limit poori ho to bhi upgrade hi rasta hai."],

            ['p' => [['product', 'mil', 'nahi'], ['search', 'nahi'], ['product', 'nahi', 'search']],
             'a' => "Search naam ke SHURU se chalti hai — {example} ke naam ka pehla lafz shuru se likhein; baqi lafz naam ke kisi bhi lafz se mil jate hain. {item} phir bhi na mile to /pos/products par dekhein — inactive ya sale-screen toggle OFF to nahi. Grid ghayab ho to \"Show All {items}\" toggle ON karein — search har category mein dhoondti hai."],

            ['p' => [['shortcut']],
             'a' => "Aham keyboard shortcuts (sale screen): F1 = madad, F2 = search, F4 = cart khali, F5 = Hold, F8 = PAY (phir 1 = Cash, 2 = Card), F9 = Save Provisional, F10 = local bills, F11 = failed bills, Alt+R = reprint, Alt+1/Alt+2 = one-tap Cash/Card, Alt+B = Table board, D = bill discount, T = selected item ka tax ON/OFF, Esc = modal band. F1 dabane par poori list screen par aa jati hai."],

            ['p' => [['reprint'], ['dobara', 'print'], ['purana', 'bill', 'print']],
             'a' => "Kisi bhi bill ki receipt dobara print karne ke liye sale screen par Alt+R dabayen — aaj ke SAB bills ki list aati hai (PRA, Local, Failed sab); bill par click ya Enter se print. Products ke neeche \"Akhri Bills\" patti se bhi ek click par reprint hota hai. Purane dino ke bills /pos/transactions se kholein — wahan se receipt/PDF aur share link bhi milta hai."],

            ['p' => [['offline'], ['internet', 'nahi'], ['net', 'nahi']],
             'a' => "Internet chala jaye to bill offline queue mein save hota hai aur net wapas aane par khud PRA ko chala jata hai — quota dobara nahi katta. Sale screen pehli dafa load ke baad computer par mehfooz ho jati hai, is liye slow ya band net par bhi turant khulti hai. Fiscal Device mode mein Desktop Agent ka chalta hona zaroori hai."],

            ['p' => [['f11'], ['failed'], ['reject']],
             'a' => "PRA se reject/failed bills F11 modal mein aate hain — wahan se \"Edit & Retry\" karein (bill wapas cart mein aata hai, theek kar ke dobara bhejein). /pos/transactions par failed bill ke saamne \"Retry PRA\" aur upar \"Sync All (N)\" bhi hai — saray failed ek sath retry ho jate hain."],

            ['p' => [['deal']],
             'a' => "Deal /pos/deals par banti hai: \"+ Add Deal\" → naam, deal price, active days (Mon-Sun), start/end date (optional) → \"Add Item\" se products aur quantity dal kar save. Deal sirf apne set kiye dino mein sale screen par aati hai, price server enforce karta hai (cashier badal nahi sakta), aur deals hold/KOT par nahi ja saktin — sirf seedhi billing."],

            ['p' => [['customer', 'add'], ['customer', 'naya']],
             'a' => "Customer 2 tarah add hota hai: (1) sale screen par customer box mein phone/naam likhein — match na mile to usi dropdown mein \"Add as New\" se naam+phone likh kar foran ban jata hai; (2) /pos/customers par \"+ Add Customer\" se poore details (Name, Phone, Email, CNIC, NTN, City, Address) ke sath. Walk-in ke liye customer chhorna bhi theek hai."],

            ['p' => [['whatsapp', 'bill'], ['whatsapp', 'bhejna']],
             'a' => "Bill customer ko WhatsApp par bhejne ke liye /pos/transactions se bill kholein → share link banayen — yeh link WhatsApp waghera par bhej sakte hain."],

            ['p' => [['restaurant']],
             'a' => "Restaurant module ke liye Pro ya Unlimited package chahiye (ya active trial), phir /pos/features se restaurant features ON karein — KOT, Table Management, KDS, Kitchen Notes, Recipes. Dine-In, Takeaway aur Delivery teenon ko Hold ya Send to Kitchen kar sakte hain; Tables ON hon to Dine-In ke liye table zaroori hai. Tables /pos/restaurant/table-management par banti hain."],

            ['p' => [['hold']],
             'a' => "Hold (F5) Dine-In, Takeaway aur Delivery teenon ke liye hai — Tables ON hon to Dine-In ke liye table zaroori hai; manual items aur deals hold nahi ho saktin. Held orders TABLE board (Alt+B ya TABLE button) mein milte hain: table wale table card par aur baqi \"Held Orders (bina table)\" amber chips mein — click se Bill Kholo / PAY / KOT options milte hain."],

            ['p' => [['agent']],
             'a' => "Desktop Sync Agent Windows PC par chalta hai — bills PRA ko submit karta hai aur silent printing isi se hoti hai. Download /pos/agent page se (\"Download TaxNest Agent\"); wahin agent ka status (Online/Offline, last seen, version) bhi dikhta hai. Agent v1.3.0+ khud update hota hai — dobara download/install ki zaroorat nahi."],

            ['p' => [['kds']],
             'a' => "KDS (/pos/restaurant/kds) kitchen ki screen hai — kitchen account login karta hai (/pos/team se Kitchen role ka login banayen, limit mein nahi ginta). Order cards par timer aur URGENT tag; buttons: Start Preparing → Mark Ready → Clear. KOT ka barcode scan karne se order khud clear ho jata hai. KDS ON/OFF /pos/restaurant/kitchen-settings se hota hai."],

            ['p' => [['waiter']],
             'a' => "Waiter apne login se /pos/waiter kholta hai: Dine In/Take Away chunein → table chunein → items cart mein dalein (har item par kitchen note bhi likh sakta hai) → cashier select kar ke \"SEND TO CASHIER\". Cashier ki screen par TABLE button par teal badge aata hai — jamni (purple) table par click se order cart mein aa jata hai, payment cashier hi karta hai. Waiter payment/discount/delete nahi kar sakta."],

            ['p' => [['urgent'], ['rush']],
             'a' => "Urgent button (pehle 'Rush') order ko priority mark karta hai — KOT par bara *** URGENT *** aur kitchen/KDS screen par laal URGENT ka nishan aata hai. Saaf style mein yeh button \"Mazeed\" ke peechay hota hai."],

            ['p' => [['quick', 'type'], ['f7']],
             'a' => "Quick Type Mode (F7): \"{quick_type}\" jaisi line likhein — pura order khud cart mein aa jata hai. Default BAND hota hai — admin /pos/customize se ON kare, phir sale screen par F7 se modal khulta hai."],

            ['p' => [['qr', 'menu'], ['public', 'menu']],
             'a' => "QR Menu ke liye /pos/business-profile kholein → \"Public Page Enabled\" ON + \"Menu\" visible ON → menu builder mein products tick karein → QR code customer ko dikhayen. Kya kya public dikhe (Phone, Email, Address waghera) har cheez ka apna toggle hai. \"Regenerate Link\" se naya link banta hai. Yeh feature Business, Pro aur Unlimited packages mein hai."],

            ['p' => [['hazri'], ['attendance']],
             'a' => "Staff Hazri /pos/reports par \"Staff Hazri\" button se milti hai (sirf admin/manager): kaun kab login hua (First In), kab tak kaam kiya (Last Out), kitne bills banaye — business day (subah 6 → subah 6) ke hisab se, date picker se purane din bhi. Yehi hazri Day-Close Z-report mein bhi shamil hoti hai. Yeh feature Pro aur Unlimited packages mein hai."],

            // --- generic catch-alls: hamesha aakhir mein (specific pehle jeet chuke hote hain) ---
            ['p' => [['customer', 'history'], ['customer', 'purana']],
             'a' => "Customer ki poori history /pos/customers par milti hai — customer ke saamne \"History\" dabayen, us ke saray bills nazar aate hain (export/PDF bhi). Sale screen par bhi customer select kar ke \"X orders\" wali chip par click karein to wahin popup mein us ke pichhle orders ki history khul jati hai."],

            ['p' => [['purana', 'bill'], ['bill', 'record']],
             'a' => "Puranay bills /pos/transactions par milte hain — search (invoice/customer), payment method aur date ke filters ke sath; tabs: POS (PRA) / Local. Har bill se receipt/PDF aur share link milta hai. Aaj ke bills ki reprint sale screen par Alt+R se bhi ho jati hai; purane archived local bills /pos/archive par hote hain."],

            ['p' => [['bill', 'banau'], ['bill', 'banana'], ['naya', 'bill'], ['naya', 'sale']],
             'a' => "Nayi bill Dashboard ke \"Nayi Sale\" button (ya /pos/invoice/create) se banti hai:\n1. (Optional) Customer box mein phone/naam se customer chunein — walk-in ke liye khali chhor dein.\n2. Items dalein — search box, product grid ya barcode scan se.\n3. Qty cart row ke qty box se badlein.\n4. \"PAY\" (F8) → Cash (1) ya Card (2) = FINAL bill; ya \"Save Provisional\" (F9) = local bill.\n5. Receipt popup se P = Print."],

            ['p' => [['kot']],
             'a' => "KOT (Kitchen Order Ticket) kitchen ke liye order ki parchi hai — \"Send to Kitchen\" button Dine-In, Takeaway ya Delivery order ko bina payment ke kitchen bhejta aur ticket print karta hai. Tables ON hon to Dine-In ke liye table zaroori hai. KOT/kitchen printer settings /pos/restaurant/kitchen-settings par hain; yeh restaurant module ka feature hai (Pro/Unlimited) — /pos/features se ON hota hai."],

            ['p' => [['pra']],
             'a' => "PRA integration: har FINAL bill Punjab Revenue Authority ko report hota hai aur chhota fiscal serial (P-036 style) milta hai — receipt par PRA number aur QR aata hai. Settings /pos/pra-settings par (sirf admin): Environment, Connection Mode (Cloud API / PRA Fiscal Device), POS Registration ID, Token — \"Test Connection\" se check karein. Internet na ho to bill offline queue mein ja kar khud retry hota hai, quota dobara nahi katta. Naye PRA registrations ke liye Fiscal Device mode zaroori hai."],
        ];
    }

    /** Curated FAQ set for the FBR POS panel (Task 1275) — same shape as faqs(). */
    private static function fbrFaqs(): array
    {
        return [
            // --- specific before generic ---
            ['p' => [['fail', 'queue'], ['report', 'nahi'], ['fbr', 'nahi'], ['fail']],
             'a' => "Jo bills FBR ko report nahi huay wo Fail Queue mein hote hain — /fbr-pos/fail-queue kholein. Har bill ke saamne \"Retry\" hai; sab ek saath bhejne ke liye \"Retry All\" dabayen. CONFIG ERROR wale bills ka matlab FBR settings ka masla hai (ghalat POS ID/token/environment) — pehle /fbr-pos/settings par settings theek kar ke Test Connection karein, phir retry. Internet wapas aane par system khud bhi retry karta hai."],

            ['p' => [['asaan'], ['verify']],
             'a' => "Har FBR-reported receipt par FBR ka invoice number aur QR code hota hai — customer FBR ki \"Tax Asaan\" app se QR scan kar ke verify kar sakta hai. Verify na ho to /fbr-pos/transactions par check karein ke bill ka FBR status \"Submitted\" hai — pending/failed bill Tax Asaan par nahi milega (fail queue se retry karein). Sandbox ke bills Tax Asaan par kabhi nahi milte — asli verification sirf Production mein hoti hai."],

            ['p' => [['sandbox'], ['production', 'environment'], ['environment']],
             'a' => "Environment /fbr-pos/settings par set hota hai (sirf admin): SANDBOX testing ke liye hai — bills asli report NAHI hote; PRODUCTION asli reporting hai. Dukan chalani ho to Production chunein aur wohi token dalein jo FBR ne Production ke liye diya hai. Save ke baad \"Test Connection\" se check karein."],

            ['p' => [['pos', 'id'], ['token']],
             'a' => "POS Registration ID aur Token FBR se milte hain jab aap apna POS FBR ke saath register karte hain — dono /fbr-pos/settings par dalne hote hain (sirf admin). Environment (Sandbox/Production) token ke mutabiq chunein aur \"Test Connection\" se check karein. \"Resource forbidden\" ya token error ka matlab token/environment ka mel nahi hai."],

            ['p' => [['fiscal', 'device'], ['agent']],
             'a' => "Connection Mode /fbr-pos/settings par hai: \"Cloud API\" purane registered POS IDs ke liye, aur NAYE FBR registrations ke liye \"Fiscal Device / Local Service\" mode zaroori hai — is mein FBR ka local service usi computer par chalta hai jis ke liye Desktop Agent install hota hai (download settings page ke Agent section se). Install ke baad Test Connection se check karein."],

            ['p' => [['dayclose'], ['zreport']],
             'a' => "Day close /fbr-pos/day-close par hota hai:\n1. Page kholein — din ka khulasa (sales, tax, payments) nazar aayega.\n2. Cash gin kar reconcile karein.\n3. \"Close Day\" dabayen.\nPurani closes isi page par milti hain — PDF aur Thermal print dono. Auto Day-Close (24h) ka option bhi hai. Din band karne se pehle fail queue check kar lein."],

            ['p' => [['provisional'], ['local', 'bill'], ['makefinal']],
             'a' => "Provisional bill: sale screen par \"Save Provisional\" — bill save hota hai magar FBR ko report NAHI hota. Baad mein provisional list (F10) ya /fbr-pos/transactions se \"Make Final\" (promote) karein — tabhi bill FBR ko jata hai aur receipt par FBR number + QR aata hai."],

            ['p' => [['silent']],
             'a' => "Silent printing (bina print dialog ke) ke liye Desktop Agent install karein aur printer settings mein \"Silent Printing\" ON kar ke Bill/KOT printers chunein — list Agent se aati hai. Setting badalne ke baad sale screen refresh (F5) karein."],

            ['p' => [['print', 'lagana'], ['print', 'setup'], ['print', 'install'], ['print', 'connect']],
             'a' => "Printer lagane ka tareeqa:\n1. Printer PC se connect kar ke driver install karein.\n2. Browser ke print dialog mein wohi printer select karein.\n3. /fbr-pos/receipt-settings par paper size (80mm ya 58mm) set karein.\nHar aam thermal printer (USB, network ya Bluetooth) chalta hai. Bina dialog ke seedha print chahiye to Desktop Agent install kar ke Silent Printing ON karein."],

            ['p' => [['password']],
             'a' => "Password bhool jayein to /fbr-pos/login par \"Forgot Password\" dabayen — email par OTP aata hai, phir naya password set karein. Apna password badalna ho to /fbr-pos/my-profile par Current Password + New Password + Confirm se change hota hai. Team members ke passwords company admin /fbr-pos/team par dekh sakta hai."],

            ['p' => [['login']],
             'a' => "FBR POS login /fbr-pos/login par hota hai — Email, Phone, Username, CNIC ya NTN se (CNIC/NTN se company ka admin login hota hai). 5 ghalat koshishon par login thori der ke liye lock ho jata hai. Password bhool gaye hon to \"Forgot Password\" se OTP ke zariye reset karein. Kisi aur panel par login karne se \"Invalid credentials\" aayega — sirf /fbr-pos/login use karein."],

            ['p' => [['scanner'], ['barcode']],
             'a' => "Barcode scanner ke liye alag setup nahi chahiye — koi bhi USB/Bluetooth scanner jo keyboard ki tarah type karta hai seedha chal jata hai. Sale screen ke search box mein scan karein — exact match foran cart mein chala jata hai."],

            ['p' => [['product', 'add'], ['product', 'naya'], ['product', 'banana']],
             'a' => "Naya {item} /fbr-pos/products par banta hai — \"Add {item}\" se naam, price, barcode/SKU aur tax set karein. Bohat saare {items} ek saath dalne hon to Excel import istemal karein (/fbr-pos/products/import — pehle template download karein)."],

            ['p' => [['limit'], ['package'], ['expiry']],
             'a' => "Apna package, mahana bill limit aur expiry /fbr-pos/billing par nazar aati hai. FBR POS mein mahana quota mein provisional bills bhi ginte hain. Upgrade ya renewal ke liye billing page se payment proof upload karein ya TaxNest team se WhatsApp par rabta karein."],

            ['p' => [['offline'], ['internet']],
             'a' => "Internet chala jaye to bill save ho jata hai aur FBR ko report offline queue mein chala jata hai — net wapas aane par khud submit ho jata hai, double report nahi hota. Jo bills phir bhi reh jayen wo /fbr-pos/fail-queue par milte hain — wahan se Retry All kar dein."],
        ];
    }

    // ==================== RETRIEVAL ENGINE ====================

    /** Confidence-gated retrieval over KB sections/lines. Null = decline. */
    private static function retrieve(array $qTokens, string $product = 'pos', array $vocabulary = []): ?string
    {
        $index = self::index($product, $vocabulary);
        if (empty($index['sections'])) {
            return null;
        }
        $idf = $index['idf'];
        $nSections = count($index['sections']);
        // Unknown-to-KB tokens get max weight — they pull coverage DOWN, which
        // is exactly what we want ("youtube video download" must decline).
        $unknownW = log(1 + $nSections / 0.5);

        $totalW = 0.0;
        $weights = [];
        foreach ($qTokens as $t) {
            $w = $idf[$t] ?? $unknownW;
            $weights[$t] = $w;
            $totalW += $w;
        }
        if ($totalW <= 0) {
            return null;
        }

        // ---- pick best section ----
        $best = null;
        $bestScore = 0.0;
        $bestMatchedW = 0.0;
        $bestMatched = 0;
        foreach ($index['sections'] as $si => $sec) {
            $score = 0.0;
            $matchedW = 0.0;
            $matched = 0;
            foreach ($qTokens as $t) {
                $inBody = isset($sec['tokens'][$t]);
                $inTitle = isset($sec['titleTokens'][$t]);
                if ($inBody || $inTitle) {
                    $w = $weights[$t];
                    $matchedW += $w;
                    $matched++;
                    $score += $w + ($inTitle ? 1.5 * $w : 0);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $si;
                $bestMatchedW = $matchedW;
                $bestMatched = $matched;
            }
        }
        if ($best === null) {
            return null;
        }

        // ---- confidence gates (conservative on purpose) ----
        $coverage = $bestMatchedW / $totalW;
        $singleStrong = count($qTokens) === 1 && $bestMatched === 1
            && ($weights[$qTokens[0]] >= 1.4)
            && isset($index['sections'][$best]['titleTokens'][$qTokens[0]]);
        if (!$singleStrong) {
            if ($coverage < 0.62 || $bestMatched < 2 || $bestMatchedW < 1.6) {
                return null;
            }
        }

        // ---- pick best lines within the section ----
        $sec = $index['sections'][$best];
        $lineScores = [];
        foreach ($sec['lines'] as $li => $line) {
            $s = 0.0;
            foreach ($qTokens as $t) {
                if (isset($line['tokens'][$t])) {
                    $s += $weights[$t];
                }
            }
            if ($s > 0) {
                $lineScores[$li] = $s;
            }
        }
        if (empty($lineScores)) {
            return null;
        }
        arsort($lineScores);
        $topScore = reset($lineScores);
        // Line must independently carry some signal, not ride on title alone.
        if ($topScore < 0.9 && !$singleStrong) {
            return null;
        }

        $picked = [];
        foreach ($lineScores as $li => $s) {
            if (count($picked) >= 3) {
                break;
            }
            if ($s >= 0.55 * $topScore) {
                $picked[] = $li;
            }
        }
        sort($picked); // document order reads naturally

        $out = [];
        $len = 0;
        foreach ($picked as $li) {
            $text = self::clipLine($sec['lines'][$li]['text']);
            if ($text === '') {
                continue;
            }
            $out[] = $text;
            $len += mb_strlen($text);
            if ($len > 700) {
                break;
            }
        }
        if (empty($out)) {
            return null;
        }

        return MadadgarService::stripMarkdown(implode("\n", $out));
    }

    /** Clip an over-long KB line at a sentence boundary. */
    private static function clipLine(string $line, int $max = 420): string
    {
        $line = trim($line);
        if (mb_strlen($line) <= $max) {
            return $line;
        }
        $cut = mb_substr($line, 0, $max);
        $lastDot = mb_strrpos($cut, '. ');
        $lastSemi = mb_strrpos($cut, '; ');
        $pos = max($lastDot === false ? -1 : $lastDot, $lastSemi === false ? -1 : $lastSemi);
        if ($pos > 80) {
            return mb_substr($cut, 0, $pos + 1);
        }

        return rtrim($cut).'…';
    }

    // ==================== KB INDEX ====================

    private static function kbRaw(string $product = 'pos'): string
    {
        try {
            $path = resource_path($product === 'fbrpos' ? 'madadgar/knowledge-fbrpos.md' : 'madadgar/knowledge-pos.md');

            return is_file($path) ? (string) file_get_contents($path) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Parse + tokenize the KB once per request (memoized per product). */
    private static function index(string $product = 'pos', array $vocabulary = []): array
    {
        $indexKey = $product.'|'.self::contextKey($vocabulary, null);
        if (isset(self::$index[$indexKey])) {
            return self::$index[$indexKey];
        }

        $sections = [];
        $current = null;
        $knowledge = self::fillPlaceholders(self::kbRaw($product), $vocabulary);
        foreach (preg_split('/\r?\n/', $knowledge) as $raw) {
            if (preg_match('/^#{2,3}\s+(.*)$/', $raw, $m)) {
                if ($current !== null && !empty($current['lines'])) {
                    $sections[] = $current;
                }
                $current = ['title' => trim($m[1]), 'lines' => []];
                continue;
            }
            if ($current === null) {
                continue; // preamble before first ##
            }
            $t = trim($raw);
            if ($t === '' || str_starts_with($t, '# ')) {
                continue;
            }
            // Each physical line is a retrieval unit; strip bullet markers.
            $text = preg_replace('/^[-*]\s+/', '', $t);
            $tokens = self::tokenize($text);
            if (empty($tokens)) {
                continue;
            }
            $current['lines'][] = ['text' => $text, 'tokens' => array_flip($tokens)];
        }
        if ($current !== null && !empty($current['lines'])) {
            $sections[] = $current;
        }

        // Section-level token sets + IDF over sections.
        $df = [];
        foreach ($sections as &$sec) {
            $all = self::tokenize($sec['title']);
            $sec['titleTokens'] = array_flip($all);
            foreach ($sec['lines'] as $line) {
                foreach ($line['tokens'] as $tok => $_) {
                    $all[] = $tok;
                }
            }
            $sec['tokens'] = array_flip($all);
            foreach ($sec['tokens'] as $tok => $_) {
                $df[$tok] = ($df[$tok] ?? 0) + 1;
            }
        }
        unset($sec);

        $n = max(1, count($sections));
        $idf = [];
        foreach ($df as $tok => $d) {
            $idf[$tok] = log(1 + $n / $d);
        }

        return self::$index[$indexKey] = ['sections' => $sections, 'idf' => $idf];
    }

    private static function asksForUnavailableModule(array $tokens, ?array $availableModules): bool
    {
        if ($availableModules === null) {
            return false;
        }
        $set = array_flip($tokens);
        $topics = [
            'kot' => ['kot'],
            'kitchen' => ['kds', 'kitchen', 'restaurant'],
            'tables' => ['table', 'waiter', 'dine'],
            'recipes' => ['recipe', 'ingredient'],
            'riders_enabled' => ['rider'],
            'deals_enabled' => ['deal', 'combo'],
            'inventory' => ['stock', 'inventory'],
            'barcode' => ['barcode', 'scanner'],
            'pharmacy' => ['pharmacy', 'prescription', 'batch', 'expiry', 'medicine'],
        ];
        foreach ($topics as $module => $keywords) {
            if (array_intersect_key($set, array_flip($keywords))
                && !in_array($module, $availableModules, true)) {
                return true;
            }
        }
        return false;
    }

    private static function fillPlaceholders(string $text, array $vocabulary): string
    {
        $defaults = [
            'item' => 'Product',
            'items' => 'Products',
            'example' => 'Item A',
            'example2' => 'Item B',
            'quick_type' => 'item 2, item 1',
        ];
        $v = array_merge($defaults, $vocabulary);
        return strtr($text, [
            '{item}' => (string) $v['item'],
            '{items}' => (string) $v['items'],
            '{example}' => (string) $v['example'],
            '{example2}' => (string) $v['example2'],
            '{quick_type}' => (string) $v['quick_type'],
        ]);
    }

    private static function contextKey(array $vocabulary, ?array $availableModules): string
    {
        $modules = $availableModules ?? [];
        sort($modules);
        return md5(json_encode([
            $vocabulary['category'] ?? 'generic',
            $vocabulary['item'] ?? 'Product',
            $vocabulary['examples'] ?? [],
            $modules,
            $availableModules === null ? 'unfiltered' : 'filtered',
        ]));
    }

    // ==================== NORMALIZATION ====================

    /** Public for tests: canonical informative tokens of a question. */
    public static function questionTokens(string $question): array
    {
        return self::tokenize($question);
    }

    private static function tokenize(string $text): array
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        $t = ' '.trim((string) $t).' ';

        foreach (self::phraseFolds() as $from => $to) {
            $t = str_replace($from, $to, $t);
        }

        $map = self::variantMap();
        $stop = self::stopwords();
        $out = [];
        foreach (preg_split('/\s+/', trim($t)) as $tok) {
            if ($tok === '' || mb_strlen($tok) < 2) {
                continue;
            }
            $tok = $map[$tok] ?? $tok;
            if (isset($stop[$tok])) {
                continue;
            }
            $out[$tok] = true;
        }

        return array_keys($out);
    }

    /** Multi-word folds — applied to space-padded text BEFORE token mapping. */
    private static function phraseFolds(): array
    {
        return [
            ' din band '     => ' dayclose ',
            ' day close '    => ' dayclose ',
            ' din close '    => ' dayclose ',
            ' close day '    => ' dayclose ',
            ' day end '      => ' dayclose ',
            ' z report '     => ' zreport ',
            ' bar code '     => ' barcode ',
            ' whats app '    => ' whatsapp ',
            ' delivery boy ' => ' rider ',
            ' log in '       => ' login ',
            ' sign in '      => ' login ',
            ' make final '   => ' makefinal ',
            ' quick type '   => ' quick type ', // keep as-is (two tokens on both sides)
        ];
    }

    /**
     * Roman-Urdu spelling variants + synonym groups folded to one canonical
     * token. Applied to BOTH question and KB so the distinction collapses
     * equally on each side.
     */
    private static function variantMap(): array
    {
        return [
            // bill / receipt / parchi family
            'bills' => 'bill', 'bil' => 'bill', 'invoice' => 'bill', 'invoices' => 'bill',
            'receipt' => 'bill', 'receipts' => 'bill', 'reciept' => 'bill', 'recipt' => 'bill',
            'parchi' => 'bill', 'parchiyan' => 'bill', 'rasid' => 'bill', 'raseed' => 'bill', 'slip' => 'bill',
            // print family
            'printer' => 'print', 'printers' => 'print', 'printing' => 'print', 'prints' => 'print',
            'chapta' => 'print', 'chapti' => 'print', 'chape' => 'print', 'chapay' => 'print',
            'chhapta' => 'print', 'chhapti' => 'print', 'chhape' => 'print', 'chhapay' => 'print',
            // on / off
            'band' => 'off', 'bandh' => 'off', 'bund' => 'off', 'chalu' => 'on', 'chaloo' => 'on',
            // delete / remove
            'hatao' => 'delete', 'hatana' => 'delete', 'hataye' => 'delete', 'hatayen' => 'delete',
            'remove' => 'delete', 'urao' => 'delete', 'urado' => 'delete',
            // misc spelling variants
            'passward' => 'password', 'pasword' => 'password',
            'gahak' => 'customer', 'grahak' => 'customer', 'gahaak' => 'customer', 'customers' => 'customer',
            'item' => 'product', 'items' => 'product', 'products' => 'product',
            'member' => 'team', 'members' => 'team', 'staff' => 'team', 'employee' => 'team',
            'account' => 'team', 'accounts' => 'team',
            'mez' => 'table', 'tables' => 'table',
            'riders' => 'rider',
            'riayat' => 'discount', 'rayat' => 'discount', 'chhoot' => 'discount', 'choot' => 'discount',
            'shortcuts' => 'shortcut', 'keys' => 'shortcut',
            'scaner' => 'scanner',
            'packages' => 'package', 'plan' => 'package', 'plans' => 'package',
            'subscription' => 'package', 'pkg' => 'package',
            'prices' => 'price', 'qeemat' => 'price', 'keemat' => 'price', 'qimat' => 'price',
            'rate' => 'price', 'rates' => 'price',
            'limits' => 'limit', 'quota' => 'limit',
            'stocks' => 'stock', 'inventory' => 'stock',
            'provisonal' => 'provisional', 'parvisional' => 'provisional',
            'waiters' => 'waiter', 'bera' => 'waiter',
            'kharaab' => 'kharab', 'karab' => 'kharab',
            'problem' => 'masla', 'problems' => 'masla', 'issue' => 'masla', 'issues' => 'masla',
            'masail' => 'masla', 'masle' => 'masla', 'maslay' => 'masla',
            'milta' => 'mil', 'milti' => 'mil', 'mila' => 'mil', 'milay' => 'mil',
            'mile' => 'mil', 'milega' => 'mil', 'milegi' => 'mil', 'milay' => 'mil',
            'lagayen' => 'lagana', 'lagaen' => 'lagana', 'lagao' => 'lagana', 'laga' => 'lagana',
            'lagta' => 'lagana', 'lagti' => 'lagana', 'lage' => 'lagana', 'lagay' => 'lagana',
            'orders' => 'order', 'settings' => 'setting', 'reports' => 'report',
            'deals' => 'deal', 'sales' => 'sale',
            'kichen' => 'kitchen', 'kichan' => 'kitchen',
            'wapis' => 'wapas', 'cancle' => 'cancel', 'returned' => 'return',
            'branches' => 'branch', 'terminals' => 'terminal',
            'ingredients' => 'ingredient', 'recipes' => 'recipe',
            'expire' => 'expiry', 'expired' => 'expiry',
            'attendence' => 'attendance', 'hazree' => 'hazri',
            'awaz' => 'sound', 'ghanti' => 'sound', 'bell' => 'sound',
            'downlod' => 'download', 'apps' => 'app', 'apk' => 'app',
            'nhi' => 'nahi', 'nahin' => 'nahi',
            'naye' => 'naya', 'nayi' => 'naya', 'new' => 'naya',
            'create' => 'add', 'banayein' => 'add',
        ];
    }

    /** Roman Urdu + English function words dropped from scoring. */
    private static function stopwords(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }
        $words = [
            // Roman Urdu grammar / filler
            'ka', 'ki', 'ke', 'ko', 'se', 'par', 'pe', 'per', 'mein', 'me', 'mai', 'main',
            'hun', 'hu', 'hoon', 'hai', 'hain', 'hy', 'he', 'ho', 'hota', 'hoti', 'hote',
            'hogi', 'hoga', 'honge', 'hua', 'hui', 'hue', 'raha', 'rahi', 'rahe', 'rha', 'rhi', 'rhe',
            'gaya', 'gayi', 'gaye', 'gya', 'gyi', 'jata', 'jati', 'jate', 'ja', 'jaye', 'jayen',
            'jayega', 'jayegi', 'karna', 'karein', 'karen', 'karain', 'kare', 'karo', 'kar',
            'krna', 'krein', 'kren', 'kro', 'kiya', 'kiye', 'kia', 'karta', 'karti', 'karte',
            'karun', 'karoon', 'krun', 'to', 'bhi', 'aur', 'or', 'ya', 'phr', 'ab', 'abhi',
            'aap', 'ap', 'apna', 'apni', 'apne', 'mera', 'meri', 'mere', 'hamara', 'hamari', 'hamare',
            'is', 'us', 'ye', 'yeh', 'wo', 'woh', 'in', 'un', 'kya', 'kyun', 'kiun', 'kyu',
            'kab', 'kahan', 'kaha', 'kahaan', 'kidhar', 'kaun', 'kon', 'kaunsa', 'konsa', 'kounsa',
            'kitna', 'kitni', 'kitne', 'kaise', 'kese', 'kaisay', 'kesay', 'kasay', 'kaisa',
            'agar', 'lekin', 'magar', 'phir', 'sakta', 'sakti', 'sakte', 'saktay', 'sake', 'sakay',
            'chahiye', 'chaiye', 'chahye', 'chahta', 'chahti', 'chahte', 'liye', 'lie', 'lye',
            'le', 'lo', 'lena', 'leni', 'de', 'do', 'dein', 'den', 'dena', 'deni', 'dain', 'dey', 'dy',
            'diya', 'dia', 'batao', 'bata', 'batana', 'batayen', 'bataye', 'btao', 'bta', 'batain',
            'please', 'plz', 'ji', 'sir', 'bhai', 'janab', 'ok', 'theek', 'acha', 'accha',
            'tha', 'thi', 'thay', 'wala', 'wali', 'walay', 'wale', 'waala', 'hi', 'na', 'toh',
            'banana', 'banao', 'banaye', 'banayen', 'banai', 'banti', 'banta', 'bante', 'banate',
            'banaun', 'bnao', 'bnaye', 'zara', 'kuch', 'koi', 'sab', 'sara', 'sari', 'saray',
            'bohat', 'bahut', 'bilkul', 'foran', 'jaldi', 'thora', 'thori', 'waghera', 'etc',
            'hamesha', 'kabhi', 'dafa', 'baar', 'tarah', 'tareeqa', 'tarika', 'tareeka',
            // English function words
            'the', 'and', 'of', 'for', 'at', 'a', 'an', 'am', 'i', 'you', 'we', 'they', 'it',
            'my', 'your', 'our', 'how', 'what', 'where', 'when', 'which', 'who', 'why',
            'can', 'cant', 'could', 'do', 'does', 'did', 'will', 'would', 'should', 'there',
            'this', 'that', 'these', 'those', 'with', 'without', 'about', 'tell', 'show',
            'want', 'need', 'get', 'have', 'has', 'had', 'want', 'from', 'into', 'want',
        ];
        $set = array_flip($words);

        return $set;
    }
}
