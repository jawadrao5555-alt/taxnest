<?php
/**
 * Task 1403 — insert the FBR Desktop Agent / Features / Customize strings
 * into lang/{en,rur,ur}/pos.php, alphabetically, in one pass.
 *
 * Idempotent: an already-present key is skipped.
 */

$root = dirname(__DIR__, 2);

$keys = [
    'copy_btn' => [
        'en'  => 'Copy',
        'rur' => 'Copy',
        'ur'  => 'کاپی',
    ],

    // ── Desktop Agent page ────────────────────────────────────────────
    'fbr_agent_title' => [
        'en'  => 'Desktop Agent',
        'rur' => 'Desktop Agent',
        'ur'  => 'ڈیسک ٹاپ ایجنٹ',
    ],
    'fbr_agent_sub' => [
        'en'  => 'Install the Desktop Agent on your counter PC so bills and store slips print silently, with no popup. It does not change how your invoices reach FBR.',
        'rur' => 'Counter ke PC par Desktop Agent laga lein taake bill aur store slip bina popup ke khud print hon. Is se FBR ko invoice bhejne ka tareeqa nahi badalta.',
        'ur'  => 'کاؤنٹر کے کمپیوٹر پر ڈیسک ٹاپ ایجنٹ لگائیں تاکہ بل اور سٹور سلپ بغیر پاپ اپ کے خود پرنٹ ہوں۔ اس سے ایف بی آر کو انوائس بھیجنے کا طریقہ نہیں بدلتا۔',
    ],
    'fbr_agent_plan_locked_title' => [
        'en'  => 'Desktop Agent is not included in your package',
        'rur' => 'Desktop Agent aap ke package mein shamil nahi',
        'ur'  => 'ڈیسک ٹاپ ایجنٹ آپ کے پیکج میں شامل نہیں',
    ],
    'fbr_agent_plan_locked_body' => [
        'en'  => 'Silent printing through the Desktop Agent is a Business-and-above feature. Upgrade the package and this page opens right away.',
        'rur' => 'Desktop Agent se silent printing Business aur us se upar ke packages ka feature hai. Package upgrade karte hi yeh page khul jayega.',
        'ur'  => 'ڈیسک ٹاپ ایجنٹ سے خاموش پرنٹنگ Business اور اس سے اوپر کے پیکجوں کا فیچر ہے۔ پیکج اپ گریڈ کرتے ہی یہ صفحہ کھل جائے گا۔',
    ],
    'fbr_agent_plan_locked_msg' => [
        'en'  => 'Desktop Agent is not included in your package — upgrade to connect an agent.',
        'rur' => 'Desktop Agent aap ke package mein shamil nahi — agent jorne ke liye package upgrade karein.',
        'ur'  => 'ڈیسک ٹاپ ایجنٹ آپ کے پیکج میں شامل نہیں — ایجنٹ جوڑنے کے لیے پیکج اپ گریڈ کریں۔',
    ],
    'fbr_agent_key_exists' => [
        'en'  => 'A key already exists for this shop. Use Regenerate Key to replace it.',
        'rur' => 'Is dukan ki key pehle se mojood hai. Badalne ke liye Regenerate Key istemaal karein.',
        'ur'  => 'اس دکان کی کلید پہلے سے موجود ہے۔ بدلنے کے لیے کلید دوبارہ بنائیں۔',
    ],
    'fbr_agent_key_generated' => [
        'en'  => 'Agent key created. Enter it in the Desktop Agent on your counter PC.',
        'rur' => 'Agent key ban gayi. Ise counter ke PC par Desktop Agent mein daal dein.',
        'ur'  => 'ایجنٹ کلید بن گئی۔ اسے کاؤنٹر کے کمپیوٹر پر ڈیسک ٹاپ ایجنٹ میں ڈال دیں۔',
    ],
    'fbr_agent_key_regenerated' => [
        'en'  => 'New agent key created. Agents keep failing until the new key is entered on the PC.',
        'rur' => 'Nayi agent key ban gayi. Jab tak PC par nayi key na daali jaye, agent band rahenge.',
        'ur'  => 'نئی ایجنٹ کلید بن گئی۔ جب تک کمپیوٹر پر نئی کلید نہ ڈالی جائے، ایجنٹ بند رہیں گے۔',
    ],
    'fbr_agent_status_title' => [
        'en'  => 'Agent Status',
        'rur' => 'Agent Status',
        'ur'  => 'ایجنٹ کی حالت',
    ],
    'fbr_agent_version_label' => [
        'en'  => 'Version:',
        'rur' => 'Version:',
        'ur'  => 'ورژن:',
    ],
    'fbr_agent_outdated_badge' => [
        'en'  => 'Old version — latest v:version',
        'rur' => 'Purani version — latest v:version',
        'ur'  => 'پرانا ورژن — تازہ ترین v:version',
    ],
    'fbr_agent_uptodate_badge' => [
        'en'  => 'Up to date',
        'rur' => 'Up to date',
        'ur'  => 'تازہ ترین',
    ],
    'fbr_agent_selfupdate_note' => [
        'en'  => 'The agent updates itself — keep the PC on and online and the new version installs on its own.',
        'rur' => 'Agent khud update ho jata hai — PC on aur internet chalta rahe to nayi version khud lag jayegi.',
        'ur'  => 'ایجنٹ خود اپ ڈیٹ ہو جاتا ہے — کمپیوٹر چالو اور انٹرنیٹ چلتا رہے تو نیا ورژن خود لگ جائے گا۔',
    ],
    'fbr_agent_mode_note' => [
        'en'  => 'This page only sets up printing. Where your invoices are sent to FBR is a separate setting:',
        'rur' => 'Yeh page sirf printing set karta hai. FBR ko invoice kahan se jati hai, wo alag setting hai:',
        'ur'  => 'یہ صفحہ صرف پرنٹنگ کا انتظام کرتا ہے۔ ایف بی آر کو انوائس کہاں سے جاتی ہے، وہ الگ ترتیب ہے:',
    ],
    'fbr_agent_next_steps_title' => [
        'en'  => 'Still needed before silent printing works',
        'rur' => 'Silent printing chalne se pehle yeh baqi hai',
        'ur'  => 'خاموش پرنٹنگ چلنے سے پہلے یہ باقی ہے',
    ],
    'fbr_agent_step_silent_off' => [
        'en'  => 'Silent printing is still off — turn it on here:',
        'rur' => 'Silent printing abhi band hai — ise yahan on karein:',
        'ur'  => 'خاموش پرنٹنگ ابھی بند ہے — اسے یہاں چالو کریں:',
    ],
    'fbr_agent_step_no_printers' => [
        'en'  => 'The agent has not reported a single printer yet. Run it on the PC the printers are attached to.',
        'rur' => 'Agent ne abhi tak koi printer report nahi kiya. Ise us PC par chalayen jahan printer lage hain.',
        'ur'  => 'ایجنٹ نے ابھی تک کوئی پرنٹر نہیں بھیجا۔ اسے اُس کمپیوٹر پر چلائیں جہاں پرنٹر لگے ہیں۔',
    ],
    'fbr_agent_step_store_slip_off' => [
        'en'  => 'Store Slip is off, so only the bill prints. Turn the feature on here:',
        'rur' => 'Store Slip band hai, is liye sirf bill print hoga. Feature yahan on karein:',
        'ur'  => 'سٹور سلپ بند ہے، اس لیے صرف بل چھپے گا۔ یہ فیچر یہاں چالو کریں:',
    ],
    'fbr_agent_stat_queued' => [
        'en'  => 'Waiting to print',
        'rur' => 'Print hone ka intezar',
        'ur'  => 'پرنٹ ہونے کے منتظر',
    ],
    'fbr_agent_stat_printed' => [
        'en'  => 'Printed today',
        'rur' => 'Aaj print hue',
        'ur'  => 'آج پرنٹ ہوئے',
    ],
    'fbr_agent_stat_failed' => [
        'en'  => 'Failed today',
        'rur' => 'Aaj fail hue',
        'ur'  => 'آج ناکام ہوئے',
    ],
    'fbr_agent_credentials' => [
        'en'  => 'Agent Credentials',
        'rur' => 'Agent Credentials',
        'ur'  => 'ایجنٹ کی تفصیلات',
    ],
    'fbr_agent_company_id' => [
        'en'  => 'Company ID',
        'rur' => 'Company ID',
        'ur'  => 'کمپنی آئی ڈی',
    ],
    'fbr_agent_api_key' => [
        'en'  => 'API Key',
        'rur' => 'API Key',
        'ur'  => 'API کلید',
    ],
    'fbr_agent_server_url' => [
        'en'  => 'Server URL (paste into the agent)',
        'rur' => 'Server URL (agent mein paste karein)',
        'ur'  => 'سرور URL (ایجنٹ میں لگائیں)',
    ],
    'fbr_agent_key_secret_warn' => [
        'en'  => 'Keep this key secret. Anyone holding it can send print jobs for your shop.',
        'rur' => 'Yeh key kisi ko na dein. Jis ke paas yeh key hogi wo aap ki dukan ke print jobs bhej sakta hai.',
        'ur'  => 'یہ کلید کسی کو نہ دیں۔ جس کے پاس یہ کلید ہوگی وہ آپ کی دکان کے پرنٹ کام بھیج سکتا ہے۔',
    ],
    'fbr_agent_no_key_yet' => [
        'en'  => 'No key yet — press Generate Key to create one.',
        'rur' => 'Abhi koi key nahi — Generate Key dabayen.',
        'ur'  => 'ابھی کوئی کلید نہیں — کلید بنائیں کا بٹن دبائیں۔',
    ],
    'fbr_agent_generate_btn' => [
        'en'  => 'Generate Key',
        'rur' => 'Generate Key',
        'ur'  => 'کلید بنائیں',
    ],
    'fbr_agent_regenerate_btn' => [
        'en'  => 'Regenerate Key',
        'rur' => 'Regenerate Key',
        'ur'  => 'کلید دوبارہ بنائیں',
    ],
    'fbr_agent_regenerate_confirm' => [
        'en'  => 'A new key disconnects the agents running right now. Continue?',
        'rur' => 'Nayi key banne par abhi chalte hue agent band ho jayenge. Jari rakhein?',
        'ur'  => 'نئی کلید بننے پر ابھی چلتے ہوئے ایجنٹ بند ہو جائیں گے۔ جاری رکھیں؟',
    ],
    'fbr_agent_download_title' => [
        'en'  => 'Download the Desktop Agent',
        'rur' => 'Desktop Agent Download Karein',
        'ur'  => 'ڈیسک ٹاپ ایجنٹ ڈاؤن لوڈ کریں',
    ],
    'fbr_agent_latest_prefix' => [
        'en'  => 'Latest:',
        'rur' => 'Latest:',
        'ur'  => 'تازہ ترین:',
    ],
    'fbr_agent_recommended' => [
        'en'  => 'Recommended',
        'rur' => 'Recommended',
        'ur'  => 'تجویز کردہ',
    ],
    'fbr_agent_exe_installer' => [
        'en'  => '.exe installer',
        'rur' => '.exe installer',
        'ur'  => 'exe. انسٹالر',
    ],
    'fbr_agent_zip_portable' => [
        'en'  => '.zip (portable — no installer needed)',
        'rur' => '.zip (portable — installer ki zaroorat nahi)',
        'ur'  => 'zip. (پورٹیبل — انسٹالر کی ضرورت نہیں)',
    ],
    'fbr_agent_download_now' => [
        'en'  => 'Download now',
        'rur' => 'Download Karein',
        'ur'  => 'ڈاؤن لوڈ کریں',
    ],
    'fbr_agent_coming_soon' => [
        'en'  => 'Coming soon',
        'rur' => 'Jald aa raha hai',
        'ur'  => 'جلد آ رہا ہے',
    ],
    'fbr_agent_or_zip' => [
        'en'  => 'or the portable .zip',
        'rur' => 'ya portable .zip',
        'ur'  => 'یا پورٹیبل zip.',
    ],
    'fbr_agent_build_in_progress' => [
        'en'  => 'A new build is being prepared. If the Windows button opens a web page instead of downloading, wait two or three minutes and refresh.',
        'rur' => 'Nayi build tayyar ho rahi hai. Agar Windows button se download ki jagah web page khule to 2-3 minute baad refresh karein.',
        'ur'  => 'نیا بلڈ تیار ہو رہا ہے۔ اگر Windows کے بٹن سے ڈاؤن لوڈ کی جگہ ویب صفحہ کھلے تو دو تین منٹ بعد صفحہ تازہ کریں۔',
    ],
    'fbr_agent_setup_title' => [
        'en'  => 'How to install',
        'rur' => 'Lagane Ka Tareeqa',
        'ur'  => 'لگانے کا طریقہ',
    ],
    'fbr_agent_setup_step1' => [
        'en'  => 'Download the Windows file above on the counter PC (internet needed).',
        'rur' => 'Upar se Windows file counter ke PC par download karein (internet zaroori).',
        'ur'  => 'اوپر سے Windows فائل کاؤنٹر کے کمپیوٹر پر ڈاؤن لوڈ کریں (انٹرنیٹ ضروری)۔',
    ],
    'fbr_agent_setup_step2' => [
        'en'  => 'Right-click the zip and choose "Extract All" — into Downloads or Desktop, never on top of an old agent folder.',
        'rur' => 'Zip par right-click karke "Extract All" karein — Downloads ya Desktop par, kisi purane agent folder ke upar nahi.',
        'ur'  => 'زپ پر دائیں کلک کر کے "Extract All" کریں — ڈاؤن لوڈز یا ڈیسک ٹاپ پر، کسی پرانے ایجنٹ فولڈر کے اوپر نہیں۔',
    ],
    'fbr_agent_setup_step3' => [
        'en'  => 'Open the extracted folder and double-click "install.bat" — it closes the old agent, copies the new files and makes the shortcuts.',
        'rur' => 'Extract hue folder mein "install.bat" par double-click karein — wo purana agent band karke nayi files laga deta hai.',
        'ur'  => 'نکالے گئے فولڈر میں "install.bat" پر دو بار کلک کریں — وہ پرانا ایجنٹ بند کر کے نئی فائلیں لگا دیتا ہے۔',
    ],
    'fbr_agent_setup_step4' => [
        'en'  => 'On the first run enter the Company ID, API Key and Server URL shown above.',
        'rur' => 'Pehli dafa upar diye gaye Company ID, API Key aur Server URL enter karein.',
        'ur'  => 'پہلی بار اوپر دیے گئے کمپنی آئی ڈی، API کلید اور سرور URL درج کریں۔',
    ],
    'fbr_agent_setup_step5' => [
        'en'  => 'Keep the PC on during shop hours — new versions install themselves after that.',
        'rur' => 'Dukan ke waqt PC on rakhein — aage se nayi versions khud lag jati hain.',
        'ur'  => 'دکان کے اوقات میں کمپیوٹر چالو رکھیں — آگے سے نئے ورژن خود لگ جاتے ہیں۔',
    ],
    'fbr_agent_setup_warn' => [
        'en'  => 'Never extract the zip straight onto a running agent folder — Windows shows a "File In Use" error. Always extract into a fresh folder and run "install.bat".',
        'rur' => 'Zip ko chalte hue agent ke folder par seedha extract na karein — Windows "File In Use" error dega. Hamesha naye folder mein extract karke "install.bat" chalayen.',
        'ur'  => 'زپ کو چلتے ہوئے ایجنٹ کے فولڈر پر سیدھا نہ نکالیں — Windows "File In Use" کی خرابی دکھائے گا۔ ہمیشہ نئے فولڈر میں نکال کر "install.bat" چلائیں۔',
    ],

    // ── Customize → Features card ─────────────────────────────────────
    'fbr_features_section' => [
        'en'  => 'Features',
        'rur' => 'Features',
        'ur'  => 'فیچرز',
    ],
    'fbr_features_section_sub' => [
        'en'  => 'Turn whole modules on or off. Switching one off hides it everywhere in the POS.',
        'rur' => 'Poore module on ya off karein. Off karte hi wo POS mein har jagah se chhup jata hai.',
        'ur'  => 'پورے ماڈیول چالو یا بند کریں۔ بند کرتے ہی وہ POS میں ہر جگہ سے چھپ جاتا ہے۔',
    ],
    'fbr_feat_store_slip_title' => [
        'en'  => 'Store Slip',
        'rur' => 'Store Slip',
        'ur'  => 'سٹور سلپ',
    ],
    'fbr_feat_store_slip_sub' => [
        'en'  => 'A separate packing slip for the store or godown, next to the customer bill.',
        'rur' => 'Customer bill ke sath store ya godown ke liye alag packing slip.',
        'ur'  => 'گاہک کے بل کے ساتھ سٹور یا گودام کے لیے الگ پیکنگ سلپ۔',
    ],
    'fbr_feat_delivery_title' => [
        'en'  => 'Delivery & Riders',
        'rur' => 'Delivery aur Riders',
        'ur'  => 'ڈیلیوری اور رائیڈرز',
    ],
    'fbr_feat_delivery_sub' => [
        'en'  => 'Delivery board, rider assignment and delivery tracking.',
        'rur' => 'Delivery board, rider assign karna aur delivery tracking.',
        'ur'  => 'ڈیلیوری بورڈ، رائیڈر مقرر کرنا اور ڈیلیوری کی نگرانی۔',
    ],
    'fbr_feat_store_notes_title' => [
        'en'  => 'Item note for the store',
        'rur' => 'Item par store note',
        'ur'  => 'آئٹم پر سٹور نوٹ',
    ],
    'fbr_feat_store_notes_sub' => [
        'en'  => 'The cashier can add a short note on any item — it prints on the store slip.',
        'rur' => 'Cashier kisi bhi item par chota note likh sakta hai — wo store slip par print hota hai.',
        'ur'  => 'کیشیئر کسی بھی آئٹم پر مختصر نوٹ لکھ سکتا ہے — وہ سٹور سلپ پر چھپتا ہے۔',
    ],
    'fbr_feat_needs_store_slip' => [
        'en'  => 'Turn Store Slip on first — the note has nowhere to print without it.',
        'rur' => 'Pehle Store Slip on karein — us ke baghair note kahin print nahi hoga.',
        'ur'  => 'پہلے سٹور سلپ چالو کریں — اس کے بغیر نوٹ کہیں نہیں چھپے گا۔',
    ],

    // ── Customize → new hub cards ─────────────────────────────────────
    'fbr_card_agent_desc' => [
        'en'  => 'Connect the counter PC for silent bill and store-slip printing.',
        'rur' => 'Silent bill aur store slip printing ke liye counter ka PC jorein.',
        'ur'  => 'خاموش بل اور سٹور سلپ پرنٹنگ کے لیے کاؤنٹر کا کمپیوٹر جوڑیں۔',
    ],
    'fbr_card_team_desc' => [
        'en'  => 'Add cashiers and managers, set their access and passwords.',
        'rur' => 'Cashier aur manager add karein, unka access aur password set karein.',
        'ur'  => 'کیشیئر اور منیجر شامل کریں، ان کی رسائی اور پاس ورڈ مقرر کریں۔',
    ],
    'fbr_card_branches_desc' => [
        'en'  => 'Manage your outlets and see sales branch by branch.',
        'rur' => 'Apni branches manage karein aur har branch ki sale dekhein.',
        'ur'  => 'اپنی برانچیں سنبھالیں اور ہر برانچ کی فروخت دیکھیں۔',
    ],
    'fbr_card_dayclose_settings_desc' => [
        'en'  => 'Auto day-close at 6 AM and the day cutoff time.',
        'rur' => 'Subah 6 baje auto day-close aur din ka cutoff waqt.',
        'ur'  => 'صبح چھ بجے خودکار ڈے کلوز اور دن کا کٹ آف وقت۔',
    ],
    'fbr_default_language_title' => [
        'en'  => 'Shop default language',
        'rur' => 'Dukan ki default language',
        'ur'  => 'دکان کی طے شدہ زبان',
    ],
    'fbr_default_language_sub' => [
        'en'  => 'New team members start in this language. Each person can still switch their own.',
        'rur' => 'Naye team members isi language mein shuru hote hain. Har banda apni language alag se badal sakta hai.',
        'ur'  => 'نئے ٹیم ممبر اسی زبان میں شروع ہوتے ہیں۔ ہر شخص اپنی زبان الگ سے بدل سکتا ہے۔',
    ],

    // ── Printer Settings honesty notices ──────────────────────────────
    'fbr_printer_no_agent_note' => [
        'en'  => 'No Desktop Agent is connected, so nothing can print silently — bills will keep opening the browser popup.',
        'rur' => 'Koi Desktop Agent connected nahi, is liye silent print nahi hoga — bill browser popup se hi niklega.',
        'ur'  => 'کوئی ڈیسک ٹاپ ایجنٹ جڑا ہوا نہیں، اس لیے خاموش پرنٹنگ نہیں ہوگی — بل براؤزر کے پاپ اپ سے ہی نکلے گا۔',
    ],
    'fbr_printer_store_slip_off_note' => [
        'en'  => 'Store Slip is off, so the store printer chosen below is never used. Turn the feature on from Customize.',
        'rur' => 'Store Slip band hai, is liye neeche chuna gaya store printer istemaal nahi hoga. Feature Customize se on karein.',
        'ur'  => 'سٹور سلپ بند ہے، اس لیے نیچے چنا گیا سٹور پرنٹر استعمال نہیں ہوگا۔ یہ فیچر Customize سے چالو کریں۔',
    ],
    'fbr_printer_open_agent_page' => [
        'en'  => 'Open the Desktop Agent page',
        'rur' => 'Desktop Agent ka page kholein',
        'ur'  => 'ڈیسک ٹاپ ایجنٹ کا صفحہ کھولیں',
    ],
];

foreach (['en', 'rur', 'ur'] as $loc) {
    $file = "{$root}/lang/{$loc}/pos.php";
    $lines = file($file);
    if ($lines === false) {
        fwrite(STDERR, "cannot read {$file}\n");
        exit(1);
    }

    $added = 0;
    foreach ($keys as $key => $vals) {
        $value = $vals[$loc];

        // Already present? skip (idempotent re-runs).
        $exists = false;
        foreach ($lines as $l) {
            if (strpos($l, "'{$key}' =>") === 0 || strpos(ltrim($l), "'{$key}' =>") === 0) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            continue;
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        $entry = "    '{$key}' => '{$escaped}',\n";

        // Alphabetical insert: before the first top-level key that sorts after ours.
        $at = null;
        foreach ($lines as $i => $l) {
            if (preg_match("/^    '([a-z0-9_]+)' => /", $l, $m) && strcmp($m[1], $key) > 0) {
                $at = $i;
                break;
            }
        }
        if ($at === null) {
            // Fall back to just before the closing bracket.
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (trim($lines[$i]) === '];') {
                    $at = $i;
                    break;
                }
            }
        }
        array_splice($lines, $at, 0, [$entry]);
        $added++;
    }

    file_put_contents($file, implode('', $lines));
    echo "{$loc}: +{$added} keys\n";
}

echo "done\n";
