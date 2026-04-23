<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Business Profile</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Update business info, logo &amp; receipt print settings</p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-sm font-medium">
            ✓ {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('fbrpos.business-profile') }}" enctype="multipart/form-data">
        @csrf

        {{-- Business details --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-5 mb-5">
            <h2 class="text-base font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">📝 Business Details</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Business Name *</label>
                <input type="text" name="name" value="{{ old('name', $company->name) }}" required
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                <textarea name="address" rows="2"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">{{ old('address', $company->address) }}</textarea>
                @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $company->phone) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">NTN</label>
                <input type="text" name="ntn" value="{{ old('ntn', $company->ntn) }}"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @error('ntn') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Logo --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-4 mb-5">
            <h2 class="text-base font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">🖼️ Business Logo</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Logo prints on top of every receipt &amp; invoice. Recommended: square or wide PNG/JPG, max 2MB.</p>

            @if($company->logo_path)
                <div class="flex items-start gap-4 p-3 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-200 dark:border-gray-700">
                    <img src="{{ asset('storage/' . $company->logo_path) }}" alt="Current Logo" class="h-20 w-auto max-w-[200px] object-contain bg-white p-1 rounded border">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Current logo</p>
                        <p class="text-xs text-gray-500 mt-1">Upload a new file below to replace, or tick the box to remove.</p>
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-600 dark:text-red-400 cursor-pointer">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Remove current logo
                        </label>
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $company->logo_path ? 'Replace Logo' : 'Upload Logo' }}</label>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"
                    class="w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-blue-600 file:text-white hover:file:bg-blue-700 file:cursor-pointer cursor-pointer">
                @error('logo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Print Settings --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-4 mb-5">
            <h2 class="text-base font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">🖨️ Print Settings</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Receipt Paper Size</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @php $current = old('print_paper_size', $company->print_paper_size ?? 'thermal'); @endphp

                    <label class="relative flex cursor-pointer rounded-lg border-2 p-4 transition
                                 {{ $current === 'thermal' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-300' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-blue-400' }}">
                        <input type="radio" name="print_paper_size" value="thermal" class="sr-only" {{ $current === 'thermal' ? 'checked' : '' }}>
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🧾</span>
                            <div>
                                <div class="font-bold text-sm text-gray-900 dark:text-white">Thermal Printer (80mm)</div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Standard POS thermal roll. Auto-cut after each receipt.</p>
                            </div>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-lg border-2 p-4 transition
                                 {{ $current === 'a4' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-300' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-blue-400' }}">
                        <input type="radio" name="print_paper_size" value="a4" class="sr-only" {{ $current === 'a4' ? 'checked' : '' }}>
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">📄</span>
                            <div>
                                <div class="font-bold text-sm text-gray-900 dark:text-white">A4 Printer (Thermal-style on A4)</div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Receipt prints in 80mm thermal layout, centered on full A4 page. <span class="text-emerald-700 dark:text-emerald-400 font-semibold">No cutting required.</span></p>
                            </div>
                        </div>
                    </label>
                </div>
                @error('print_paper_size') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Receipt Footer Note <span class="text-gray-400 font-normal">(optional)</span></label>
                <input type="text" name="receipt_footer_note" maxlength="255" value="{{ old('receipt_footer_note', $company->receipt_footer_note) }}"
                    placeholder='e.g. "Goods once sold are not returnable"'
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @error('receipt_footer_note') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('fbrpos.dashboard') }}" class="px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:underline">Cancel</a>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-md transition text-sm">
                Save Changes
            </button>
        </div>
    </form>
</div>
</x-fbr-pos-layout>
