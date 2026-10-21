# Dashboard notification reading — owner video review

Source: owner video `6f08d460-037d-4ebf-bd68-fac2d7ddf2fd.mp4` shows a notification closing while being read. The restaurant dashboard unconditionally reloads after its 30-second HEAD probe; opening a notification does not stop that reload. This is a code-confirmed cause, not a claim that every possible dismissal has been diagnosed.

Compatibility matrix:
- Open featured notice/history detail/bell: skip automatic refresh.
- Notice opened during a pending HEAD response: do not reload.
- All overlays dismissed or hidden: existing periodic refresh resumes.
- Failed reachability/concurrent timer: retain current no-reload/no-duplicate behavior.
- Read acknowledgements, notification audience, tenant selection and stored settings are unchanged.

Local `node --test scripts/tests/dashboard-notice-reading.test.mjs`: 4/4 pass against the actual production methods, including the in-flight race. `node --check scripts/rc-browser-acceptance.mjs` and `git diff --check` pass.

The existing real Chrome restaurant journey now opens the actual bell and notification detail on the dashboard, invokes the actual refresh handler, asserts the notice survives, and captures desktop/mobile screenshots. That acceptance is pending GitHub CI; local PHP 8.4/application/browser sockets are unavailable in this host. Do not call the customer issue independently live-verified. No production or customer data was accessed.
