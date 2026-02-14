<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Create Customer Profile</h2>
            <a href="/customer-profiles" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800">Back to Customers</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="/customer-profiles" class="space-y-6" x-data="{
                regType: '{{ old('registration_type', 'Registered') }}'
            }">
                @csrf

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Customer Type</h3>
                    <div class="flex gap-4">
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="registration_type" value="Registered" x-model="regType" class="sr-only peer">
                            <div class="border-2 rounded-xl p-4 text-center transition peer-checked:border-emerald-500 peer-checked:bg-emerald-50 dark:peer-checked:bg-emerald-900/20 border-gray-200 dark:border-gray-700 hover:border-gray-300">
                                <div class="text-2xl mb-1">
                                    <svg class="w-8 h-8 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" :class="regType === 'Registered' ? 'text-emerald-600' : 'text-gray-400'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                </div>
                                <p class="font-semibold text-sm" :class="regType === 'Registered' ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-600 dark:text-gray-400'">FBR Registered</p>
                                <p class="text-xs text-gray-500 mt-1">NTN, CNIC & full details required</p>
                            </div>
                        </label>
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="registration_type" value="Unregistered" x-model="regType" class="sr-only peer">
                            <div class="border-2 rounded-xl p-4 text-center transition peer-checked:border-amber-500 peer-checked:bg-amber-50 dark:peer-checked:bg-amber-900/20 border-gray-200 dark:border-gray-700 hover:border-gray-300">
                                <div class="text-2xl mb-1">
                                    <svg class="w-8 h-8 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" :class="regType === 'Unregistered' ? 'text-amber-600' : 'text-gray-400'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                </div>
                                <p class="font-semibold text-sm" :class="regType === 'Unregistered' ? 'text-amber-700 dark:text-amber-400' : 'text-gray-600 dark:text-gray-400'">Unregistered</p>
                                <p class="text-xs text-gray-500 mt-1">Only name & address needed</p>
                            </div>
                        </label>
                    </div>
                    @error('registration_type') <p class="text-red-500 text-xs mt-2">{{ $message }}</p> @enderror
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Customer Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Customer Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name') }}" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div x-show="regType === 'Registered'" x-cloak>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">NTN <span class="text-red-500">*</span></label>
                            <input type="text" name="ntn" value="{{ old('ntn') }}" placeholder="1234567-8"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('ntn') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div x-show="regType === 'Registered'" x-cloak>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CNIC <span class="text-red-500">*</span></label>
                            <input type="text" name="cnic" value="{{ old('cnic') }}" placeholder="12345-1234567-1"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('cnic') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Province <span class="text-red-500">*</span></label>
                            <select name="province" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Select Province</option>
                                <option value="Punjab" {{ old('province') == 'Punjab' ? 'selected' : '' }}>Punjab</option>
                                <option value="Sindh" {{ old('province') == 'Sindh' ? 'selected' : '' }}>Sindh</option>
                                <option value="KPK" {{ old('province') == 'KPK' ? 'selected' : '' }}>Khyber Pakhtunkhwa (KPK)</option>
                                <option value="Balochistan" {{ old('province') == 'Balochistan' ? 'selected' : '' }}>Balochistan</option>
                                <option value="Islamabad" {{ old('province') == 'Islamabad' ? 'selected' : '' }}>Islamabad Capital Territory</option>
                                <option value="AJK" {{ old('province') == 'AJK' ? 'selected' : '' }}>Azad Jammu & Kashmir (AJK)</option>
                                <option value="Gilgit-Baltistan" {{ old('province') == 'Gilgit-Baltistan' ? 'selected' : '' }}>Gilgit-Baltistan</option>
                                <option value="FATA" {{ old('province') == 'FATA' ? 'selected' : '' }}>Tribal Areas (FATA)</option>
                            </select>
                            @error('province') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div x-show="regType === 'Registered'" x-cloak>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                            <input type="text" name="phone" value="{{ old('phone') }}" placeholder="+92-300-1234567"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div x-show="regType === 'Registered'" x-cloak>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                            <input type="email" name="email" value="{{ old('email') }}" placeholder="customer@example.com"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address <span x-show="regType === 'Unregistered'" class="text-gray-400">(Optional)</span></label>
                            <textarea name="address" rows="3" placeholder="Full address"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('address') }}</textarea>
                            @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/customer-profiles" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
                        Create Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
