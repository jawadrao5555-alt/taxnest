<x-admin-layout>
<div class="p-4 sm:p-6 max-w-4xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-gray-500 dark:text-gray-400 hover:text-indigo-400 transition text-sm">&larr; Back</a>
        <h1 class="text-2xl font-bold text-white">Edit: {{ $company->name }}</h1>
        @php
            $tc = ['di' => 'bg-emerald-900/30 text-emerald-400', 'pos' => 'bg-purple-900/30 text-purple-400', 'fbrpos' => 'bg-blue-900/30 text-blue-400'];
            $typeLabels = ['di' => 'Digital Invoice', 'pos' => 'NestPOS', 'fbrpos' => 'FBR POS'];
        @endphp
        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $tc[$company->product_type] ?? '' }}">{{ $typeLabels[$company->product_type] ?? $company->product_type }}</span>
    </div>

    @if($errors->any())
    <div class="bg-red-900/30 border border-red-700 rounded-xl p-4 mb-6">
        <ul class="text-sm text-red-400 space-y-1">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('saas.admin.companies.update', $company->id) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-4">Company Information</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Company Name <span class="text-red-400">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $company->name) }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Owner Name</label>
                    <input type="text" name="owner_name" value="{{ old('owner_name', $company->owner_name) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Company Email</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">NTN</label>
                    <input type="text" name="ntn" value="{{ old('ntn', $company->ntn) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">CNIC</label>
                    <input type="text" name="cnic" value="{{ old('cnic', $company->cnic) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $company->phone) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Mobile</label>
                    <input type="text" name="mobile" value="{{ old('mobile', $company->mobile) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">City</label>
                    <input type="text" name="city" value="{{ old('city', $company->city) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Province</label>
                    <select name="province" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Select Province</option>
                        @foreach(['Punjab','Sindh','KPK','Balochistan','Islamabad','AJK','GB'] as $p)
                        <option value="{{ $p }}" {{ old('province', $company->province) === $p ? 'selected' : '' }}>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Business Activity</label>
                    <input type="text" name="business_activity" value="{{ old('business_activity', $company->business_activity) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs text-gray-400 mb-1 block">Address</label>
                    <input type="text" name="address" value="{{ old('address', $company->address) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Website</label>
                    <input type="text" name="website" value="{{ old('website', $company->website) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Franchise</label>
                    <select name="franchise_id" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="">None</option>
                        @foreach($franchises as $f)
                        <option value="{{ $f->id }}" {{ old('franchise_id', $company->franchise_id) == $f->id ? 'selected' : '' }}>{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Standard Tax Rate (%)</label>
                    <input type="number" name="standard_tax_rate" value="{{ old('standard_tax_rate', $company->standard_tax_rate) }}" step="0.01" min="0" max="100" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Invoice Number Prefix</label>
                    <input type="text" name="invoice_number_prefix" value="{{ old('invoice_number_prefix', $company->invoice_number_prefix) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600" placeholder="e.g. INV-">
                </div>
            </div>
        </div>

        @if($company->product_type === 'di')
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-4">FBR Settings</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">FBR Environment</label>
                    <select name="fbr_environment" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="">Not Set</option>
                        <option value="sandbox" {{ old('fbr_environment', $company->fbr_environment) === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                        <option value="production" {{ old('fbr_environment', $company->fbr_environment) === 'production' ? 'selected' : '' }}>Production</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">FBR Registration No</label>
                    <input type="text" name="fbr_registration_no" value="{{ old('fbr_registration_no', $company->fbr_registration_no) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs text-gray-400 mb-1 block">FBR Business Name</label>
                    <input type="text" name="fbr_business_name" value="{{ old('fbr_business_name', $company->fbr_business_name) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
            </div>
        </div>
        @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-4">PRA Settings</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">PRA Environment</label>
                    <input type="text" name="pra_environment" value="{{ old('pra_environment', $company->pra_environment) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">POS ID</label>
                    <input type="text" name="pra_pos_id" value="{{ old('pra_pos_id', $company->pra_pos_id) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
            </div>
        </div>
        @endif

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save Changes</button>
            <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="px-6 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm rounded-lg transition">Cancel</a>
        </div>
    </form>
</div>
</x-admin-layout>
