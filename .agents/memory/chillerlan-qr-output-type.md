---
name: chillerlan php-qrcode v5 output selection (silent SVG fallback)
description: chillerlan/php-qrcode v5 picks the renderer by the outputType STRING, not outputInterface; the wrong key fails silently to SVG with no exception.
---

# Rule
With `chillerlan/php-qrcode` v5, choose the renderer via `QROptions['outputType']` set to a string constant from `QROutputInterface` (e.g. `GDIMAGE_PNG` = `'png'`, `MARKUP_SVG` = `'svg'`). Do NOT use `'outputInterface' => QRGdImagePNG::class` to switch formats — `outputInterface` is consulted ONLY when `outputType === QROutputInterface::CUSTOM`, otherwise it is ignored.

**Why:** Passing `outputInterface` (the FQCN) while leaving `outputType` at its default produces a valid base64 data URI that is silently an **SVG**, with NO exception thrown — so a try/catch fallback never trips and you ship SVG thinking it's PNG. Burned ~3 attempts diagnosing "PNG path threw" when nothing threw at all. PNG matters here because the QR is embedded in DomPDF A4 invoices and thermal receipts, where DomPDF's SVG support is shaky; PNG renders reliably.

**How to apply:** `app/Support/QrImage.php` uses `'outputType' => QROutputInterface::GDIMAGE_PNG` + `'outputBase64' => true` (PNG data URI), falling back to `MARKUP_SVG` only on a real Throwable. `gd` is installed (PNG works); `imagick` is NOT, so simple-qrcode / bacon PNG backends are unavailable — chillerlan GD is the only server-side PNG path. Verify output by asserting the data URI starts with `data:image/png;base64,iVBORw0KGg` (PNG signature), not `data:image/svg+xml`.
