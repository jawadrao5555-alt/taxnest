<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">API Access — Invoice Push API</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Push invoices into TaxNest straight from your DMS / ERP software — TaxNest submits them to FBR Digital Invoicing and returns the FBR invoice number.</p>
                </div>
                <a href="/company/api-docs" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    Integration Docs
                </a>
            </div>

            @if(session('success'))
                <div class="mb-4 p-4 rounded-lg text-sm bg-emerald-50 border border-emerald-200 text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 rounded-lg text-sm bg-red-50 border border-red-200 text-red-700">{{ session('error') }}</div>
            @endif

            @if($newKey)
                <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded-xl p-6">
                    <h3 class="text-base font-semibold text-amber-800 dark:text-amber-200 mb-1">New API key — shown only THIS ONCE</h3>
                    <p class="text-sm text-amber-700 dark:text-amber-300 mb-3">Copy it and hand it to your software / vendor. After you leave this page it will never be shown again — it can only be regenerated.</p>
                    <div class="flex items-center gap-2">
                        <code id="new-api-key" class="flex-1 block px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-amber-300 dark:border-amber-700 text-sm font-mono text-gray-800 dark:text-gray-100 break-all select-all">{{ $newKey }}</code>
                        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('new-api-key').textContent.trim()).then(() => { this.textContent = 'Copied!'; setTimeout(() => this.textContent = 'Copy', 2000); })"
                            class="flex-shrink-0 px-4 py-2 rounded-lg text-sm font-medium bg-amber-600 hover:bg-amber-700 text-white">Copy</button>
                    </div>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">API Key</h3>

                @if($company->di_api_key_hash)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 text-sm">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 mb-1">Key</p>
                            <p class="font-mono text-gray-800 dark:text-gray-100">{{ $company->di_api_key_hint ?? '••••' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 mb-1">Generated</p>
                            <p class="text-gray-800 dark:text-gray-100">{{ optional($company->di_api_key_created_at)->format('d M Y, h:i A') ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 mb-1">Last used</p>
                            <p class="text-gray-800 dark:text-gray-100">{{ optional($company->di_api_key_last_used_at)->format('d M Y, h:i A') ?? 'Never' }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <form method="POST" action="/company/api-access/generate" onsubmit="return confirm('Regenerating will immediately disable the OLD key. Software still using the old key will stop pushing invoices until the new key is entered. Continue?');">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-teal-700 hover:bg-teal-800 text-white">Regenerate Key</button>
                        </form>
                        <form method="POST" action="/company/api-access/revoke" onsubmit="return confirm('Revoking will completely disable API access. Continue?');">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-red-50 hover:bg-red-100 text-red-700 border border-red-200">Revoke Key</button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">No API key yet. Generate a key and enter it in your third-party software (SAMS, Intellibiz, custom DMS, etc.) — it will then push invoices straight into TaxNest.</p>
                    <form method="POST" action="/company/api-access/generate">
                        @csrf
                        <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold bg-teal-700 hover:bg-teal-800 text-white">Generate API Key</button>
                    </form>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">How it works</h3>
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300 list-disc list-inside">
                    <li>The key is shown in full <strong>only once</strong> — the server stores only its secure hash.</li>
                    <li>Send this header with every request: <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">Authorization: Bearer dik_...</code></li>
                    <li>Re-sending the same <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">client_reference</code> <strong>never</strong> creates a duplicate invoice — the original result is returned.</li>
                    <li>Final invoices created via the API count towards your monthly plan quota exactly like panel invoices.</li>
                    <li>Invoices that arrive via the API carry an <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 text-xs font-semibold">API</span> badge in the invoice list.</li>
                </ul>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">See the <a href="/company/api-docs" class="text-teal-700 dark:text-teal-400 font-medium hover:underline">Integration Docs</a> for endpoints, sample request/response and error codes.</p>
            </div>
        </div>
    </div>
</x-app-layout>
