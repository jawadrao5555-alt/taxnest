---
name: Laravel 419 CSRF renderable gotcha
description: Why a renderable typed on TokenMismatchException never fires; catch HttpException 419 instead.
---

# 419 "Page Expired" after logout — renderable never fires

A raw "Page Expired" 419 page shows on any stale/invalid CSRF token (double-logout,
browser Back after logout, page left open past session lifetime) even when a
`renderable(function (TokenMismatchException $e, ...))` handler exists.

**Why:** Laravel's `Illuminate\Foundation\Exceptions\Handler::render()` calls
`prepareException()` (converts `TokenMismatchException` → `new HttpException(419, msg, $prev)`)
BEFORE `renderViaCallbacks()`. So by the time render callbacks run, the exception is a
generic `Symfony\...\HttpException`, NOT `TokenMismatchException` — a callback type-hinted
on `TokenMismatchException` can never match. (Contrast: `NotFoundHttpException`/
`MethodNotAllowedHttpException` callbacks DO work because those are already HttpException
subclasses and are not converted.)

**How to apply:** Register the renderable typed on `HttpException` and gate on
`$e->getStatusCode() !== 419 → return null` (null-passthrough lets earlier 404/405
callbacks and other statuses fall through untouched — verified `renderViaCallbacks`
continues on null). Then do the per-panel graceful redirect (admin/pos/fbr-pos/default →
`back()` if that guard still authed, else the panel's `/login` with a "Session expired"
flash). Nothing else in this app throws 419, so the status gate is exact. Reproduce with a
temp admin: POST /admin/logout with `_token=WRONG` → must be 302, not a "Page Expired" body.
