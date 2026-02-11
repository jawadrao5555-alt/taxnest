<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Welcome to TaxNest</h2>
            <form method="POST" action="/onboarding/skip">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-700 underline">Skip Setup</button>
            </form>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="text-center mb-8">
                <h3 class="text-2xl font-bold text-gray-900">Let's set up your account</h3>
                <p class="text-gray-500 mt-2">Complete these steps to start using TaxNest for your business.</p>
            </div>

            <div class="flex items-center justify-between mb-10 px-8">
                @php
                    $steps = [
                        ['num' => 1, 'label' => 'Add Branch', 'key' => 'branch'],
                        ['num' => 2, 'label' => 'FBR Token', 'key' => 'fbr_token'],
                        ['num' => 3, 'label' => 'First Product', 'key' => 'product'],
                        ['num' => 4, 'label' => 'First Invoice', 'key' => 'invoice'],
                    ];
                @endphp
                @foreach($steps as $i => $step)
                    <div class="flex flex-col items-center {{ $i < count($steps) - 1 ? 'flex-1' : '' }}">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold
                            {{ $progress[$step['key']] ? 'bg-emerald-500 text-white' : ($currentStep == $step['num'] ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500') }}">
                            @if($progress[$step['key']])
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                            @else
                                {{ $step['num'] }}
                            @endif
                        </div>
                        <span class="text-xs font-medium mt-2 {{ $progress[$step['key']] ? 'text-emerald-600' : ($currentStep == $step['num'] ? 'text-blue-600' : 'text-gray-400') }}">{{ $step['label'] }}</span>
                    </div>
                    @if($i < count($steps) - 1)
                    <div class="flex-1 h-0.5 mx-2 mt-[-20px] {{ $progress[$step['key']] ? 'bg-emerald-400' : 'bg-gray-200' }}"></div>
                    @endif
                @endforeach
            </div>

            <div class="space-y-4">
                <a href="/branches/create" class="block bg-white rounded-xl shadow-sm border {{ $progress['branch'] ? 'border-emerald-200 bg-emerald-50' : ($currentStep == 1 ? 'border-blue-300 ring-2 ring-blue-100' : 'border-gray-200') }} p-6 hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="p-3 rounded-lg {{ $progress['branch'] ? 'bg-emerald-100' : 'bg-blue-50' }}">
                                <svg class="w-6 h-6 {{ $progress['branch'] ? 'text-emerald-600' : 'text-blue-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900">Step 1: Add Your First Branch</h4>
                                <p class="text-sm text-gray-500">Set up your head office or primary branch location.</p>
                            </div>
                        </div>
                        @if($progress['branch'])
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Done</span>
                        @else
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        @endif
                    </div>
                </a>

                <a href="/company/fbr-settings" class="block bg-white rounded-xl shadow-sm border {{ $progress['fbr_token'] ? 'border-emerald-200 bg-emerald-50' : ($currentStep == 2 ? 'border-blue-300 ring-2 ring-blue-100' : 'border-gray-200') }} p-6 hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="p-3 rounded-lg {{ $progress['fbr_token'] ? 'bg-emerald-100' : 'bg-blue-50' }}">
                                <svg class="w-6 h-6 {{ $progress['fbr_token'] ? 'text-emerald-600' : 'text-blue-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900">Step 2: Configure FBR Token</h4>
                                <p class="text-sm text-gray-500">Connect your FBR/PRAL API credentials for invoice submissions.</p>
                            </div>
                        </div>
                        @if($progress['fbr_token'])
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Done</span>
                        @else
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        @endif
                    </div>
                </a>

                <a href="/products/create" class="block bg-white rounded-xl shadow-sm border {{ $progress['product'] ? 'border-emerald-200 bg-emerald-50' : ($currentStep == 3 ? 'border-blue-300 ring-2 ring-blue-100' : 'border-gray-200') }} p-6 hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="p-3 rounded-lg {{ $progress['product'] ? 'bg-emerald-100' : 'bg-blue-50' }}">
                                <svg class="w-6 h-6 {{ $progress['product'] ? 'text-emerald-600' : 'text-blue-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900">Step 3: Add Your First Product</h4>
                                <p class="text-sm text-gray-500">Define a product with HS code and tax schedule for invoicing.</p>
                            </div>
                        </div>
                        @if($progress['product'])
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Done</span>
                        @else
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        @endif
                    </div>
                </a>

                <a href="/invoice/create" class="block bg-white rounded-xl shadow-sm border {{ $progress['invoice'] ? 'border-emerald-200 bg-emerald-50' : ($currentStep == 4 ? 'border-blue-300 ring-2 ring-blue-100' : 'border-gray-200') }} p-6 hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div class="p-3 rounded-lg {{ $progress['invoice'] ? 'bg-emerald-100' : 'bg-blue-50' }}">
                                <svg class="w-6 h-6 {{ $progress['invoice'] ? 'text-emerald-600' : 'text-blue-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900">Step 4: Create First Invoice</h4>
                                <p class="text-sm text-gray-500">Create your first tax invoice and test the system.</p>
                            </div>
                        </div>
                        @if($progress['invoice'])
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">Done</span>
                        @else
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        @endif
                    </div>
                </a>
            </div>

            @if($allDone)
            <div class="mt-8 text-center">
                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-6 mb-4">
                    <svg class="w-12 h-12 text-emerald-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <h3 class="text-lg font-bold text-emerald-800">All Steps Complete!</h3>
                    <p class="text-sm text-emerald-600 mt-1">You're ready to start using TaxNest.</p>
                </div>
                <form method="POST" action="/onboarding/complete">
                    @csrf
                    <button type="submit" class="px-8 py-3 bg-emerald-600 text-white rounded-xl font-bold text-lg hover:bg-emerald-700 transition shadow-lg">
                        Go to Dashboard
                    </button>
                </form>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
