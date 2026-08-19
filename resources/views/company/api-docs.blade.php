<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Invoice Push API — Integration Docs</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Share this page with your software vendor (DMS / ERP) — no support contact is needed for the integration.</p>
                </div>
                <a href="/company/api-access" class="flex-shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Manage API Key</a>
            </div>

            {{-- Auth --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">1. Authentication</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">Every request is JSON, with the API key in the <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-mono text-xs">Authorization</code> header:</p>
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
                    <li><code class="font-mono text-xs">client_reference</code> — <strong>required</strong>: your own system's unique invoice ID. Re-sending the same reference <strong>never</strong> creates a duplicate; the original invoice's result is returned (<code class="font-mono text-xs">"duplicate": true</code>).</li>
                    <li><code class="font-mono text-xs">mode</code> — <code class="font-mono text-xs">"submit"</code> (default: create + submit to FBR) or <code class="font-mono text-xs">"draft"</code> (create a draft only, without sending to FBR).</li>
                    <li><code class="font-mono text-xs">document_type</code> — <code class="font-mono text-xs">"Sale Invoice"</code>, <code class="font-mono text-xs">"Credit Note"</code> or <code class="font-mono text-xs">"Debit Note"</code> (CN/DN require <code class="font-mono text-xs">reference_invoice_number</code>).</li>
                    <li>Each item requires <code class="font-mono text-xs">hs_code</code>, <code class="font-mono text-xs">description</code>, <code class="font-mono text-xs">quantity</code>, <code class="font-mono text-xs">price</code> (per-unit, excluding tax) and <code class="font-mono text-xs">tax</code> (total sales tax amount). For non-standard rates also send <code class="font-mono text-xs">schedule_type</code>, <code class="font-mono text-xs">tax_rate</code>, <code class="font-mono text-xs">sro_schedule_no</code>, <code class="font-mono text-xs">serial_no</code>; 3rd Schedule items also require <code class="font-mono text-xs">mrp</code>.</li>
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
                <p class="text-sm text-gray-500 dark:text-gray-400">If FBR rejects the invoice it is still created with <code class="font-mono text-xs">"invoice_status": "failed"</code> and the response includes an <code class="font-mono text-xs">fbr_errors</code> array — fix the payload and resend with a <strong>new</strong> <code class="font-mono text-xs">client_reference</code>, or retry from the panel.</p>
            </div>

            {{-- Status endpoint --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">3. Poll status — <span class="font-mono text-base">GET /invoices/status</span></h3>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">Query parameter: <code class="font-mono text-xs">client_reference</code> <em>or</em> <code class="font-mono text-xs">invoice_number</code> (internal or FBR number, both work).</p>
                <pre class="p-4 rounded-lg bg-gray-900 text-gray-100 text-xs font-mono overflow-x-auto">curl "{{ url('/api/di/v1/invoices/status') }}?client_reference=DMS-INV-2026-001542" \
  -H "Authorization: Bearer dik_..." \
  -H "Accept: application/json"</pre>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">The response format matches the create endpoint. Failed invoices also include <code class="font-mono text-xs">fbr_errors</code>.</p>
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
                                <th class="py-2 font-medium">Meaning</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700 dark:text-gray-200">
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">401</td><td class="py-2 pr-4 font-mono text-xs">missing_api_key</td><td class="py-2">Authorization header was not sent.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">401</td><td class="py-2 pr-4 font-mono text-xs">invalid_api_key</td><td class="py-2">Key is wrong, or was revoked/regenerated.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">403</td><td class="py-2 pr-4 font-mono text-xs">company_suspended / company_pending_approval / company_rejected</td><td class="py-2">The company account cannot use the API in its current state.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">422</td><td class="py-2 pr-4 font-mono text-xs">validation_failed</td><td class="py-2">Invalid payload — the <code class="font-mono text-xs">errors</code> object carries field-level details (e.g. <code class="font-mono text-xs">items.0.hs_code</code>).</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">404</td><td class="py-2 pr-4 font-mono text-xs">not_found</td><td class="py-2">Status lookup — no matching invoice found.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">429</td><td class="py-2 pr-4 font-mono text-xs">quota_exceeded</td><td class="py-2">Monthly plan invoice quota exhausted — upgrade the plan.</td></tr>
                            <tr class="border-b border-gray-100 dark:border-gray-800"><td class="py-2 pr-4 font-mono text-xs">429</td><td class="py-2 pr-4 font-mono text-xs">(throttle)</td><td class="py-2">Rate limit: 60 create/min, 120 requests/min total.</td></tr>
                            <tr><td class="py-2 pr-4 font-mono text-xs">500</td><td class="py-2 pr-4 font-mono text-xs">server_error</td><td class="py-2">Server issue — retry with the same <code class="font-mono text-xs">client_reference</code> (no duplicate will be created).</td></tr>
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
                    <li>On a timeout or network error, retry with the <strong>same</strong> <code class="font-mono text-xs">client_reference</code> — idempotency guarantees no duplicate invoice and no double quota deduction.</li>
                    <li>Test the integration with <code class="font-mono text-xs">mode: "draft"</code> first; drafts are never sent to FBR and remain visible in the panel.</li>
                    <li>Send <code class="font-mono text-xs">buyer_ntn</code> for registered buyers, otherwise <code class="font-mono text-xs">buyer_cnic</code> — the registration type is detected automatically.</li>
                    <li>Invoices arriving via the API show an <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 text-xs font-semibold">API</span> badge in the panel's invoice list.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
