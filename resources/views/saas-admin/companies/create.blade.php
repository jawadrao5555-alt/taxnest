<x-admin-layout>
<div class="p-4 sm:p-6 max-w-4xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.companies') }}" class="text-gray-500 hover:text-indigo-400 transition text-sm">&larr; Back</a>
        <h1 class="text-2xl font-bold text-white">Create New Company</h1>
    </div>

    @if($errors->any())
    <div class="bg-red-900/30 border border-red-700 rounded-xl p-4 mb-6">
        <ul class="text-sm text-red-400 space-y-1">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('saas.admin.companies.store') }}" class="space-y-6" x-data="{ productType: '{{ old('product_type', 'di') }}' }">
        @csrf

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-4">Company Type</h3>
            <div class="grid grid-cols-2 gap-3">
                <label :class="productType === 'di' ? 'border-emerald-500 bg-emerald-900/20' : 'border-gray-700 hover:border-gray-600'" class="relative flex items-center gap-3 p-4 rounded-xl border-2 cursor-pointer transition">
                    <input type="radio" name="product_type" value="di" x-model="productType" class="hidden">
                    <div :class="productType === 'di' ? 'bg-emerald-600' : 'bg-gray-700'" class="w-10 h-10 rounded-lg flex items-center justify-center transition">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">Digital Invoice</p>
                        <p class="text-[10px] text-gray-500">FBR integrated invoicing</p>
                    </div>
                </label>
                <label :class="productType === 'pos' ? 'border-purple-500 bg-purple-900/20' : 'border-gray-700 hover:border-gray-600'" class="relative flex items-center gap-3 p-4 rounded-xl border-2 cursor-pointer transition">
                    <input type="radio" name="product_type" value="pos" x-model="productType" class="hidden">
                    <div :class="productType === 'pos' ? 'bg-purple-600' : 'bg-gray-700'" class="w-10 h-10 rounded-lg flex items-center justify-center transition">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white">NestPOS</p>
                        <p class="text-[10px] text-gray-500">PRA integrated POS</p>
                    </div>
                </label>
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-4">Company Information</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Company Name <span class="text-red-400">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. ABC Traders Pvt Ltd">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Owner Name <span class="text-red-400">*</span></label>
                    <input type="text" name="owner_name" value="{{ old('owner_name') }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="Full name">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Company Email <span class="text-red-400">*</span></label>
                    <input type="email" name="email" value="{{ old('email') }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="company@example.com">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">NTN</label>
                    <input type="text" name="ntn" value="{{ old('ntn') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="National Tax Number">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">CNIC</label>
                    <input type="text" name="cnic" value="{{ old('cnic') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. 35202-XXXXXXX-X">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. 042-XXXXXXX">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Mobile</label>
                    <input type="text" name="mobile" value="{{ old('mobile') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. 03XX-XXXXXXX">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">City</label>
                    <input type="text" name="city" value="{{ old('city') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. Lahore">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Province</label>
                    <select name="province" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Select Province</option>
                        <option value="Punjab" {{ old('province') === 'Punjab' ? 'selected' : '' }}>Punjab</option>
                        <option value="Sindh" {{ old('province') === 'Sindh' ? 'selected' : '' }}>Sindh</option>
                        <option value="KPK" {{ old('province') === 'KPK' ? 'selected' : '' }}>KPK</option>
                        <option value="Balochistan" {{ old('province') === 'Balochistan' ? 'selected' : '' }}>Balochistan</option>
                        <option value="Islamabad" {{ old('province') === 'Islamabad' ? 'selected' : '' }}>Islamabad</option>
                        <option value="AJK" {{ old('province') === 'AJK' ? 'selected' : '' }}>AJK</option>
                        <option value="GB" {{ old('province') === 'GB' ? 'selected' : '' }}>Gilgit-Baltistan</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Business Activity</label>
                    <input type="text" name="business_activity" value="{{ old('business_activity') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. Retail, Manufacturing">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs text-gray-400 mb-1 block">Address</label>
                    <input type="text" name="address" value="{{ old('address') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="Full address">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Website</label>
                    <input type="text" name="website" value="{{ old('website') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="https://example.com">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Franchise</label>
                    <select name="franchise_id" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="">None</option>
                        @foreach($franchises as $f)
                        <option value="{{ $f->id }}" {{ old('franchise_id') == $f->id ? 'selected' : '' }}>{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Initial Status</label>
                    <select name="status" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="approved" {{ old('status', 'approved') === 'approved' ? 'selected' : '' }}>Approved (Active)</option>
                        <option value="pending" {{ old('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-1">Company Admin Account</h3>
            <p class="text-xs text-gray-500 mb-4">This user will be the company administrator.</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Admin Name <span class="text-red-400">*</span></label>
                    <input type="text" name="admin_name" value="{{ old('admin_name') }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="Admin full name">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Admin Email <span class="text-red-400">*</span></label>
                    <input type="email" name="admin_email" value="{{ old('admin_email') }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="admin@company.com">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Password <span class="text-red-400">*</span></label>
                    <input type="text" name="admin_password" value="{{ old('admin_password', 'Admin@12345') }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Create Company</button>
            <a href="{{ route('saas.admin.companies') }}" class="px-6 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm rounded-lg transition">Cancel</a>
        </div>
    </form>
</div>
</x-admin-layout>
