# Nine optional customer receipt styles with independent Local/Fiscal selection

Owner-approved contract, 25 October 2026. Specification only: no runtime feature or deploy readiness in this commit.

## Non-negotiable preservation
Every company retains exactly its currently selected receipt format and saved settings until an authorized company user explicitly chooses a new design and Saves. No default rollout, bulk migration, reset or automatic split into new Local/Fiscal preferences. Missing/null new fields inherit the existing renderer and saved settings, not a new template. Preview, tab switching and Cancel must not persist changes. A second tenant is unaffected.
Selecting a style changes presentation only: never amounts, discounts, taxes, tax-inclusive/exclusive semantics, invoice identity, reporting status, historical fiscal payload or QR contents. Preserve existing printer, width, typography overrides, logo, logo-finals-only, menu QR, footer and visibility preferences. In PosController receipt settings, missing checkbox fields already have preservation logic; do not regress it.

## Nine styles
Keep Existing / Current plus eight reviewed visual directions: Classic, Compact, Bold Total, Clean, Large Text, Grid, Minimal Ink, Boxed. All eight support the same applicable data and saved logo/QR options. Clean and Minimal Ink must not silently omit a saved enabled logo.
Local and Fiscal each offer a separate preview and optional style selection. They may use identical or different styles. Existing shared preferences remain the fallback until explicitly overridden. Validate allowlisted IDs server-side using existing settings authorization and company scope.

## Original reference and render rules
The owner supplied today's Pizza Master P2137 receipt as a visual reference. Do not commit customer screenshot, logo asset, fiscal number or actual verification QR as a fixture. Use fictional data in tests/previews. Mockup images are design directions, not exact or verified output.
Preserve applicable company logo/name/address/contact/NTN/POS identity; order/POS invoice/fiscal reference; date, terminal, payment, cashier, waiter/table, customer/address when applicable; complete item/deal names, quantity/rate/amount; legitimate discount once only; tax breakdown, payable total; valid verification QR and saved footer.
All thermal print backgrounds white, text solid black. Outlined boxes/strong text instead of grey fills/inverse bars. Keep configured logo behavior; do not rewrite logo files. Preview selector cards can use UI colors, printed template must stay high contrast. Wrap long fields/names without clipping and retain QR quiet zone/readability. Minimize needless paper whitespace without unsafe cutter feed.
Inclusive tax stays included (reference total3127 with included tax431.31 is not total3558.31). Style does not invent Card Discount/Menu Total lines or alter existing real discount semantics.

## Local versus Fiscal truth
Configured invoice mode alone does not prove reporting. Use authoritative existing invoice state/reference rules. Local bills clearly identify local status and must not fabricate a fiscal number or verification QR. Distinguish any independently saved menu/payment QR from fiscal verification QR; local does not mean hiding unrelated QR options. Pending/failed fiscal submissions must remain truthful. Successful fiscal bills retain actual authority reference/verification QR and applicable fiscal fields regardless of aesthetic selection. Do not relabel an unreported bill as reported to satisfy a design.
Receipt style affects newly rendered previews/receipts according to explicit settings selection; it must never rewrite historical financial data, stored submissions or already generated documents. Audit actual reprint contracts before changing historical rendering behavior.

## Acceptance before readiness
- Reproduce current settings/renderer output before editing; actual rendered preview and generated output for all nine styles, Local/Fiscal independently, at1366/390 and supported paper widths.
- Existing non-default saved designs and typography/logo on/off/finals-only/menu QR/footer/width remain byte-for-byte unchanged in storage on deployment, preview, cancel and unrelated saves.
- Explicit Save persists only requested scoped style; reload/reprint consistent with documented existing contract. Invalid ID and unauthorized/cross-tenant writes rejected.
- Shared old preference fallback, absent new field, two tenants, two authorized users and normal supported older print/Agent paths covered.
- Test local, reported, pending/failed, cash/card, inclusive/exclusive tax, real discount/no discount, long item/deal/customer/address, mixed order and optional field combinations.
- Preview and print derive the same data; QR payload is not changed, placeholder test QR is not live authority proof. No physical printer or production access needed.
- Targeted regressions, actual loopback Chrome evidence, supported output tests and all mandatory fresh exact-head CI including own validate before owner release review. Documentation-only CI is not functional acceptance.

Separate from KOT PR280, admin reports278 and notifications279. No new KOT styles implemented here; no merge/deploy, fiscal submission, customer data mutation or production workflow.
