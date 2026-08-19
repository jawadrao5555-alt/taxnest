<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Invoice Push API — Integration Docs</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Ye page apne software vendor (DMS / ERP) ko dein — integration ke liye support se raabta zaroori nahi.</p>
                </div>
                <a href="/company/api-access" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Manage API Key</a>
            </div>

            {{-- Auth --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">1. Authentication</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">Har request JSON hoti hai aur API key <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">Authorization</code> header mein jati hai:</p>
                <pre class="p-4 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono overflow-x-auto">Authorization: Bearer dik_XXXX_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
Content-Type: application/json
Accept: application/json</pre>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">Base URL: <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">{{ url('/api/di/v1') }}</code></p>
            </div>

            {{-- Create endpoint --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">2. Create invoice — <span class="font-mono text-base">POST /invoices</span></h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                    <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">{{ url('/api/di/v1/invoices') }}</code>
                </p>
                <ul class="space-y-1.5 text-sm text-gray-600 dark:text-gray-300 list-disc list-inside mb-4">
                    <li><code class="font-mono text-xs">client_reference</code> — <strong>required</strong>: aap ke apne system ka unique invoice ID. Same reference dobara bhejne par duplicate <strong>kabhi nahi</strong> banta; original invoice ka result wapas milta hai (<code class="font-mono text-xs">"duplicate": true</code>).</li>
                    <li><code class="font-mono text-xs">mode</code> — <code class="font-mono text-xs">"submit"</code> (default: create + FBR par submit) ya <code class="font-mono text-xs">"draft"</code> (sirf draft banaye, FBR par nahi bheje).</li>
                    <li><code class="font-mono text-xs">document_type</code> — <code class="font-mono text-xs">"Sale Invoice"</code>, <code class="font-mono text-xs">"Credit Note"</code> ya <code class="font-mono text-xs">"Debit Note"</code> (CN/DN ke liye <code class="font-mono text-xs">reference_invoice_number</code> required).</li>
                    <li>Har item mein <code class="font-mono text-xs">hs_code</code>, <code class="font-mono text-xs">description</code>, <code class="font-mono text-xs">quantity</code>, <code class="font-mono text-xs">price</code> (per-unit, excluding tax) aur <code class="font-mono text-xs">tax</code> (total sales tax amount) required hain. Non-standard rate ke liye <code class="font-mono text-xs">schedule_type</code>, <code class="font-mono text-xs">tax_rate</code>, <code class="font-mono text-xs">sro_schedule_no</code>, <code class="font-mono text-xs">serial_no</code> bhi bhejein; 3rd Schedule par <code class="font-mono text-xs">mrp</code> required hai.</li>
                </ul>

                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Sample request</p>
                <pre class="p-4 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono overflow-x-auto mb-4">curl -X POST "{{ url('/api/di/v1/invoices') }}" \
  -H "Authorization: Bearer dik_..." \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
  "client_reference": "DMS-INV-2026-001542",
  "mode": "submit",
  "document_type": "Sale Invoice",
  "buyer_name": "Al Karam Traders",
  "buyer_ntn": "1234567",
  "buyer_address": "Main GT Road, Lahore",
  "destination_province": "Punjab",
  "items": [
    {
      "hs_code": "1905.9000",
      "description": "Biscuits Family Pack",
      "quantity": 100,
      "price": 250,
      "tax": 4500,
      "schedule_type": "standard"
    }
  ]
}'</pre>

                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Sample response — 201 Created</p>
                <pre class="p-4 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono overflow-x-auto mb-4">{
  "status": "ok",
  "duplicate": false,
  "invoice": {
    "id": 8123,
    "client_reference": "DMS-INV-2026-001542",
    "invoice_number": "1234567DI00871",
    "document_type": "Sale Invoice",
    "invoice_date": "2026-08-19",
    "buyer_name": "Al Karam Traders",
    "total_amount": 29500.0,
    "total_sales_tax": 4500.0,
    "items_count": 1,
    "invoice_status": "submitted",
    "fbr_status": "production",
    "fbr_invoice_number": "1234567DI00871-100001",
    "fbr_submission_date": "2026-08-19T14:05:11+05:00",
    "source": "api"
  }
}</pre>
                <p class="text-sm text-gray-500 dark:text-gray-400">FBR reject kare to invoice <code class="font-mono text-xs">"invoice_status": "failed"</code> ke saath create ho jata hai aur response mein <code class="font-mono text-xs">fbr_errors</code> array hota hai — payload theek kar ke <strong>naye</strong> <code class="font-mono text-xs">client_reference</code> se dobara bhejein, ya panel se retry karein.</p>
            </div>

            {{-- Status endpoint --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">3. Poll status — <span class="font-mono text-base">GET /invoices/status</span></h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">Query parameter: <code class="font-mono text-xs">client_reference</code> <em>ya</em> <code class="font-mono text-xs">invoice_number</code> (internal ya FBR number dono chalte hain).</p>
                <pre class="p-4 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono overflow-x-auto">curl "{{ url('/api/di/v1/invoices/status') }}?client_reference=DMS-INV-2026-001542" \
  -H "Authorization: Bearer dik_..." \
  -H "Accept: application/json"</pre>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">Response ka format create wala hi hai. Failed invoice par <code class="font-mono text-xs">fbr_errors</code> bhi shamil hota hai.</p>
            </div>

            {{-- Errors --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">4. Error codes</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4 font-medium">HTTP</th>
                                <th class="py-2 pr-4 font-medium">error</th>
                                <th class="py-2 font-medium">Matlab</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700 dark:text-gray-200">
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">401</td><td class="py-2 pr-4 font-mono text-xs">missing_api_key</td><td class="py-2">Authorization header nahi bheja.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">401</td><td class="py-2 pr-4 font-mono text-xs">invalid_api_key</td><td class="py-2">Key ghalat hai ya revoke/regenerate ho chuka hai.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">403</td><td class="py-2 pr-4 font-mono text-xs">company_suspended / company_pending_approval / company_rejected</td><td class="py-2">Company account is haalat mein API use nahi kar sakta.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">422</td><td class="py-2 pr-4 font-mono text-xs">validation_failed</td><td class="py-2">Payload ghalat — <code class="font-mono text-xs">errors</code> object mein field-level tafseel (e.g. <code class="font-mono text-xs">items.0.hs_code</code>).</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">404</td><td class="py-2 pr-4 font-mono text-xs">not_found</td><td class="py-2">Status lookup — koi invoice match nahi hua.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">429</td><td class="py-2 pr-4 font-mono text-xs">quota_exceeded</td><td class="py-2">Monthly plan invoice quota khatam — plan upgrade karein.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">429</td><td class="py-2 pr-4 font-mono text-xs">(throttle)</td><td class="py-2">Rate limit: 60 create/min, 120 requests/min total.</td></tr>
                            <tr><td class="py-2 pr-4 font-mono text-xs">500</td><td class="py-2 pr-4 font-mono text-xs">server_error</td><td class="py-2">Server issue — same <code class="font-mono text-xs">client_reference</code> se retry karein (duplicate nahi banega).</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 mt-4 mb-2">Sample 422 response</p>
                <pre class="p-4 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono overflow-x-auto">{
  "status": "error",
  "error": "validation_failed",
  "message": "The invoice payload failed validation.",
  "errors": {
    "buyer_address": ["The buyer address field is required."],
    "items.0.hs_code": ["The items.0.hs_code field is required."]
  }
}</pre>
            </div>

            {{-- Best practices --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">5. Integration tips</h3>
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300 list-disc list-inside">
                    <li>Timeout ya network error par <strong>same</strong> <code class="font-mono text-xs">client_reference</code> se retry karein — idempotency guarantee hai ke duplicate invoice nahi banega aur quota dobara nahi katega.</li>
                    <li>Pehle <code class="font-mono text-xs">mode: "draft"</code> se integration test karein; drafts FBR par nahi jate aur panel se nazar aa jate hain.</li>
                    <li>Buyer registered ho to <code class="font-mono text-xs">buyer_ntn</code> bhejein, warna <code class="font-mono text-xs">buyer_cnic</code> — registration type khud detect ho jata hai.</li>
                    <li>API se aaye invoices panel ki invoice list mein <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 text-xs font-semibold">API</span> badge ke saath dikhte hain.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
