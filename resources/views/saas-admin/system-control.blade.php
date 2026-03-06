<x-admin-layout>
<div class="p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">System Control Panel</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500 mb-5">Emergency switches to control platform-wide features. Changes take effect immediately.</p>

        <div class="space-y-4">
            @foreach($controls as $control)
            <div class="flex items-center justify-between p-4 rounded-lg border {{ $control->value === 'enabled' ? 'border-emerald-800/50 bg-emerald-900/10' : 'border-red-800/50 bg-red-900/10' }}">
                <div>
                    <p class="text-sm font-semibold text-white">{{ ucwords(str_replace('_', ' ', $control->key)) }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $control->description }}</p>
                </div>
                <form method="POST" action="{{ route('saas.admin.system.toggle', $control->key) }}">
                    @csrf
                    <button class="px-4 py-2 rounded-lg text-sm font-medium transition {{ $control->value === 'enabled' ? 'bg-red-600/20 text-red-400 hover:bg-red-600/40 border border-red-700/50' : 'bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/40 border border-emerald-700/50' }}">
                        {{ $control->value === 'enabled' ? 'Disable' : 'Enable' }}
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </div>
</div>
</x-admin-layout>
