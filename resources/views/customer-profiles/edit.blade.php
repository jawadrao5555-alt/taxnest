<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Edit Customer Profile</h2>
            <a href="/customer-profiles" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800">Back to Customers</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="/customer-profiles/{{ $customerProfile->id }}" class="space-y-6" x-data="{
                ntn: '{{ old('ntn', $customerProfile->ntn ?? '') }}',
                get registrationType() {
                    let digits = this.ntn.replace(/[^0-9]/g, '');
                    return digits.length >= 7 ? 'Registered' : 'Unregistered';
                }
            }">
                @csrf
                @method('PUT')

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Customer Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Customer Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $customerProfile->name) }}" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">NTN</label>
                            <input type="text" name="ntn" x-model="ntn" value="{{ old('ntn', $customerProfile->ntn) }}" placeholder="1234567-8"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-xs mt-1" :class="registrationType === 'Registered' ? 'text-blue-600' : 'text-gray-500'">
                                Registration Type: <span x-text="registrationType" class="font-medium"></span>
                            </p>
                            @error('ntn') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                CNIC <span x-show="registrationType === 'Unregistered'" class="text-gray-400">(Optional)</span>
                            </label>
                            <input type="text" name="cnic" value="{{ old('cnic', $customerProfile->cnic) }}" placeholder="12345-1234567-1"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('cnic') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            @php $currentProvince = old('province', $customerProfile->province); @endphp
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Province <span class="text-red-500">*</span></label>
                            <select name="province" required
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Select Province</option>
                                <option value="Punjab" {{ $currentProvince == 'Punjab' ? 'selected' : '' }}>Punjab</option>
                                <option value="Sindh" {{ $currentProvince == 'Sindh' ? 'selected' : '' }}>Sindh</option>
                                <option value="KPK" {{ $currentProvince == 'KPK' ? 'selected' : '' }}>Khyber Pakhtunkhwa (KPK)</option>
                                <option value="Balochistan" {{ $currentProvince == 'Balochistan' ? 'selected' : '' }}>Balochistan</option>
                                <option value="Islamabad" {{ $currentProvince == 'Islamabad' ? 'selected' : '' }}>Islamabad Capital Territory</option>
                                <option value="AJK" {{ $currentProvince == 'AJK' ? 'selected' : '' }}>Azad Jammu & Kashmir (AJK)</option>
                                <option value="Gilgit-Baltistan" {{ $currentProvince == 'Gilgit-Baltistan' ? 'selected' : '' }}>Gilgit-Baltistan</option>
                                <option value="FATA" {{ $currentProvince == 'FATA' ? 'selected' : '' }}>Tribal Areas (FATA)</option>
                            </select>
                            @error('province') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                            <input type="text" name="phone" value="{{ old('phone', $customerProfile->phone) }}" placeholder="+92-300-1234567"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                            <input type="email" name="email" value="{{ old('email', $customerProfile->email) }}" placeholder="customer@example.com"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                            <textarea name="address" rows="3" placeholder="Full address"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('address', $customerProfile->address) }}</textarea>
                            @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/customer-profiles" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
                        Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
