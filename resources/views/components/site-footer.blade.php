@props(['loginUrl' => '/login', 'loginLabel' => 'Log In'])
@php
    $footerLegalName = \App\Models\SystemSetting::get('company_legal_name', '') ?: 'TaxNest';
    $footerEmail = \App\Models\SystemSetting::get('contact_email', '');
    $footerPhone = \App\Models\SystemSetting::get('contact_phone', '');
    $footerAddress = \App\Models\SystemSetting::get('contact_address', '');
@endphp

<footer class="bg-[#052730] border-t-4 border-[#0A4D5C]">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-10">
            <div class="md:col-span-2">
                <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-8 w-auto mb-5">
                <p class="text-sm text-white/60 leading-relaxed max-w-sm mb-6">
                    Pakistan's tax compliance platform — FBR Digital Invoicing, PRA Point of Sale, and FBR POS, engineered under one roof.
                </p>
                <ul class="space-y-2.5 text-sm text-white/60">
                    @if($footerEmail)
                    <li class="flex items-center gap-3">
                        <svg class="w-4 h-4 text-white/40 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <a href="mailto:{{ $footerEmail }}" class="hover:text-white transition-colors">{{ $footerEmail }}</a>
                    </li>
                    @endif
                    @if($footerPhone)
                    <li class="flex items-center gap-3">
                        <svg class="w-4 h-4 text-white/40 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11 11 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $footerPhone) }}" class="hover:text-white transition-colors">{{ $footerPhone }}</a>
                    </li>
                    @endif
                    @if($footerAddress)
                    <li class="flex items-start gap-3">
                        <svg class="w-4 h-4 text-white/40 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span>{{ $footerAddress }}</span>
                    </li>
                    @endif
                </ul>
            </div>

            <div>
                <h4 class="text-xs font-semibold uppercase tracking-widest text-white/40 mb-4">Products</h4>
                <ul class="space-y-3 text-sm text-white/60">
                    <li><a href="/digital-invoice" class="hover:text-white transition-colors">Digital Invoice</a></li>
                    <li><a href="/pos" class="hover:text-white transition-colors">NestPOS (PRA)</a></li>
                    <li><a href="/fbr-pos-landing" class="hover:text-white transition-colors">FBR POS</a></li>
                    <li><a href="/#compare" class="hover:text-white transition-colors">Compare</a></li>
                    <li><a href="/download" class="hover:text-white transition-colors">Downloads</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-xs font-semibold uppercase tracking-widest text-white/40 mb-4">Company</h4>
                <ul class="space-y-3 text-sm text-white/60">
                    <li><a href="/contact" class="hover:text-white transition-colors">Contact Us</a></li>
                    <li><a href="/contact#responsibility" class="hover:text-white transition-colors">Responsibilities</a></li>
                    {{-- Privacy ab apna poora safha hai (Play ki shart: public URL) --}}
                    <li><a href="/privacy" class="hover:text-white transition-colors">Privacy Policy</a></li>
                    <li><a href="/contact#terms" class="hover:text-white transition-colors">Terms &amp; Conditions</a></li>
                    <li><a href="/data-deletion" class="hover:text-white transition-colors">Data Deletion</a></li>
                    <li><a href="{{ $loginUrl }}" class="hover:text-white transition-colors">{{ $loginLabel }}</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-12 pt-8 border-t border-white/10 flex flex-col md:flex-row items-center justify-between gap-4">
            <p class="text-sm text-white/40">&copy; {{ date('Y') }} {{ $footerLegalName }}. All rights reserved.</p>
            <p class="text-xs text-white/30 tracking-wide uppercase">FBR &amp; PRA Compliant · Built in Pakistan</p>
        </div>
    </div>
</footer>
