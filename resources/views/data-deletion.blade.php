@php
    // Public, login-free page — Google Play Data safety form ka "account
    // deletion URL" isi safhe par jata hai (Task 1346). Play ki shart: user
    // bina app install kiye, bina login kiye yeh safha khol kar delete ki
    // darkhwast ka tareeqa parh sake.
    $legalName = \App\Models\SystemSetting::get('company_legal_name', '') ?: 'TaxNest';
    $email = \App\Models\SystemSetting::get('contact_email', '');
    $phone = \App\Models\SystemSetting::get('contact_phone', '');
    $waNumber = preg_replace('/\D/', '', (string) \App\Models\SystemSetting::get('support_whatsapp_number', ''));
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
    <title>Account &amp; Data Deletion — TaxNest</title>
    <meta name="description" content="How to delete your TaxNest account and data, including data collected by the TaxNest Caller ID Android app — what is removed, what is kept for tax law, and how long it takes.">
    @include('partials.meta-og', [
        'ogTitle'       => 'Account & Data Deletion — TaxNest',
        'ogDescription' => 'Request deletion of your TaxNest account and data, including TaxNest Caller ID app data.',
        'ogUrl'         => 'https://taxnest.com.pk/data-deletion',
    ])
    <link rel="canonical" href="https://taxnest.com.pk/data-deletion">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap"></noscript>
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
            <h1 class="text-4xl sm:text-5xl font-serif text-white mb-4">Account &amp; Data Deletion</h1>
            <p class="text-white/70 text-lg font-light">Stop the collection yourself in seconds, or ask us to delete your whole account. Both routes are below.</p>
        </div>
    </header>

    <main class="flex-1">
        <section class="max-w-[900px] mx-auto px-4 sm:px-6 lg:px-8 py-16 space-y-12">

            <!-- Immediate, self-service -->
            <div class="legal-prose">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-3">Right now, without asking anyone</h2>
                <h3 class="text-2xl font-serif text-[#052730] mb-4">Stop the TaxNest Caller ID app collecting</h3>
                <div class="grid md:grid-cols-3 gap-5 mb-6">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                        <div class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-2">On the phone</div>
                        <p class="text-sm text-gray-600 mb-0">Withdraw the permission in Android Settings (notification access, or the app's permissions), or simply uninstall the app. Collection stops immediately.</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                        <div class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-2">In POS</div>
                        <p class="text-sm text-gray-600 mb-0">The shop owner can open POS → Customize → Caller ID and revoke any linked phone, or switch the feature off for the whole shop.</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                        <div class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-2">Automatically</div>
                        <p class="text-sm text-gray-600 mb-0">Ring records (number, name, time, call type) are deleted on their own about 48 hours after the call, whether you do anything or not.</p>
                    </div>
                </div>
                <p>None of the above needs a support request, and none of it deletes the shop's invoices or customers — it only ends the Caller ID collection.</p>
            </div>

            <!-- Full account deletion -->
            <div id="request" class="scroll-mt-24 legal-prose">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-3">Full deletion</h2>
                <h3 class="text-2xl font-serif text-[#052730] mb-4">Delete your TaxNest account and its data</h3>
                <p>TaxNest accounts are business accounts, so deletion is done by us after we confirm the request really comes from the account holder. Send us a request with the details below and we will handle it.</p>

                <h4>How to send the request</h4>
                <ul>
                    @if($email)<li><strong>Email</strong> <a href="mailto:{{ $email }}?subject={{ rawurlencode('Account and data deletion request') }}" class="text-[#0A4D5C] font-semibold hover:underline">{{ $email }}</a> with the subject "Account and data deletion request".</li>@endif
                    @if($waNumber)<li><strong>WhatsApp</strong> <a href="https://wa.me/{{ $waNumber }}?text={{ rawurlencode('I want to delete my TaxNest account and data.') }}" target="_blank" rel="noopener" class="text-[#0A4D5C] font-semibold hover:underline">our support number</a>.</li>@endif
                    @if($phone && !$waNumber)<li><strong>Phone</strong> {{ $phone }}.</li>@endif
                    <li>Or any channel listed on the <a href="/contact" class="text-[#0A4D5C] font-semibold hover:underline">Contact page</a>.</li>
                </ul>

                <h4>Include in the message</h4>
                <ul>
                    <li>The registered business name and the login email or username of the account.</li>
                    <li>Whether you want the <strong>whole account</strong> deleted, or only the <strong>Caller ID data</strong> (ring records and linked devices).</li>
                    <li>Send it from the email address or phone number registered on the account, so we can verify you. If we cannot verify a request, we will ask for confirmation before deleting anything.</li>
                </ul>

                <h4>What happens then</h4>
                <ul>
                    <li>We acknowledge the request, normally within 2 working days.</li>
                    <li>Once verified, we delete the account and its data — users, devices, tokens, Caller ID ring records, products, customers, bills and invoices held in the platform — normally within <strong>30 days</strong>.</li>
                    <li>Backups roll over on their own cycle, so a copy may persist in encrypted backups for up to 90 days before it is overwritten.</li>
                </ul>

                <h4>What we may have to keep</h4>
                <ul>
                    <li>Records that Pakistani tax law requires us or your business to retain — chiefly invoices and POS bills that were already submitted to FBR / PRA, and their submission responses. These cannot be erased on request; the authority also holds its own copy.</li>
                    <li>Minimal billing records for our own accounting and legal obligations.</li>
                    <li>Immutable audit-log entries needed to prove what happened on the account, kept for the period required by law.</li>
                </ul>
                <p class="text-xs text-gray-500">Everything not covered by the above is deleted. Caller ID data is never in the "must keep" category — it can always be deleted in full on request, and in normal operation it expires by itself after about 48 hours.</p>
            </div>

            <div class="legal-prose">
                <h3 class="text-2xl font-serif text-[#052730] mb-4">Related</h3>
                <p>Full detail of what each app collects is in our <a href="/privacy" class="text-[#0A4D5C] font-semibold hover:underline">Privacy Policy</a>. Terms &amp; Conditions are on the <a href="/contact#terms" class="text-[#0A4D5C] font-semibold hover:underline">Contact &amp; Legal</a> page.</p>
                <p class="text-xs text-gray-500">Requests are handled by {{ $legalName }}.</p>
            </div>

        </section>
    </main>

    <x-site-footer />
    <x-whatsapp-support />
</body>
</html>
