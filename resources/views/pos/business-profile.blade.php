<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Business Profile</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Manage your business details. This information appears on your POS receipts.</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm border border-emerald-200 dark:border-emerald-800">{{ session('success') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm border border-red-200 dark:border-red-800">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('pos.business-profile') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Business Logo
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Upload your business logo to print on receipts. Recommended: Square image, max 2MB (JPEG, PNG, WebP).</p>

            <div x-data="{ showRemove: {{ $company->logo_path ? 'true' : 'false' }}, previewUrl: '{{ $company->logo_path ? asset('storage/' . $company->logo_path) : '' }}' }" class="flex items-start gap-6">
                <div class="flex-shrink-0">
                    <template x-if="previewUrl">
                        <img :src="previewUrl" alt="Business Logo" class="w-24 h-24 rounded-xl object-contain border-2 border-gray-200 dark:border-gray-700 bg-white p-1">
                    </template>
                    <template x-if="!previewUrl">
                        <div class="w-24 h-24 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center bg-gray-50 dark:bg-gray-800">
                            <svg class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                    </template>
                </div>
                <div class="flex-1 space-y-2">
                    <input type="file" name="logo" accept="image/jpeg,image/jpg,image/png,image/webp"
                        class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300"
                        @change="const file = $event.target.files[0]; if(file) { previewUrl = URL.createObjectURL(file); showRemove = false; }">
                    <template x-if="showRemove">
                        <label class="inline-flex items-center gap-2 text-sm text-red-600 cursor-pointer hover:text-red-700">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Remove current logo
                        </label>
                    </template>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                Business Information
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Business Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $company->name) }}" required
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Owner / Proprietor Name</label>
                    <input type="text" name="owner_name" value="{{ old('owner_name', $company->owner_name) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">NTN (National Tax Number)</label>
                    <input type="text" name="ntn" value="{{ old('ntn', $company->ntn) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="e.g. 1234567-8">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Business Activity</label>
                    <input type="text" name="business_activity" value="{{ old('business_activity', $company->business_activity) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="e.g. Retail, Restaurant, Pharmacy">
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                Contact Details
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="info@yourbusiness.com">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone (Landline)</label>
                    <input type="text" name="phone" value="{{ old('phone', $company->phone) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="042-35761234">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Mobile</label>
                    <input type="text" name="mobile" value="{{ old('mobile', $company->mobile) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="0300-1234567">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Website</label>
                    <input type="url" name="website" value="{{ old('website', $company->website) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="https://yourbusiness.com">
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Business Address
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">This address will print on your POS receipts.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Full Address</label>
                    <textarea name="address" rows="2"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="Shop #, Street, Area">{{ old('address', $company->address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">City</label>
                    <input type="text" name="city" value="{{ old('city', $company->city) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="e.g. Lahore, Karachi, Islamabad">
                </div>
            </div>
        </div>

        <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-xl p-4">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-purple-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <div>
                    <p class="text-sm font-medium text-purple-800 dark:text-purple-300">Receipt Preview</p>
                    <p class="text-xs text-purple-600 dark:text-purple-400 mt-0.5">Your business name, address, phone, NTN, and logo (if uploaded) will appear at the top of every POS receipt printed from your terminals.</p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Customize
            </a>
            <button type="submit" class="px-8 py-2.5 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition shadow-sm">
                Save Business Profile
            </button>
        </div>
    </form>

    {{-- ============ F8: PUBLIC QR PROFILE + MENU (admin only) ============ --}}
    @if(isset($ppSettings) && auth('pos')->user() && !auth('pos')->user()->isPosCashier())
    <div class="mt-10 space-y-6">
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Public QR Profile &amp; Menu</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Customers scan a QR code on local receipts to see your business page and live menu. You control exactly what is shown.</p>
        </div>

        {{-- Settings + link --}}
        <form method="POST" action="{{ route('pos.public-profile.save') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-5">
            @csrf
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Public Page</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Turn on to get a private QR link for your business page.</p>
                </div>
                <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="pp_enabled" value="1" {{ ($ppSettings['enabled'] ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-5 h-5">
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Enabled</span>
                </label>
            </div>

            @if($ppUrl)
            <div class="flex flex-col sm:flex-row items-center gap-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-4">
                @if($ppQr)
                <img src="{{ $ppQr }}" alt="Public profile QR" class="w-28 h-28 rounded-lg bg-white p-1.5 border border-gray-200">
                @endif
                <div class="min-w-0 flex-1 text-center sm:text-left" x-data="{ copied: false }">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Your public link</p>
                    <p class="text-sm font-mono text-teal-800 dark:text-teal-300 break-all">{{ $ppUrl }}</p>
                    <div class="mt-2 flex items-center justify-center sm:justify-start gap-2">
                        <button type="button" @click="navigator.clipboard.writeText('{{ $ppUrl }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                            <span x-show="!copied">Copy Link</span><span x-show="copied" x-cloak class="text-emerald-600">Copied!</span>
                        </button>
                        <a href="{{ $ppUrl }}" target="_blank" rel="noopener" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Open Page</a>
                    </div>
                </div>
            </div>
            @elseif($ppSettings['enabled'] ?? false)
            <p class="text-xs text-amber-600 dark:text-amber-400">Save once to generate your link and QR code.</p>
            @endif

            <div>
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wide mb-3">What to show on the public page</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach([
                        'show_phone' => 'Phone',
                        'show_mobile' => 'Mobile',
                        'show_email' => 'Email',
                        'show_address' => 'Address',
                        'show_ntn' => 'NTN',
                        'show_website' => 'Website',
                        'show_hours' => 'Opening Hours',
                        'show_menu' => 'Menu',
                    ] as $key => $label)
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="pp_{{ $key }}" value="1" {{ ($ppSettings[$key] ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Opening Hours (text)</label>
                    <input type="text" name="hours_text" value="{{ old('hours_text', $ppSettings['hours_text'] ?? '') }}" maxlength="200"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="e.g. Daily 12pm – 12am">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">About / Tagline</label>
                    <input type="text" name="about_text" value="{{ old('about_text', $ppSettings['about_text'] ?? '') }}" maxlength="600"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="Short intro shown under your business name">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="submit" class="px-6 py-2.5 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition shadow-sm">Save Public Profile</button>
            </div>
        </form>

        @if($company->public_profile_slug)
        <form method="POST" action="{{ route('pos.public-profile.regenerate') }}" onsubmit="return confirm('Naya link banane se purane chhape hue QR codes kaam karna band kar denge. Continue?');" class="flex justify-end">
            @csrf
            <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700 underline underline-offset-2">Generate a new link (old QR codes stop working)</button>
        </form>
        @endif

        {{-- Menu builder --}}
        <form method="POST" action="{{ route('pos.public-profile.menu') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6"
            x-data="{ selected: {{ json_encode(array_map('intval', array_values($ppSelectedIds ?? []))) ?: '[]' }} }">
            @csrf
            <div class="flex items-center justify-between gap-4 mb-1">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Public Menu Items</h3>
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400" x-text="selected.length + ' selected'"></span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Tick the products to show on your public menu. Prices always show LIVE from your product list — update the product, the menu updates itself.</p>

            @if(($ppProducts ?? collect())->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No active products yet — add products first, then build your menu.</p>
            @else
            <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach(($ppProducts ?? collect())->groupBy(fn ($p) => trim((string) $p->category) !== '' ? $p->category : 'Uncategorized') as $cat => $prods)
                <div>
                    <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800 text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 sticky top-0">{{ $cat }}</div>
                    @foreach($prods as $p)
                    <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/60 transition">
                        <input type="checkbox" name="menu_product_ids[]" value="{{ $p->id }}"
                            {{ in_array((int) $p->id, $ppSelectedIds ?? [], true) ? 'checked' : '' }}
                            @change="$event.target.checked ? selected.push({{ (int) $p->id }}) : selected = selected.filter(i => i !== {{ (int) $p->id }})"
                            class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="flex-1 text-sm text-gray-800 dark:text-gray-200 min-w-0 truncate">{{ $p->name }}</span>
                        <span class="text-xs font-bold text-teal-800 dark:text-teal-300 shrink-0">Rs {{ number_format((float) $p->price, 2) }}</span>
                    </label>
                    @endforeach
                </div>
                @endforeach
            </div>
            <div class="flex items-center justify-end mt-4">
                <button type="submit" class="px-6 py-2.5 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition shadow-sm">Save Menu</button>
            </div>
            @endif
        </form>
    </div>
    @endif
</div>
</x-pos-layout>
