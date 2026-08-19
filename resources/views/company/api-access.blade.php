<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">API Access — Invoice Push API</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Apne DMS / ERP software se seedha TaxNest mein invoice bhejein — TaxNest FBR Digital Invoicing par submit kar ke FBR invoice number wapas deta hai.</p>
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
                    <h3 class="text-base font-semibold text-amber-800 dark:text-amber-200 mb-1">Naya API key — sirf ABHI dikhaya jayega</h3>
                    <p class="text-sm text-amber-700 dark:text-amber-300 mb-3">Isay copy kar ke apne software / vendor ko dein. Page chhorne ke baad ye dobara nahi dikhega — sirf regenerate ho sakta hai.</p>
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
                        <form method="POST" action="/company/api-access/generate" onsubmit="return confirm('Regenerate karne se PURANA key foran band ho jayega. Jo software purana key use kar raha hai wo invoice nahi bhej sakega jab tak naya key na dala jaye. Continue?');">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-teal-700 hover:bg-teal-800 text-white">Regenerate Key</button>
                        </form>
                        <form method="POST" action="/company/api-access/revoke" onsubmit="return confirm('Revoke karne se API access mukammal band ho jayega. Continue?');">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-red-50 hover:bg-red-100 text-red-700 border border-red-200">Revoke Key</button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Abhi koi API key nahi hai. Key generate karein aur apne third-party software (SAMS, Intellibiz, custom DMS waghera) mein dalein — wo seedha TaxNest par invoice push kar sakega.</p>
                    <form method="POST" action="/company/api-access/generate">
                        @csrf
                        <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold bg-teal-700 hover:bg-teal-800 text-white">Generate API Key</button>
                    </form>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Kaise kaam karta hai</h3>
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300 list-disc list-inside">
                    <li>Key sirf <strong>aik dafa</strong> pura dikhta hai — server par sirf iska secure hash mehfooz hota hai.</li>
                    <li>Har request ke saath header bhejein: <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">Authorization: Bearer dik_...</code></li>
                    <li>Same <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">client_reference</code> dobara bhejne par duplicate invoice <strong>kabhi nahi</strong> banta — original result wapas milta hai.</li>
                    <li>API se banaye gaye final invoices aap ke monthly plan quota mein waise hi ginte hain jaise panel ke invoices.</li>
                    <li>Invoice list mein API se aaye invoices par <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 text-xs font-semibold">API</span> ka nishaan hota hai.</li>
                </ul>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">Endpoints, sample request/response aur error codes ke liye <a href="/company/api-docs" class="text-teal-700 dark:text-teal-400 font-medium hover:underline">Integration Docs</a> dekhein.</p>
            </div>
        </div>
    </div>
</x-app-layout>
