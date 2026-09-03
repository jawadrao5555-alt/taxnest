<?php

/*
 * Silent-print long-poll tuning (agent v1.6.2+, ZFC instant-print request).
 *
 * Each HELD long-poll deliberately occupies one PHP worker (sleeping, ~zero
 * CPU) for up to `longpoll_max_wait` seconds. On the shared cPanel host the
 * FPM pool size is not introspectable, so the admission cap defaults very
 * conservatively: at most 3 workers may be held at once — comfortably below
 * even the smallest typical cPanel FPM pools (pm.max_children >= 5), always
 * leaving headroom for normal POS traffic. Agents refused a hold degrade
 * gracefully to a 1.5s short-poll (still >= the pre-long-poll behavior).
 *
 * Deployment-tunable via .env (read here, so config:cache-safe):
 *   PRINT_LONGPOLL_MAX_HOLDS  — raise only if the host's FPM pool is known
 *                               to be larger (keep well below pm.max_children)
 *   PRINT_LONGPOLL_MAX_WAIT   — max seconds a poll may be held (0 disables
 *                               holding entirely -> pure short-poll fallback)
 */
/*
 * Aug 2026 revision — "server bohat slow" incident.
 *
 * The live shared cPanel host was observed running a pool of only FOUR lsphp
 * workers. With the old cap of 3, round-the-clock agent polling could sleep on
 * three quarters of it, so a counter's own page request queued behind sleeping
 * pollers. That wait happens before PHP boots, so nothing showed up in the
 * slow-request log even though the shop felt the site crawl.
 *
 * Two guards now apply, and both must stay in place on small shared hosting:
 *   - at most ONE worker may ever be held, leaving the rest of the pool free;
 *   - holds are only offered while a shop is actually printing (see
 *     `active_window_minutes`), so a closed shop holds nothing at all.
 * Raise `longpoll_max_holds` only on a host with a known, larger pool.
 */
return [
    'longpoll_max_holds' => (int) env('PRINT_LONGPOLL_MAX_HOLDS', 1),
    'longpoll_max_wait' => (int) env('PRINT_LONGPOLL_MAX_WAIT', 8),

    // How long after the last real print job a shop still counts as "printing"
    // and may be offered a held poll.
    'active_window_minutes' => (int) env('PRINT_ACTIVE_WINDOW_MINUTES', 20),

    /*
     * Optional local realtime wake relay. Leave either value blank to keep the
     * established polling-only behaviour. This is deliberately a loopback URL:
     * it is a local companion gateway, never a customer-controlled webhook.
     */
    'realtime_gateway_url' => env('PRINT_REALTIME_GATEWAY_URL'),
    'realtime_gateway_secret' => env('PRINT_REALTIME_GATEWAY_SECRET'),
];
