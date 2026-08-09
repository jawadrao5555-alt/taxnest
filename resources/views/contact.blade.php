@php
    $legalName = \App\Models\SystemSetting::get('company_legal_name', '') ?: 'TaxNest';
    $email = \App\Models\SystemSetting::get('contact_email', '');
    $phone = \App\Models\SystemSetting::get('contact_phone', '');
    $address = \App\Models\SystemSetting::get('contact_address', '');
    $hours = \App\Models\SystemSetting::get('support_hours', '');
    $waNumber = preg_replace('/\D/', '', (string) \App\Models\SystemSetting::get('support_whatsapp_number', ''));
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>Contact &amp; Legal — TaxNest</title>
    <meta name="description" content="Contact TaxNest support and review our Privacy Policy, Terms & Conditions, and the responsibilities of TaxNest versus your company.">
    @include('partials.meta-og', [
        'ogTitle'       => 'Contact & Legal — TaxNest',
        'ogDescription' => 'Get in touch with TaxNest support. Also find our Privacy Policy, Terms & Conditions, and a clear breakdown of responsibilities between TaxNest and your business.',
        'ogUrl'         => 'https://taxnest.com.pk/contact',
    ])
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap"></noscript>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --teal-dark:#052730; --teal-main:#0A4D5C; --gold:#E7BF3B; --paper:#FDFBF7; --ink:#1F2937; }
        body { font-family:'Inter',system-ui,-apple-system,sans-serif; background:var(--paper); color:var(--ink); -webkit-font-smoothing:antialiased; }
        h1,h2,h3,.font-serif { font-family:'Playfair Display',serif; }
        [x-cloak]{display:none!important;}
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
                <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] mr-2"></span> We're here to help
            </div>
            <h1 class="text-4xl sm:text-5xl font-serif text-white mb-4">Contact &amp; Legal</h1>
            <p class="text-white/70 text-lg font-light">Reach our team any time — and see exactly how TaxNest protects you and what each side is responsible for.</p>
        </div>
    </header>

    <main class="flex-1">

        <!-- Contact cards -->
        <section class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 -mt-10 relative z-20">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @if($waNumber)
                <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode('Hello, I need support with TaxNest.') }}" target="_blank" rel="noopener"
                   class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:-translate-y-0.5 transition">
                    <div class="w-11 h-11 rounded-lg bg-[#25D366]/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.515 5.26l-.999 3.648 3.973-1.715z"/></svg>
                    </div>
                    <h3 class="font-serif text-lg text-[#052730] mb-1">WhatsApp Support</h3>
                    <p class="text-sm text-gray-500">Tap to chat with our team and describe your issue — we'll help you resolve it.</p>
                </a>
                @endif
                @if($email)
                <a href="mailto:{{ $email }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:-translate-y-0.5 transition">
                    <div class="w-11 h-11 rounded-lg bg-[#0A4D5C]/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="font-serif text-lg text-[#052730] mb-1">Email Us</h3>
                    <p class="text-sm text-gray-500 break-all">{{ $email }}</p>
                </a>
                @endif
                @if($phone)
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:-translate-y-0.5 transition">
                    <div class="w-11 h-11 rounded-lg bg-[#E7BF3B]/20 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-[#B8912A]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11 11 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </div>
                    <h3 class="font-serif text-lg text-[#052730] mb-1">Call Us</h3>
                    <p class="text-sm text-gray-500">{{ $phone }}</p>
                </a>
                @endif
            </div>

            @if($address || $hours)
            <div class="mt-5 bg-white rounded-xl border border-gray-200 shadow-sm p-6 flex flex-col sm:flex-row gap-8">
                @if($address)
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-1">Office</h4>
                        <p class="text-sm text-gray-700">{{ $address }}</p>
                    </div>
                </div>
                @endif
                @if($hours)
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-1">Support Hours</h4>
                        <p class="text-sm text-gray-700">{{ $hours }}</p>
                    </div>
                </div>
                @endif
            </div>
            @endif

            @if(!$waNumber && !$email && !$phone)
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
                <p class="text-gray-500 text-sm">Contact details will appear here once the administrator adds them in the admin settings.</p>
            </div>
            @endif
        </section>

        <!-- Legal sections -->
        <section class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 py-20 space-y-16">

            <!-- Responsibilities -->
            <div id="responsibility" class="scroll-mt-24">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-3">Shared Responsibility</h2>
                <h3 class="text-3xl font-serif text-[#052730] mb-4">Who is responsible for what</h3>
                <p class="text-gray-600 leading-relaxed max-w-3xl">
                    TaxNest is compliance <em>software</em>. We give you the tools and the secure connection to the authorities — but the accuracy of your data and your legal tax obligations remain with your business. Here is the clear split.
                </p>
                <div class="grid md:grid-cols-2 gap-6 mt-8">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <div class="inline-flex items-center gap-2 mb-4">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <h4 class="font-serif text-lg text-[#052730]">TaxNest is responsible for</h4>
                        </div>
                        <ul class="space-y-2.5 text-sm text-gray-600">
                            <li class="flex gap-2"><span class="text-emerald-500">✓</span> Providing the platform and keeping it reasonably available and secure.</li>
                            <li class="flex gap-2"><span class="text-emerald-500">✓</span> Transmitting your invoices and POS data to FBR / PRA through their official APIs (PRAL IMS, FBR Digital Invoicing) as you configure.</li>
                            <li class="flex gap-2"><span class="text-emerald-500">✓</span> Applying tax rates and calculation logic per the current published FBR / PRA rules implemented in the system.</li>
                            <li class="flex gap-2"><span class="text-emerald-500">✓</span> Protecting your account with access controls and immutable audit logs.</li>
                            <li class="flex gap-2"><span class="text-emerald-500">✓</span> Relaying the authority's response to you transparently, including any rejection reasons.</li>
                            <li class="flex gap-2"><span class="text-emerald-500">✓</span> Providing support for platform issues through the channels listed above.</li>
                        </ul>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                        <div class="inline-flex items-center gap-2 mb-4">
                            <span class="w-2 h-2 rounded-full bg-[#0A4D5C]"></span>
                            <h4 class="font-serif text-lg text-[#052730]">Your company is responsible for</h4>
                        </div>
                        <ul class="space-y-2.5 text-sm text-gray-600">
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> The accuracy of all data you enter — products, prices, HS codes, buyer NTN / CNIC, tax categories and amounts.</li>
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> Holding valid FBR / PRA registration, tokens and credentials, and keeping them active.</li>
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> Ensuring every invoice and receipt reflects a real, lawful transaction your business is entitled to issue.</li>
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> Timely filing and payment of your taxes — TaxNest transmits data, it does not file or pay on your behalf.</li>
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> Reviewing each bill before submission and reconciling with your own records.</li>
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> Your devices, internet, and (in PRA fiscal-device mode) the on-site sync agent.</li>
                            <li class="flex gap-2"><span class="text-[#0A4D5C]">•</span> Keeping login credentials confidential and managing your own users and roles.</li>
                        </ul>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-5 leading-relaxed max-w-3xl">
                    Government API downtime, rule changes, or rejections that originate from FBR / PRA are outside TaxNest's control. TaxNest is not a substitute for professional tax or legal advice.
                </p>
            </div>

            <!-- Privacy Policy -->
            <div id="privacy" class="scroll-mt-24 legal-prose">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-3" style="font-family:'Inter',sans-serif;">Legal</h2>
                <h3 class="text-3xl font-serif text-[#052730] mb-6">Privacy Policy</h3>
                <p>This Privacy Policy explains what information {{ $legalName }} ("TaxNest", "we", "us") collects when you use our platform, why we collect it, and how we protect it. By using TaxNest you agree to this policy.</p>

                <h4>Information we collect</h4>
                <ul>
                    <li>Account and company details you provide — name, email, phone, username, CNIC / NTN, business name and address.</li>
                    <li>Transaction data you create in the platform — invoices, POS bills, products, customers and ledgers.</li>
                    <li>Technical and usage data — log records, device / browser information, and audit trails of actions taken in your account.</li>
                </ul>

                <h4>How we use it</h4>
                <ul>
                    <li>To operate the service and generate your invoices and receipts.</li>
                    <li>To transmit the data required for compliance to FBR / PRA on your instruction.</li>
                    <li>To provide support, prevent abuse, and improve the platform.</li>
                    <li>To send you service, billing and account notifications.</li>
                </ul>

                <h4>How we share it</h4>
                <ul>
                    <li>With FBR / PRA and their official APIs, as required to submit the documents you generate.</li>
                    <li>With trusted service providers (such as hosting) strictly to run the platform.</li>
                    <li>Where required by law or a lawful order.</li>
                    <li>We do <strong>not</strong> sell your data.</li>
                </ul>

                <h4>Data security &amp; retention</h4>
                <p>We protect data in transit and apply access controls and immutable audit logs. Records are retained for as long as your account is active and for the period required by applicable tax law. You may request access to, or correction of, your data by contacting us.</p>

                <h4>Your choices</h4>
                <p>You control the data you enter and can request account closure. Some records must be retained to meet legal and tax obligations even after closure.</p>
            </div>

            <!-- Terms & Conditions -->
            <div id="terms" class="scroll-mt-24 legal-prose">
                <h3 class="text-3xl font-serif text-[#052730] mb-6">Terms &amp; Conditions</h3>
                <p>These Terms govern your use of the TaxNest platform operated by {{ $legalName }}. By creating an account or using the service you accept these Terms.</p>

                <h4>1. Eligibility &amp; account</h4>
                <p>You must be authorised to act for the business you register. You are responsible for the confidentiality of your credentials and for all activity under your account.</p>

                <h4>2. The service</h4>
                <p>TaxNest provides invoicing and point-of-sale software with connectivity to FBR / PRA. We use reasonable efforts to keep the service available but do not guarantee uninterrupted or error-free operation, particularly where third-party government systems are involved.</p>

                <h4>3. Your data &amp; compliance</h4>
                <p>You are solely responsible for the accuracy and legality of the data you enter and the documents you submit. TaxNest transmits data on your instruction and is not responsible for your filing, tax liability, or penalties arising from incorrect data, misuse, or your own regulatory non-compliance.</p>

                <h4>4. Subscriptions &amp; billing</h4>
                <p>Plan features, prices and billing cycles are as shown at the time of purchase. Subscriptions may renew per the cycle you select. Any trial terms and refund eligibility are as communicated at purchase.</p>

                <h4>5. Acceptable use</h4>
                <p>You agree not to misuse the platform, attempt unauthorised access, issue fraudulent documents, or use the service for any unlawful purpose.</p>

                <h4>6. Limitation of liability</h4>
                <p>To the maximum extent permitted by law, TaxNest is not liable for indirect, incidental or consequential losses, or for taxes, fines or penalties resulting from your data, your decisions, or actions of the tax authorities. Our total liability for any claim is limited to the fees you paid for the service in the preceding twelve months.</p>

                <h4>7. Suspension &amp; termination</h4>
                <p>We may suspend or terminate access for breach of these Terms or non-payment. You may stop using the service at any time, subject to your outstanding obligations.</p>

                <h4>8. Governing law</h4>
                <p>These Terms are governed by the laws of the Islamic Republic of Pakistan, and the courts of Pakistan have jurisdiction over any dispute.</p>

                <h4>9. Changes</h4>
                <p>We may update these Terms and this Policy from time to time. Continued use of the platform after changes take effect constitutes acceptance. For any questions, please contact us using the details above.</p>
            </div>

        </section>
    </main>

    <x-site-footer />
    <x-whatsapp-support />
</body>
</html>
