<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-200 leading-tight">Business Profile</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
            <div class="p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-xl text-emerald-700 dark:text-emerald-300 font-medium">{{ session('success') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-2">
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $company->name }}</h3>
                        @if($company->owner_name)
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Owner: {{ $company->owner_name }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if($company->ntn)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700">NTN: {{ $company->ntn }}</span>
                        @endif
                        @if($company->cnic)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700">CNIC: {{ $company->cnic }}</span>
                        @endif
                        @if($company->registration_no)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700">Reg #: {{ $company->registration_no }}</span>
                        @endif
                        @if($company->company_status)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $company->company_status === 'approved' ? 'bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-700' : 'bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-700' }}">{{ ucfirst($company->company_status) }}</span>
                        @endif
                    </div>
                </div>
                @if($company->address || $company->phone || $company->mobile || $company->email || $company->city || $company->website)
                <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                    @if($company->address)
                    <p class="text-sm text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-600 dark:text-gray-300">Address:</span> {{ $company->address }}@if($company->city), {{ $company->city }}@endif</p>
                    @endif
                    @if($company->phone)
                    <p class="text-sm text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-600 dark:text-gray-300">Phone:</span> {{ $company->phone }}</p>
                    @endif
                    @if($company->mobile)
                    <p class="text-sm text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-600 dark:text-gray-300">Mobile:</span> {{ $company->mobile }}</p>
                    @endif
                    @if($company->email)
                    <p class="text-sm text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-600 dark:text-gray-300">Email:</span> {{ $company->email }}</p>
                    @endif
                    @if($company->website)
                    <p class="text-sm text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-600 dark:text-gray-300">Website:</span> {{ $company->website }}</p>
                    @endif
                </div>
                @endif
            </div>

            <form method="POST" action="/company/profile" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 pb-3 border-b border-gray-100 dark:border-gray-800">Business Information</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Business Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $company->name) }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Owner / Proprietor Name</label>
                            <input type="text" name="owner_name" value="{{ old('owner_name', $company->owner_name) }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Registration No</label>
                            <input type="text" name="registration_no" value="{{ old('registration_no', $company->registration_no) }}" placeholder="e.g. REG-12345" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Business Activity</label>
                            <input type="text" name="business_activity" value="{{ old('business_activity', $company->business_activity) }}" placeholder="e.g. Retailer, Manufacturer, Distributor" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 pb-3 border-b border-gray-100 dark:border-gray-800">Tax Registration (Read Only)</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">NTN (National Tax Number)</label>
                            <input type="text" value="{{ $company->ntn }}" disabled class="w-full rounded-lg bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                            <p class="text-xs text-gray-400 mt-1">Cannot be changed after registration</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CNIC</label>
                            <input type="text" value="{{ $company->cnic }}" disabled class="w-full rounded-lg bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                            <p class="text-xs text-gray-400 mt-1">Cannot be changed after registration</p>
                        </div>
                        @if($company->fbr_registration_no)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">FBR Registration No</label>
                            <input type="text" value="{{ $company->fbr_registration_no }}" disabled class="w-full rounded-lg bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                        </div>
                        @endif
                        @if($company->province)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Province</label>
                            <input type="text" value="{{ $company->province }}" disabled class="w-full rounded-lg bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed">
                        </div>
                        @endif
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 pb-3 border-b border-gray-100 dark:border-gray-800">Contact Details</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 pb-3 border-b border-gray-100 dark:border-gray-800">Address</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Address</label>
                            <textarea name="address" rows="2" placeholder="Street address, area, etc." class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('address', $company->address) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                            <input type="text" name="city" value="{{ old('city', $company->city) }}" placeholder="e.g. Lahore, Karachi, Islamabad" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">These details will appear on your invoices</h4>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                        <p class="text-sm text-gray-600 dark:text-gray-300"><strong>{{ $company->name }}</strong></p>
                        @if($company->ntn)<p class="text-xs text-gray-500">NTN: {{ $company->ntn }}</p>@endif
                        @if($company->cnic)<p class="text-xs text-gray-500">CNIC: {{ $company->cnic }}</p>@endif
                        @if($company->registration_no)<p class="text-xs text-gray-500">Reg #: {{ $company->registration_no }}</p>@endif
                        @if($company->address)<p class="text-xs text-gray-500 mt-1">{{ $company->address }}@if($company->city), {{ $company->city }}@endif</p>@endif
                        @if($company->phone)<p class="text-xs text-gray-500">Phone: {{ $company->phone }}</p>@endif
                        @if($company->mobile)<p class="text-xs text-gray-500">Mobile: {{ $company->mobile }}</p>@endif
                        @if($company->email)<p class="text-xs text-gray-500">Email: {{ $company->email }}</p>@endif
                        @if($company->website)<p class="text-xs text-gray-500">Website: {{ $company->website }}</p>@endif
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
