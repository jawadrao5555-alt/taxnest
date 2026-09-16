<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 min-w-0" data-service-workflow="{{ $profile['category'] }}">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div><h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $profile['noun'] }} Board</h1><p class="text-sm text-gray-500 mt-1">{{ ucfirst(str_replace('_', ' ', $profile['category'])) }} workflow · category-native stages</p></div>
        <div class="grid grid-cols-1 sm:flex gap-2 w-full sm:w-auto"><a href="{{ route('pos.service-work-orders.report') }}" class="px-4 py-2 rounded-lg border text-sm text-center dark:border-gray-700 dark:text-gray-200">Export CSV</a>@if($canCreate)<a href="{{ route('pos.service-work-orders.create') }}" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm text-center font-bold">New {{ $profile['noun'] }}</a>@endif</div>
    </div>
    @if(session('success'))<div class="mb-4 p-3 rounded-lg bg-emerald-50 text-emerald-800 text-sm">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm">{{ session('error') }}</div>@endif
    <div class="flex gap-2 overflow-x-auto pb-2 mb-5">
        <a href="{{ route('pos.service-work-orders.index') }}" class="shrink-0 px-3 py-2 rounded-lg border text-xs font-semibold dark:border-gray-700 dark:text-gray-200">All · {{ $orders->total() }}</a>
        @foreach($profile['stages'] as $stage)<a href="{{ route('pos.service-work-orders.index', ['status' => $stage]) }}" class="shrink-0 px-3 py-2 rounded-lg border text-xs font-semibold {{ request('status') === $stage ? 'bg-purple-600 text-white border-purple-600' : 'dark:border-gray-700 dark:text-gray-200' }}">{{ ucwords(str_replace('_', ' ', $stage)) }} · {{ (int)($counts[$stage] ?? 0) }}</a>@endforeach
    </div>
    <form method="GET" class="mb-5 flex flex-col sm:flex-row gap-2">
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <label class="sr-only" for="service-work-order-search">Search {{ strtolower($profile['noun']) }}</label>
        <input id="service-work-order-search" name="q" value="{{ request('q') }}" maxlength="255" placeholder="Search number, customer or work" class="w-full min-w-0 rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-white">
        <button class="w-full sm:w-auto shrink-0 px-4 py-2 rounded-lg border font-semibold text-sm dark:border-gray-700 dark:text-gray-200">Search</button>
    </form>
    <div class="bg-white dark:bg-gray-900 rounded-xl border dark:border-gray-800 overflow-hidden shadow-sm">
        <div class="overflow-x-auto"><table class="min-w-full divide-y dark:divide-gray-800"><thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-4 py-3 text-left text-xs">Number</th><th class="px-4 py-3 text-left text-xs">Customer / Work</th><th class="px-4 py-3 text-left text-xs">Schedule</th><th class="px-4 py-3 text-left text-xs">Status</th><th class="px-4 py-3 text-right text-xs">Amount</th></tr></thead><tbody class="divide-y dark:divide-gray-800">
        @forelse($orders as $order)<tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50"><td class="px-4 py-3"><a class="font-bold text-purple-600" href="{{ route('pos.service-work-orders.show', $order) }}">{{ $order->job_number }}</a></td><td class="px-4 py-3 text-sm dark:text-gray-200"><div class="font-semibold">{{ $order->customer_name }}</div><div class="text-xs text-gray-500">{{ $order->title }}</div></td><td class="px-4 py-3 text-xs text-gray-500">{{ $order->scheduled_at?->format('d M Y H:i') ?? 'Walk-in' }}</td><td class="px-4 py-3"><span class="px-2 py-1 rounded-full bg-purple-100 text-purple-800 text-xs font-bold">{{ ucwords(str_replace('_', ' ', $order->status)) }}</span></td><td class="px-4 py-3 text-right font-semibold dark:text-white">Rs {{ number_format((float)$order->total_amount, 2) }}</td></tr>
        @empty<tr><td colspan="5" class="px-4 py-12 text-center text-gray-500">No {{ strtolower($profile['noun']) }} records yet.</td></tr>@endforelse
        </tbody></table></div>
        @if($orders->hasPages())<div class="p-4 border-t dark:border-gray-800">{{ $orders->links() }}</div>@endif
    </div>
</div>
</x-pos-layout>
