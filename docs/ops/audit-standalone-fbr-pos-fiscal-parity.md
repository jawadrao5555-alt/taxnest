# Standalone FBR POS fiscal acknowledgement parity

The PRA IMS callback in PosImsReportingService accepts the narrowly observed production number-only reply when it matches the claimed POSID and response shape. Standalone AgentController::fbrSubmitResult requires explicit Code 100. The standalone Agent branch can report the number-only reply as an unknown outcome. Keep this difference visible until the standalone transport and callback are both updated.

## Implementation boundaries

1. Reuse the guarded response classification from #354. Require an authenticated, active standalone claim with the matching tenant, bill, device and original POSID. Bind number-only acceptance to exact observed payload shape, transport evidence and verified production device environment. Keep old Agent explicit Code 100 acceptance compatible.
2. Bind queued bills to their original requested environment. A company configuration switch or device rebinding cannot move queued work across environments. Preserve ambiguous legacy rows for owner reconciliation. Separate requested environment, local device evidence and central evidence.
3. Keep timeout, socket reset, malformed response, missing or mismatched claim, malformed or foreign fiscal number and uncertain outcomes on verification hold. Preserve original bill and USIN. Never create a second fiscal dispatch from a hold. Owner recovery uses the existing audited reconciliation flow.
4. Acceptance covers REST and supported SOAP, explicit Code 100, exact number-only evidence, legacy callbacks, concurrent claims, stale callbacks, duplicate callback, sandbox to production switch, reporting off, wrong tenant or terminal, frozen sale and return RefUSIN, and recovery after an uncertain response. Mock device and regulator for CI. Real-device and central verification require separate authorized sandbox/shop evidence.
5. Related checkout work displays durable status and total, preserves offline and local fallbacks and refreshes number and QR after acceptance. Treat local acceptance and central confirmation as separate outcomes.
