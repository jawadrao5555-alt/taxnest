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
return [
    'longpoll_max_holds' => (int) env('PRINT_LONGPOLL_MAX_HOLDS', 3),
    'longpoll_max_wait' => (int) env('PRINT_LONGPOLL_MAX_WAIT', 8),
];
