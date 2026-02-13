<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Company Profile</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif

            <form method="POST" action="/company/profile" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                    <input type="text" name="name" value="{{ old('name', $company->name) }}" required class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Owner / Proprietor Name</label>
                    <input type="text" name="owner_name" value="{{ old('owner_name', $company->owner_name) }}" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">NTN</label>
                        <input type="text" value="{{ $company->ntn }}" disabled class="w-full rounded-lg bg-gray-100 border-gray-300 text-gray-500 cursor-not-allowed">
                        <p class="text-xs text-gray-400 mt-1">NTN cannot be changed after registration</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">CNIC</label>
                        <input type="text" value="{{ $company->cnic }}" disabled class="w-full rounded-lg bg-gray-100 border-gray-300 text-gray-500 cursor-not-allowed">
                        <p class="text-xs text-gray-400 mt-1">CNIC cannot be changed after registration</p>
                    </div>
                </div>
                @if($company->fbr_registration_no)
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">FBR Registration No</label>
                    <input type="text" value="{{ $company->fbr_registration_no }}" disabled class="w-full rounded-lg bg-gray-100 border-gray-300 text-gray-500 cursor-not-allowed">
                </div>
                @endif
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email', $company->email) }}" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone / Cell</label>
                        <input type="text" name="phone" value="{{ old('phone', $company->phone) }}" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Business Activity</label>
                    <input type="text" name="business_activity" value="{{ old('business_activity', $company->business_activity) }}" placeholder="e.g. Retailer, Manufacturer" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('address', $company->address) }}</textarea>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
