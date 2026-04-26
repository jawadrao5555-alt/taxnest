<form method="GET" action="{{ $action }}" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1 uppercase tracking-wide">From</label>
        <input type="date" name="date_from" value="{{ $from }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm">
    </div>
    <div>
        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1 uppercase tracking-wide">To</label>
        <input type="date" name="date_to" value="{{ $to }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm">
    </div>
    <div>
        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1 uppercase tracking-wide">Company</label>
        <input type="number" name="company_id" value="{{ $companyId }}" placeholder="all" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm w-28">
    </div>
    <div>
        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1 uppercase tracking-wide">Branch</label>
        <input type="number" name="branch_id" value="{{ $branchId }}" placeholder="all" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm w-28">
    </div>
    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-lg text-sm">Apply Filters</button>
</form>
