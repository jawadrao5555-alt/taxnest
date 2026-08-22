@php
    // Public, login-free page — Google Play ki shart hai ke privacy policy ka
    // URL bina kisi account ke khule aur app ka naam usmein saaf likha ho
    // (Task 1346). Contact page ka #privacy section chhota khulasa hai; asal
    // mukammal policy yeh safha hai.
    $legalName = \App\Models\SystemSetting::get('company_legal_name', '') ?: 'TaxNest';
    $email = \App\Models\SystemSetting::get('contact_email', '');
    $phone = \App\Models\SystemSetting::get('contact_phone', '');
    $address = \App\Models\SystemSetting::get('contact_address', '');
    $updated = 'August 21, 2026';
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>Privacy Policy — TaxNest</title>
    <meta name="description" content="TaxNest Privacy Policy — what data the TaxNest platform and the TaxNest Caller ID Android app collect, why, who it is shared with, how long it is kept, and how to delete it.">
    @include('partials.meta-og', [
        'ogTitle'       => 'Privacy Policy — TaxNest',
        'ogDescription' => 'What data TaxNest and the TaxNest Caller ID Android app collect, why, how it is protected, and how to have it deleted.',
        'ogUrl'         => 'https://taxnest.com.pk/privacy',
    ])
    <link rel="canonical" href="https://taxnest.com.pk/privacy">
    {{-- Fonts: non-blocking loader — never link a font stylesheet directly (see partials/font-css). --}}
    @include('partials.font-css', ['fontFamilies' => 'playfair-display:400,600,700|inter:400,500,600,700,800'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --teal-dark:#052730; --teal-main:#0A4D5C; --gold:#E7BF3B; --paper:#FDFBF7; --ink:#1F2937; }
        body { font-family:'Inter',system-ui,-apple-system,sans-serif; background:var(--paper); color:var(--ink); -webkit-font-smoothing:antialiased; }
        h1,h2,h3,.font-serif { font-family:'Playfair Display',serif; }
        .grid-dark { background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px); background-size:40px 40px; }
        .legal-prose p { margin-bottom:0.85rem; line-height:1.75; color:#4B5563; font-size:0.95rem; }
        .legal-prose h4 { font-family:'Inter',sans-serif; font-weight:700; color:#052730; margin:1.4rem 0 .5rem; font-size:0.95rem; }
        .legal-prose ul { list-style:disc; padding-left:1.25rem; margin-bottom:1rem; }
        .legal-prose li { margin-bottom:.4rem; line-height:1.7; color:#4B5563; font-size:0.95rem; }
        .legal-prose table { width:100%; border-collapse:collapse; margin:.5rem 0 1.25rem; font-size:0.9rem; }
        .legal-prose th, .legal-prose td { border:1px solid #E5E7EB; padding:.6rem .75rem; text-align:left; vertical-align:top; color:#4B5563; }
        .legal-prose th { background:#F9FAFB; color:#052730; font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:.04em; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Nav -->
    <nav class="bg-[#052730] border-b border-[#0A4D5C]">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-20">
            <a href="/"><img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-8 w-auto"></a>
            <div class="flex items-center gap-6 text-sm font-medium text-white/70">
                <a href="/digital-invoice" class="hover:text-white transition-colors hidden sm:inline">Digital Invoice</a>
                <a href="/pos" class="hover:text-white transition-colors hidden sm:inline">NestPOS</a>
                <a href="/fbr-pos-landing" class="hover:text-white transition-colors hidden md:inline">FBR POS</a>
                <a href="/download" class="hover:text-white transition-colors hidden md:inline">Downloads</a>
                <a href="/login" class="text-white bg-white/10 hover:bg-white/20 px-4 py-2 rounded-md transition-colors">Log In</a>
            </div>
        </div>
    </nav>

    <!-- Header -->
    <header class="bg-[#052730] border-b border-[#0A4D5C] relative overflow-hidden">
        <div class="absolute inset-0 grid-dark opacity-60"></div>
        <div class="max-w-2xl mx-auto px-4 text-center py-20 relative z-10">
            <div class="inline-flex items-center px-3 py-1 bg-white/5 border border-white/10 rounded-full text-xs font-medium text-white/80 tracking-wide uppercase mb-6">
                <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] mr-2"></span> Last updated {{ $updated }}
            </div>
            <h1 class="text-4xl sm:text-5xl font-serif text-white mb-4">Privacy Policy</h1>
            <p class="text-white/70 text-lg font-light">What we collect, why we collect it, who it goes to, and how you get it deleted — for the TaxNest platform and for the TaxNest Caller ID Android app.</p>
        </div>
    </header>

    <main class="flex-1">
        <section class="max-w-[900px] mx-auto px-4 sm:px-6 lg:px-8 py-16 space-y-14">

            <!-- Jump links -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">On this page</h2>
                <ul class="grid sm:grid-cols-2 gap-2 text-sm text-[#0A4D5C]">
                    <li><a class="hover:underline" href="#who">1. Who we are</a></li>
                    <li><a class="hover:underline" href="#platform">2. The TaxNest platform</a></li>
                    <li><a class="hover:underline" href="#callerid">3. TaxNest Caller ID app</a></li>
                    <li><a class="hover:underline" href="#sharing">4. Sharing</a></li>
                    <li><a class="hover:underline" href="#security">5. Security &amp; retention</a></li>
                    <li><a class="hover:underline" href="#rights">6. Your rights &amp; deletion</a></li>
                    <li><a class="hover:underline" href="#children">7. Children</a></li>
                    <li><a class="hover:underline" href="#changes">8. Changes &amp; contact</a></li>
                </ul>
            </div>

            <div id="who" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">1. Who we are</h3>
                <p>This policy is issued by {{ $legalName }} ("TaxNest", "we", "us"), Pakistan. It covers the TaxNest web platform at taxnest.com.pk (FBR Digital Invoicing, NestPOS for PRA, and FBR POS) and the TaxNest Android applications, including <strong>TaxNest Caller ID</strong> (package <code>pk.taxnest.callerid</code>).</p>
                <p>TaxNest is business software sold to registered businesses. In almost every case the person using our apps is a shop owner or their staff member, using the software for their own shop.</p>
            </div>

            <div id="platform" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">2. The TaxNest platform</h3>
                <h4>What we collect</h4>
                <ul>
                    <li><strong>Account and company details</strong> you provide — name, email, phone, username, CNIC / NTN, business name and address.</li>
                    <li><strong>Transaction data you create</strong> — invoices, POS bills, products, customers, ledgers and payments.</li>
                    <li><strong>Technical and usage data</strong> — log records, device / browser information, IP address, and audit trails of actions taken in your account.</li>
                </ul>
                <h4>Why</h4>
                <ul>
                    <li>To run the service and produce your invoices and receipts.</li>
                    <li>To transmit, on your instruction, the documents that FBR / PRA require.</li>
                    <li>To provide support, prevent abuse and fraud, and improve the platform.</li>
                    <li>To send service, billing and account notifications.</li>
                </ul>
            </div>

            <!-- Caller ID — Play reviewer yahi section parhta hai -->
            <div id="callerid" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">3. TaxNest Caller ID (Android app)</h3>
                <p>TaxNest Caller ID is a companion app for shops that already use TaxNest NestPOS. It sits on the shop's phone and tells the shop's own POS sale screen who is calling, so the cashier sees the customer's name and past orders while the phone is still ringing.</p>
                <p>The app is only usable by a person who signs in with an existing TaxNest POS account. It has no public sign-up.</p>

                <h4>What the app collects and sends</h4>
                <table>
                    <thead><tr><th>Data</th><th>Why</th><th>Where it goes</th></tr></thead>
                    <tbody>
                        <tr><td>Caller's phone number of an <em>incoming</em> call</td><td>To match the caller to a customer record in the shop's own POS and show the pop-up</td><td>Only to the shop's own TaxNest account on taxnest.com.pk, over HTTPS</td></tr>
                        <tr><td>Caller's display name, when the phone shows one</td><td>Shown in the pop-up when the number is not yet a saved customer</td><td>Same</td></tr>
                        <tr><td>Time of the call and its type (SIM call or WhatsApp call)</td><td>To show the pop-up for the right call and drop stale ones</td><td>Same</td></tr>
                        <tr><td>Sign-in email, device model, build type, and a device token</td><td>To sign in, to show the shop owner which phones are linked, and to let the owner revoke a phone</td><td>Same</td></tr>
                    </tbody>
                </table>

                <h4>How the app detects a call</h4>
                <ul>
                    <li>The <strong>clean build</strong> (website download) uses Android's telephony permissions (<code>READ_PHONE_STATE</code>, <code>READ_CALL_LOG</code>) to read the number of an incoming SIM call. This is a caller-ID use, which is an approved use of these permissions.</li>
                    <li>The <strong>WhatsApp build</strong> (website) and the <strong>Google Play build</strong> use Android's notification access instead, so that WhatsApp calls — which are internet calls and are invisible to telephony APIs — can also be detected. Before that permission is requested, the app shows a full-screen disclosure describing exactly what is read and where it is sent, and the user must agree.</li>
                    <li>Those two builds read an incoming-call notification only when it comes from the phone's own calling app (the default dialer or the system telephony app) or from WhatsApp / WhatsApp Business. A call notification from any other app — Messenger, Telegram, Viber, Skype, Zoom and the like — is ignored: it is not read and nothing about it is sent.</li>
                </ul>

                <h4>What the app never does</h4>
                <ul>
                    <li>It does not read, store or transmit any notification other than an incoming-call notification from the phone's own calling app or from WhatsApp — no messages, chats, emails, or other apps' notifications.</li>
                    <li>It does not read your call history, contacts, SMS, photos or files.</li>
                    <li>It does not record calls or audio.</li>
                    <li>It does not track location.</li>
                    <li>It does not show ads, and it contains no advertising or analytics SDKs.</li>
                    <li>It never sends data to any third party. The only destination is the shop's own TaxNest account.</li>
                </ul>

                <h4>Retention for Caller ID data</h4>
                <p>A ring record (number, name, time, call type) is stored so the sale screen can show the pop-up, and is deleted automatically about <strong>48 hours</strong> later. If the shop's owner turns the feature off, or revokes that phone from POS → Customize, the phone stops sending anything immediately. Uninstalling the app also ends all collection.</p>
                <p>Where the caller is already a saved customer of the shop, that customer record belongs to the shop and is kept as part of the shop's own business records (see section 5).</p>
            </div>

            <div id="sharing" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">4. How we share data</h3>
                <ul>
                    <li>With <strong>FBR / PRA</strong> and their official APIs, as required to submit the documents you generate. This applies to invoicing and POS data only — Caller ID data is never sent to any authority.</li>
                    <li>With trusted service providers (such as our hosting provider) strictly to run the platform.</li>
                    <li>Where required by law or a lawful order.</li>
                    <li>We do <strong>not</strong> sell your data, and we do not share it with advertisers or data brokers.</li>
                </ul>
            </div>

            <div id="security" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">5. Security &amp; retention</h3>
                <p>All traffic between our apps and our servers is encrypted in transit using HTTPS (TLS). Access is controlled per account and per user role, sensitive credentials are stored encrypted, and account actions are written to immutable audit logs.</p>
                <p>Business records (invoices, bills, customers, ledgers) are retained while your account is active and for the period required by Pakistani tax law. Caller ID ring records are deleted automatically after about 48 hours. Device tokens are deleted when the device is revoked or the user signs out.</p>
            </div>

            <div id="rights" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">6. Your rights &amp; deleting your data</h3>
                <p>You can ask us for a copy of your data, ask for a correction, or ask for your account and data to be deleted. For Caller ID specifically, you can withdraw the permission at any time from your phone's settings, and the shop owner can revoke the device or switch the feature off from POS → Customize.</p>
                <p>The full deletion procedure, including what is deleted immediately and what must be kept for tax law, is on the <a href="/data-deletion" class="text-[#0A4D5C] font-semibold hover:underline">Account &amp; Data Deletion</a> page.</p>
            </div>

            <div id="children" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">7. Children</h3>
                <p>TaxNest is business software intended for registered businesses and their staff. It is not directed to children, and we do not knowingly collect data from anyone under 18.</p>
            </div>

            <div id="changes" class="scroll-mt-24 legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">8. Changes &amp; how to contact us</h3>
                <p>We may update this policy. The date at the top of this page always shows the current version, and continued use after a change means you accept it.</p>
                <p>For any privacy question, data request, or deletion request:</p>
                <ul>
                    @if($email)<li>Email: <a href="mailto:{{ $email }}" class="text-[#0A4D5C] font-semibold hover:underline">{{ $email }}</a></li>@endif
                    @if($phone)<li>Phone / WhatsApp: {{ $phone }}</li>@endif
                    @if($address)<li>Address: {{ $address }}</li>@endif
                    <li>Or use any channel on our <a href="/contact" class="text-[#0A4D5C] font-semibold hover:underline">Contact page</a>.</li>
                </ul>
                <p class="text-xs text-gray-500 mt-6">Terms &amp; Conditions and the split of responsibilities between TaxNest and your business are on the <a href="/contact#terms" class="text-[#0A4D5C] hover:underline">Contact &amp; Legal</a> page.</p>
            </div>

        </section>
    </main>

    <x-site-footer />
    <x-whatsapp-support />
</body>
</html>
