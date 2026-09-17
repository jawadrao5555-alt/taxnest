# Browser acceptance evidence union

**Status:** current fixture coverage complete by a scope-aware evidence union, not
by one all-green browser invocation.

| Measure | Result |
|---|---:|
| Current named journeys | 61 |
| Desktop/mobile journey contexts | 122 |
| Canonical route checks per viewport | 64 |
| Canonical route/viewport checks | 128 |
| Current category journeys | 48 |
| Missing current journey contexts | 0 |
| Accepted passing harness outcomes | 252 |
| Unique passing harness messages | 250 |

The union is deliberately provenance-scoped:

- `23bb5252` supplies 48 unchanged journey contexts at both widths.
- `1510be74` supplies the nine remediated POS/category contexts and six health
  isolation assertions.
- `0e73072f` supplies both service-workflow create/transition/invoice-link
  results.
- `58d0c164` supplies both fiscal real-control/pointer-trial results.
- `982826bb` supplies the final four DI role/viewport contexts after visual
  normal-flow and geometry checks.

Invalid fixture, server-unavailable, and superseded partial runs are recorded as
excluded in the companion JSON. Raw logs and screenshots remain under ignored
`.local/recertification/browser-*` and `.local/browser-evidence` paths; the
JSON contains their SHA-256 hashes and sanitized paths.