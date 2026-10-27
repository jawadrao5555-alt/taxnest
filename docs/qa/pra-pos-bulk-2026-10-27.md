# Owner-requested PRA POS bulk consolidation — 27 October 2026

The owner explicitly requested one bulk PR for direct pending PRA POS implementation, with original PRs optionally kept open. This overrides the normal one-issue-per-PR preference for this consolidation only. This is implementation, not the requirements-only tracker #301.

Base: `f6040daee73b5d375fdf4fd72ec0b48767b1a175` (owner-deployed #302). Source branches are unchanged. All 14 source heads are retained as parents/ancestors of the candidate. Original PRs stay open until the bulk candidate has independent acceptance; do not deploy them separately from this candidate without rechecking overlap.

| Source | Pinned source head | Scope |
|---|---|---|
| #244 | `2f7ff99f508035cabc994e6cf025c123aad64ac4` | Reprint business dates |
| #245 | `492f46ad3ab859e68df2dedcfb252d140b54aba4` | Reprint Cash/Card and order filters |
| #248 | `29bbc5251ff0d493fb7c880c5745c3d3edc5176a` | Caller history header clearance |
| #249 | `669676979e4941ff7d2192418208348340e34a1c` | Receipt Card Discount copy |
| #252 | `5bed637dae721da3ec1695099fd18a220694c1d9` | Attached customer contact correction |
| #253 | `848d74968ef4ed1935936b62012cfc7900a20a79` | Reports business-day defaults |
| #254 | `b7a557ae0b6cd172767b3c4526cd3245ec12fb34` | Cart wheel containment |
| #255 | `0dad50753cd59eba44df4653be6ec4301d66f76d` | Selected rider cash settlement |
| #257 | `afc6f8eb4c981dbb9c5118bce6838280c045f7d6` | Visible Table Setup controls |
| #258 | `04e736b83616a20ab38f8f7c3dff25a997992779` | Complete menu-order report PDF |
| #259 | `75136fc5f0cef20a0cc6c1cedb7f28c5a8b646eb` | Opt-in receipt typography |
| #260 | `ef0f6563b9366da0c367015df08911894e487d03` | Recalled additions confirmation |
| #288 | `252d4d0c2a9055083381ceea99c262fb2c1ae780` | Recent Bills preview before explicit Print |
| #289 | `561cbbf12024672d4a9410ca39939c53122a3c81` | Exempt product filter (replacement #243) |

Excluded: Agent #234/#250/#251/#256, HOLD #237, Admin #294 and planning/design contracts #278–#281/#301. No nine-style design implementation is claimed.

## Combined integration

- Reprint keeps date request ordering plus search/order/payment filters and historical empty-state wording.
- Caller panel placement, unsaved caller Save and saved customer Edit coexist.
- Preserve deployed mixed-fulfillment delivery guards and address snapshots when adding recalled-order confirmation.
- The production cart fingerprint gained tax/discount fields on current main. Correct the inherited #260 skip-kitchen index from 9 to 11; otherwise every normal item with a default discount type could bypass confirmation. Decline/approval, changed quantities, tax/discount fields, skipped fees and unchanged split rows remain tested.
- Browser dispatch and fixture journeys are a union, preserving existing table, delivery, report and rider acceptance. Owner settings tests use separate same-company owner sessions; cashier access stays unchanged.
- All source Node tests remain mandatory; actual table/receipt component browser tests are additionally wired into the installed-browser lane using the trusted shared browser launcher.
- Single ordinary PRA announcement replaces source announcements. No migration or automatic company configuration rewrite. Receipt fonts/geometry apply only after explicit Save; stale forms preserve stored settings. Existing receipt totals, fiscal state and historical snapshots must stay unchanged.
- Urdu confirmation text uses Urdu words rather than adding Latin words to the language-purity allowlist.

## Evidence and remaining gates

Local PHP 8.4.27, Node 24.22.1 and actual pinned Chromium headless shell 151.0.7922.34:

- Targeted production-method/component tests: 31/31 pass after fingerprint correction.
- Relevant PHP selection: 132 tests / 1,097 assertions pass (28 existing PHPUnit deprecations).
- Language purity: 7 tests / 22 assertions pass after correcting the inherited Urdu text.
- Vite build passes. JS parser, PHP fixture parser, YAML parser and whitespace checks pass.
- Full 5,589-test local PHP run was stopped after more than 2,300 tests to hand over to the fresh exact-head CI run. Its early inherited language failure was fixed and the language lane rerun. No full local pass is claimed.
- Actual full-app browser acceptance is being investigated: initial table settings toggle/restore journey timed out after first real persisted POST. Added operational-surface waiting and failure evidence; final desktop and 390px acceptance is still required. A subsequent fresh local TCP lab reset also failed in the unchanged consultant migration; no migration gate was bypassed. Local MariaDB 10.11 TCP is supplemental because this host refuses Unix sockets. Committed CI retains exact MariaDB 10.6.23, isolated Unix socket guards and all fixtures.
- Local workflow safety checks passed up to the MariaDB discovery harness; that harness refuses this host's remapped temporary root. No allowlist/gate was loosened. CI remains authoritative.

Deploy readiness requires this bulk PR's own current-head seven mandatory lanes plus validate (8/8), fresh clean mergeability and review. Historical source greens are not evidence for this combined tree. No approval, merge, relay dispatch, production access or physical print was performed. No background monitoring promise.
