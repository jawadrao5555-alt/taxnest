<?php
/**
 * One-off script: send activation confirmation email to Company ID 40
 * (Abdul Hameed Hotel) and write an audit log entry.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT \
 *       -u PGUSER -u PGPASSWORD -u PGDATABASE \
 *       php tools/send-activation-email.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Mail\TrialReminderMail;
use Illuminate\Support\Facades\Mail;

// ---- Known production details (Company ID 40 exists only on live DB) ----
$companyId   = 40;
$companyName = 'Abdul Hameed Hotel';
$email       = 'saleemabbassildr@gmail.com';
$planLabel   = 'Business (Annual)';
$endsDate    = '17 Aug 2027';
$panelName   = 'NestPOS — PRA Point of Sale';
$ctaUrl      = 'https://pos.taxnest.com.pk/pos/login';
// -------------------------------------------------------------------------

echo "Company : {$companyName}\n";
echo "Email   : {$email}\n";
echo "Plan    : {$planLabel}\n";
echo "Ends at : {$endsDate}\n\n";

$paragraphs = [
    "Aapka {$companyName} ka TaxNest account activate ho gaya hai — ab aap login kar ke kaam shuru kar saktay hain.",
    "Package: {$planLabel}",
    "Validity: {$endsDate} tak",
    "Login karne ke liye neeche diye gaye button par click karein. Aapka username/email wahi hai jo aap ne registration ke waqt daakhil kiya tha.",
    "Kisi bhi mushkil mein hamara WhatsApp support hamesha haazir hai.",
];

echo "Sending to: {$email} ...\n";

try {
    Mail::to($email)->send(new TrialReminderMail(
        subjectLine: 'Mubarak! Aapka TaxNest account activate ho gaya',
        companyName: $companyName,
        headline: 'Aapka TaxNest Business account active hai!',
        paragraphs: $paragraphs,
        ctaUrl: $ctaUrl,
        ctaLabel: 'Login Karein',
        panelName: $panelName,
    ));
    echo "Email sent successfully.\n";
    try { \App\Services\MailHealth::recordSuccess(); } catch (\Throwable $ignored) {}
} catch (\Throwable $e) {
    echo "Email FAILED: " . $e->getMessage() . "\n";
    try { \App\Services\MailHealth::recordFailure('Activation email company 40', $e); } catch (\Throwable $ignored) {}
    exit(1);
}

echo "\nDone.\n";
