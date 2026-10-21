# Caller history below the actual header

Owner video 6ce1c2f3-e855-4d5e-9e78-36d580b1eccd.mov shows the Caller ID strip/history hidden behind the expanded toolbar, preventing access to previous calls. Existing caller panels use fixed top-20 under the header's z-index 140, inside a sale root with zoom/overflow clipping.

Teleport only the caller widgets to the viewport, retain their Alpine scope/handlers, measure actual header clearance, and bound height/width with scrolling. Resize recomputes placement. Keep caller widgets hidden while payment/receipt/PIN/submission is blocking, preserve existing ring queue/deferral, and do not change call, save-customer or print behavior. All incoming/history/dock/manual-dial fallback variants share placement.

Production placement method regressions: 2/2 pass (normal/expanded header, short viewport, missing-header legacy fallback, dock width). Syntax/whitespace/workflow contract pass. Real Chrome sale journey renders previous-call history with the sale root zoomed, checks escape from clipping/header/viewport geometry, captures desktop/390px evidence, clicks its Bill action and checks attached identity. Supported local PHP/app/browser unavailable; that actual visual acceptance is pending CI. Code explains a concrete clipping mechanism but independent live/root-cause reproduction on the owner's Windows screen is not claimed.
