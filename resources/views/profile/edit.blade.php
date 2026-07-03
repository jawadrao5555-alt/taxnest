<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="mb-2">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Profile</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your account and business details</p>
            </div>

            @if(session('status') === 'profile-updated')
            <div class="p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-lg text-emerald-700 dark:text-emerald-300 font-medium">Account information updated.</div>
            @endif
            @if(session('status') === 'company-updated')
            <div class="p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-lg text-emerald-700 dark:text-emerald-300 font-medium">Business profile updated.</div>
            @endif
            @if(session('error'))
            <div class="p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-lg text-red-700 dark:text-red-300 font-medium">{{ session('error') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Account Information</h3>
                <form method="post" action="{{ route('profile.update') }}" class="space-y-4">
                    @csrf
                    @method('patch')
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $user->name) }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition shadow-sm">Save Account</button>
                    </div>
                </form>
            </div>

            @if($company && in_array(auth()->user()->role, ['company_admin', 'super_admin']))
            <form method="POST" action="{{ route('profile.updateCompany') }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Business Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Business Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $company->name) }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Owner / Proprietor</label>
                            <input type="text" name="owner_name" value="{{ old('owner_name', $company->owner_name) }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Registration No</label>
                            <input type="text" name="registration_no" value="{{ old('registration_no', $company->registration_no) }}" placeholder="e.g. REG-12345" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Business Activity</label>
                            <input type="text" name="business_activity" value="{{ old('business_activity', $company->business_activity) }}" placeholder="e.g. Retailer, Manufacturer" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Tax Registration</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">NTN (National Tax Number)</label>
                            <input type="text" name="ntn" value="{{ old('ntn', $company->ntn) }}" placeholder="e.g. 1234567-8" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CNIC</label>
                            <input type="text" name="cnic" value="{{ old('cnic', $company->cnic) }}" placeholder="e.g. 12345-1234567-1" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Contact Details</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                            <input type="email" name="email" value="{{ old('email', $company->email) }}" placeholder="company@example.com" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone (Landline)</label>
                            <input type="text" name="phone" value="{{ old('phone', $company->phone) }}" placeholder="042-12345678" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mobile Number</label>
                            <input type="text" name="mobile" value="{{ old('mobile', $company->mobile) }}" placeholder="03XX-XXXXXXX" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Website</label>
                            <input type="text" name="website" value="{{ old('website', $company->website) }}" placeholder="www.example.com" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Address</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Address</label>
                            <textarea name="address" rows="2" placeholder="Street address, area, etc." class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('address', $company->address) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                            <input type="text" name="city" value="{{ old('city', $company->city) }}" placeholder="e.g. Lahore, Karachi" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                @php $dp = $company->displayPrefs('di'); @endphp
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <input type="hidden" name="invoice_prefs_submitted" value="1">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">Invoice Display Options</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Choose which business details appear on your invoice PDFs, and add an optional footer message.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-700 transition">
                            <input type="checkbox" name="dp_show_address" value="1" {{ $dp['show_address'] ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                            <span class="text-sm text-gray-800 dark:text-gray-200">Show Address</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-700 transition">
                            <input type="checkbox" name="dp_show_ntn" value="1" {{ $dp['show_ntn'] ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                            <span class="text-sm text-gray-800 dark:text-gray-200">Show NTN</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-700 transition">
                            <input type="checkbox" name="dp_show_email" value="1" {{ $dp['show_email'] ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                            <span class="text-sm text-gray-800 dark:text-gray-200">Show Email</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-700 transition">
                            <input type="checkbox" name="dp_show_mobile" value="1" {{ $dp['show_mobile'] ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                            <span class="text-sm text-gray-800 dark:text-gray-200">Show Phone / Mobile</span>
                        </label>
                    </div>
                    <div class="mt-4">
                        <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                            <input type="checkbox" name="dp_show_footer" value="1" {{ $dp['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">Show footer message on invoice</span>
                        </label>
                        <input type="text" name="dp_footer_text" value="{{ $dp['footer_text'] }}" maxlength="150" placeholder="e.g. Thank you for your business!"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm"
                            autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leave blank for no custom footer message.</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Invoice Preview - These details appear on your invoices</h3>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                        <p class="text-sm text-gray-600 dark:text-gray-300"><strong>{{ $company->name }}</strong></p>
                        @if($company->ntn)<p class="text-xs text-gray-500 dark:text-gray-400">NTN: {{ $company->ntn }}</p>@endif
                        @if($company->cnic && $company->cnic !== $company->ntn && $company->cnic !== $company->registration_no)<p class="text-xs text-gray-500 dark:text-gray-400">CNIC: {{ $company->cnic }}</p>@endif
                        @if($company->registration_no)<p class="text-xs text-gray-500 dark:text-gray-400">Reg #: {{ $company->registration_no }}</p>@endif
                        @if($company->address)<p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $company->address }}@if($company->city), {{ $company->city }}@endif</p>@endif
                        @if($company->phone)<p class="text-xs text-gray-500 dark:text-gray-400">Phone: {{ $company->phone }}</p>@endif
                        @if($company->mobile && $company->mobile !== $company->phone)<p class="text-xs text-gray-500 dark:text-gray-400">Mobile: {{ $company->mobile }}</p>@endif
                        @if($company->email)<p class="text-xs text-gray-500 dark:text-gray-400">Email: {{ $company->email }}</p>@endif
                        @if($company->website)<p class="text-xs text-gray-500 dark:text-gray-400">Website: {{ $company->website }}</p>@endif
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        Back to Dashboard
                    </a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition shadow-sm">Save Business Profile</button>
                </div>
            </form>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Change Password</h3>
                @include('profile.partials.update-password-form')
            </div>
        </div>
    </div>
</x-app-layout>
