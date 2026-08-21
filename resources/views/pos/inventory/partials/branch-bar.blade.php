{{--
    Per-branch stock (Task 1354): tells the user WHOSE stock is on screen and
    lets an owner flip between one shop and the company-wide view without
    leaving the page.

    Renders nothing for a single-shop company, so the inventory pages look
    exactly as they always did unless branches are actually in use.

    Expects: $multiBranch, $activeBranchId, $activeBranchName, $allBranches
--}}
@if($multiBranch ?? false)
@php
    $branchSvc = app(\App\Services\BranchContextService::class);
    // Only the branches this user may actually reach — a manager confined to
    // one shop must not be able to peek at another's stock from here.
    $switchList = $branchSvc->accessibleBranches();
    $canSeeAll = $branchSvc->isOwner();
    $canSwitchBranch = $branchSvc->canSwitch() || $canSeeAll;
@endphp
<div class="mb-4 rounded-2xl border border-purple-100 dark:border-purple-900/40 bg-purple-50/60 dark:bg-purple-900/10 px-4 py-3">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2.5 min-w-0">
            <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            <p class="text-xs text-gray-700 dark:text-gray-300 truncate">
                @if($allBranches ?? false)
                    <span class="font-bold">{{ __('pos.branch_stock_all_label') }}</span>
                    <span class="text-gray-500 dark:text-gray-400">— {{ __('pos.branch_stock_all_hint') }}</span>
                @else
                    <span class="font-bold">{{ $activeBranchName ?? __('pos.branch_word') }}</span>
                    <span class="text-gray-500 dark:text-gray-400">— {{ __('pos.branch_stock_one_hint') }}</span>
                @endif
            </p>
        </div>
        @if($canSwitchBranch && $switchList->isNotEmpty())
        <form method="POST" action="{{ route('branch.switch') }}" class="flex items-center gap-2 flex-shrink-0">
            @csrf
            <select name="branch_id" onchange="this.form.submit()" class="rounded-xl border border-purple-200 dark:border-purple-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs font-semibold px-3 py-2 focus:ring-2 focus:ring-purple-500">
                @if($canSeeAll)
                <option value="all" {{ ($allBranches ?? false) ? 'selected' : '' }}>{{ __('pos.branch_stock_all_label') }}</option>
                @endif
                @foreach($switchList as $b)
                <option value="{{ $b->id }}" {{ (int) ($activeBranchId ?? 0) === (int) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="px-3 py-2 rounded-xl bg-purple-600 text-white text-xs font-semibold">{{ __('pos.filter_btn') }}</button></noscript>
        </form>
        @endif
    </div>
</div>
@endif
