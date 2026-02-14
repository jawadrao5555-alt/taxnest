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

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Invoice Preview - These details appear on your invoices</h3>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                        <p class="text-sm text-gray-600 dark:text-gray-300"><strong>{{ $company->name }}</strong></p>
                        @if($company->ntn)<p class="text-xs text-gray-500">NTN: {{ $company->ntn }}</p>@endif
                        @if($company->cnic && $company->cnic !== $company->ntn && $company->cnic !== $company->registration_no)<p class="text-xs text-gray-500">CNIC: {{ $company->cnic }}</p>@endif
                        @if($company->registration_no)<p class="text-xs text-gray-500">Reg #: {{ $company->registration_no }}</p>@endif
                        @if($company->address)<p class="text-xs text-gray-500 mt-1">{{ $company->address }}@if($company->city), {{ $company->city }}@endif</p>@endif
                        @if($company->phone)<p class="text-xs text-gray-500">Phone: {{ $company->phone }}</p>@endif
                        @if($company->mobile && $company->mobile !== $company->phone)<p class="text-xs text-gray-500">Mobile: {{ $company->mobile }}</p>@endif
                        @if($company->email)<p class="text-xs text-gray-500">Email: {{ $company->email }}</p>@endif
                        @if($company->website)<p class="text-xs text-gray-500">Website: {{ $company->website }}</p>@endif
                    </div>
                </div>

                <div class="flex justify-end">
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
