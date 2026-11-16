# Source #321: greetings and tutorial accessibility

Implementation only, not activation or delivery authority. No real recipients, messages or provider calls were used for acceptance.

## Greetings
- Global `GREETING_DELIVERY_ENABLED` defaults OFF; company preference defaults OFF.
- Web company owners explicitly enter recipients and event-specific email consent. Existing contacts and patient dates are never imported or treated as consent.
- Email only, through the existing Laravel mail configuration. No new credentials or implicit WhatsApp/SMS integration.
- Allowed placeholders: `{name}`, `{company}`. Preview uses fictional values and cannot send.
- Company timezone controls event date. Welcome occurs only on its specified date; February 29 annual events occur February 28 in non-leap years.
- Unique company/recipient/event/channel/occurrence prevents duplicate dispatch. Template changes do not reset it.
- Durable `sending` claim is committed before transport. Crash or uncertain delivery cannot become automatically retryable. Unknown transport outcome becomes HOLD. A stopped `sending` row requires reconciliation, never blind resend.
- Consent and company preference are locked and rechecked at dispatch. Revoked/missing consent cancels queued delivery. Missed occurrences expire rather than sending late greetings.
- Provider unavailable checks are limited to three per occurrence. Actual send exceptions are not blindly retried. Audit lists date, event, state, reason and attempts without duplicating contact or message content.
- The owner preferences page is under Profile → Optional customer greetings and consent. Other guards/products are not implicitly exposed.

## Tutorials
- Search filters the already-authorized current tutorial set; it never widens public/product/role/feature permissions.
- Accessible video title, optional local VTT captions, language, escaped transcript, reviewed date and version metadata.
- Release-admin metadata edits preserve existing publication and audience controls. Superseded rows remain stored but are excluded from current discovery.
- No captions or transcripts were invented. Existing videos without supplied caption files explicitly state captions pending.

## Remaining proof
Hosted desktop/390px keyboard and six-theme checks, native concurrent dispatch proof and actual supplied/verified caption content remain pending. Fake-provider unit/feature acceptance does not demonstrate real delivery, screen-reader certification or a complete #321 implementation. Activation, live messaging and deployment require separate owner decisions.

## Added hosted acceptance (pending execution)
- `rc_greetings_concurrency.php` uses only the guarded disposable MariaDB socket and fake transport. Two processes race the same occurrence; one synthetic send is required. A second race simulates delivery then response loss and requires one HOLD row and no repeat send.
- `opt-in-greetings-tutorials` browser journey runs desktop and 390px with synthetic owner/recipient/video-caption fixtures. Preferences remain OFF throughout. It checks error focus, preview `sent=false`, consent persistence/revocation, keyboard tutorial search/transcript, captions/version metadata and saves sanitized screenshots.
- Healthcare settings includes the owner-only package review link when the authenticated health checkout route is available.
- These are test implementations, not recorded passing browser/native outcomes until hosted CI runs. No six-theme or physical screen-reader completion is asserted.
