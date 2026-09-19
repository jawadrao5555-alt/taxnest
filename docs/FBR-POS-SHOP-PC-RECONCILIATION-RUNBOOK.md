# FBR POS shop-PC reconciliation runbook

Use this runbook when TaxNest has a local FBR IMS Code 100 and an invoice number, but central FBR/Tax Asaan synchronization is not independently known.

## Safety boundary

- Do not edit invoice numbers or statuses.
- Do not retry, resubmit, cancel, or backfill historical invoices.
- Do not switch silently between direct cloud and fiscal-device modes.
- Never copy tokens, access codes, NTN/CNIC, customer data, complete payloads, URLs, IP addresses, or unmasked invoice numbers into evidence.
- Record only masked invoice numbers, timestamps, result codes, version/environment labels, queue state, and sanitized errors.

## Shop-PC checks

1. **Confirm FBRIMS environment**
   - Open the FBRIMS configuration on the shop PC.
   - Confirm it is explicitly set to **Production**.
   - If it says Test/Sandbox, stop. Correct the installation/configuration with the authorized FBR representative before any new sale is sent.

2. **Confirm shop identity**
   - Confirm the registered POS ID and branch shown inside FBRIMS belong to the intended shop.
   - Do not paste the full POS ID into tickets or chat. Record only “matched” or “mismatched”.

3. **Verify local FBRIMS activation**
   - Confirm the FBRIMS license/activation and local credentials are valid.
   - If FBRIMS reports an activation or credential error, capture only its code and a sanitized message.

4. **Inspect the central upload/sync queue**
   - Open the FBRIMS upload/synchronization queue.
   - Record counts for pending, uploaded/confirmed, and rejected items.
   - Inspect rejected items for an FBR-provided code and sanitized reason.
   - A local Code 100 alone is not central confirmation.

5. **Trace one affected invoice**
   - Search one affected invoice in local FBRIMS logs using a masked reference in the returned evidence.
   - Record local acceptance time, central queue state, central reference/status if present, and any sanitized rejection code.
   - Do not trigger resend or retry.

6. **Restore Desktop Agent heartbeat**
   - Confirm the TaxNest Desktop Agent service/app is running.
   - Confirm the configured company is the intended shop.
   - Restore internet/service availability and wait for the admin diagnostic to show a fresh heartbeat.
   - Do not regenerate the API key unless separately authorized and required.

7. **Check direct credentials without fallback**
   - If TaxNest shows `900901`, treat direct Production credentials as rejected.
   - Do not enable or use direct fallback until the credentials are corrected and deliberately revalidated.

## Sanitized evidence to return

- Check time and timezone
- Agent version and last heartbeat
- FBRIMS component version, if the software exposes it
- Requested environment and client-reported environment
- POS/branch match: yes/no only
- Local Code and invoice-number field name
- Central queue state/reference/status only when genuinely shown
- One masked invoice number
- Sanitized error codes/messages

End with one of:

- `Central verification confirmed by explicit FBRIMS central status`
- `Central verification rejected by explicit FBRIMS central status`
- `Central verification unknown; local IMS acceptance only`