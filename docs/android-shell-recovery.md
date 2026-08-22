# Android WebView shells — blank-screen recovery (shared contract)

**Applies to:** `pos-app`, `fbr-pos-app`, `di-app`, `waiter-app` — the four thin
WebView shells around `taxnest.com.pk`. They are deliberate clones of one
another: `pos-app/app/src/main/java/pk/taxnest/pos/MainActivity.kt` is the
template, the other three differ only in package, start URL, user-agent tag,
icon, brand colour and (POS/FBR only) the push hooks.

**Not covered:** the Caller ID app (fully native, no WebView) and the Rider app.

---

## The incident this exists for

A shop filmed the Waiter app opening to a completely blank white screen — only
the teal status bar. Reload, Back and reopening changed nothing; the app was
bricked for that shop until it was uninstalled.

What the evidence said:

- The white was the app's own `android:windowBackground` (`#FFFFFF`). The web
  page never painted a single pixel. **Not a broken page — a page that never
  rendered.**
- The live server was healthy. The phone's request is in the live access log:
  `/pos/waiter` → 302 → `/pos/login` → 200 with a full HTML body. Seconds later
  the same phone was polling happily from the Caller app, so its internet was
  fine.
- The phone requested **no** stylesheets, scripts, icons or fonts for that page
  — not even the service worker's background revalidation. The document reached
  the network layer and was never parsed (empty/truncated body, a renderer that
  died, or a navigation that never committed).
- The shell had no way to notice: it only reacted to hard network errors
  (`onReceivedError`), never checked that a page finished or contained anything,
  never retried on resume, and Back just sent the app to the background so
  reopening restored the same dead screen.

The one-off cause cannot be guaranteed never to repeat. **The app must never sit
on a dead screen with no way out.**

---

## The contract every shell must keep

| # | Behaviour | Where |
|---|-----------|-------|
| 1 | **Branded boot screen.** The root view is a `FrameLayout`: WebView underneath, a brand-coloured overlay (app icon + spinner + `boot_loading`) on top. Hidden only by `onPageCommitVisible` — real pixels — with `onPageFinished` as the fallback for non-http pages. `android:windowBackground` is the brand colour, never white. | `buildBootScreen()`, `hideBootScreen()`, `res/values/themes.xml` |
| 2 | **Paint watchdog.** Every main-frame load arms a timer (`PAINT_TIMEOUT_MS`, 12 s). If nothing has painted **and** the boot screen is still covering the window, the load is dead → recovery card. A slow navigation on top of a page that already works is left alone. | `armWatchdog()`, `beginLoad()` |
| 3 | **Empty-document probe.** `onPageFinished` is not proof of content. `EMPTY_PROBE_DELAY_MS` later the document is asked for its body text length and element count; zero of both (or no `<body>`) = failure. An unreadable answer counts as content — never fail on a guess. A probe that never answers (wedged renderer) fails after `PROBE_ANSWER_MS`. | `verifyDocument()` |
| 3b | **Paint AND content, or it is not healthy.** `markLoadHealthy()` is the only place `loadSucceeded` is set, and it needs both proofs. Content without paint (a renderer that runs JS but shows nothing — the incident) leaves the watchdog armed, so it still ends on the card. | `markLoadHealthy()` |
| 3c | **Every callback is scoped to the live document.** `isLiveDoc(view, url)` gates commit, finish, network error and HTTP error: a callback from a WebView that has been replaced, or for a URL a newer navigation superseded, touches nothing. Without it a late commit for the *previous* page of a redirect or login POST would hide the boot screen and disarm the watchdog for the navigation that replaced it — re-creating the blank screen. | `isLiveDoc()` |
| 4 | **HTTP errors and renderer death.** A main-frame `5xx` goes straight to the recovery card (its body is a useless "Server Error" page). `4xx` is remembered for the reason line but shown as-is — a 403/404/419 is a real page a shop should read. `onRenderProcessGone` rebuilds the WebView and reloads (returning `false` would kill the process and leave a blank window); after `MAX_RENDERER_REBUILDS` it shows the card instead of looping. | `onReceivedHttpError()`, `onRenderProcessGone()`, `rebuildWebView()` |
| 5 | **Recovery card** (replaced the old offline-only page). Brand-coloured page with: **Dobara Koshish / Try Again** → reloads `START_URL`; **App Reset** → clears WebView cache, history, form data and `WebStorage`, then unregisters every service worker and deletes every Cache Storage bucket on the next first-party page before reloading; and a one-line **technical reason** (`HTTP 503`, `NET -2`, `EMPTY PAGE`, `TIMEOUT 12s`, `RENDERER CRASH`, `NO RESPONSE`) plus the app version and path, so a shop can read it out or photograph it. **Cookies are deliberately NOT cleared** — the poison is never the session, and a waiter who gets logged out is a new support call. | `showRecovery()`, `RecoveryBridge`, `purgeSiteStorage()` |
| 6 | **Retry on resume.** `loadSucceeded` tracks whether the current document ever painted *and* proved non-empty. On resume (never the first one) with no success and nothing in flight, the shell reloads `START_URL` by itself — so closing and reopening the app genuinely fixes it. | `onResume()` |

The recovery card is loaded with `loadDataWithBaseURL(null, …)` and its
`TNApp` JavaScript bridge is dropped by the card's own buttons **and** on every
first-party `onPageStarted` — the bridge must never be reachable from a page on
the site, where any script could call the destructive `reset()`.

A WebView rebuild first resolves whatever the dying view still owns — an
unanswered file-picker callback and the fullscreen video overlay — otherwise a
renderer death during an upload leaves the picker permanently wedged.

After process death, `restoreState()` brings back the history list but **not**
the display data, so the shell must start the restored URL as a normal load.
Restoring and merely arming the watchdog would card a perfectly good session
twelve seconds later.

## Rules for a new clone or a change

- Copy `MainActivity.kt` from `pos-app` and change **only** the documented
  per-app deltas. Everything in the table above stays byte-identical.
- The string keys `boot_loading`, `recover_title`, `recover_body`,
  `recover_retry`, `recover_reset`, `recover_reason_label` must exist in every
  shell's `res/values/strings.xml` (waiter's default locale is Urdu and it also
  carries `values-en/`).
- Never let `android:windowBackground` go back to white. It is what a shop
  stares at while the WebView has painted nothing.
- The technical reason line stays **untranslated** — it is a support code, not
  a message.
- Existing native behaviour is untouched by all of this and must stay that way:
  downloads with the session cookie (and the agent-installer exception),
  `target=_blank` popup routing, file uploads, fullscreen video, Back = web
  history, rotation via `configChanges`, and `Push.onNavigation` in POS/FBR.

## Building and shipping

See `pos-app/RELEASE.md` (POS/FBR, includes the Firebase prerequisite —
`app/google-services.json` is required for a **release** build) and
`rider-app/RELEASE.md` (toolchain and the shared `rider` signing key). DI and
Waiter have no Firebase dependency and build straight away.

Before hosting any APK:

```bash
bash scripts/apk-release-check.sh --expect-version <ver> --expect-code <code> <apk>
```
