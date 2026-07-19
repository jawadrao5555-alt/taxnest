---
name: POS product Excel import/export
description: Why POS bulk import/export must be real .xlsx (not CSV) and the tolerant-parsing rules; apply the same pattern to any future import surface (e.g. customers import is still CSV-only).
---

# POS product Excel import/export

Rule: shopkeeper-facing bulk import/export must be a real .xlsx round-trip, never CSV.

**Why:** Real customer failure (pizza shop, Jul 2026): Excel saves edits as .xlsx (CSV-only upload rejected it), Excel mangles long barcodes into scientific notation (8.9E+12) on CSV round-trip, "Rs 1,200"-style prices fail is_numeric, and blank-template sample rows became real products.

**How to apply:**
- Export/template via PhpSpreadsheet (already a composer dep, present on live vendor): SKU/Barcode columns = TEXT format AND `setCellValueExplicit(...TYPE_STRING)` — format alone doesn't stop Excel converting on open.
- Import accepts xlsx/xls/csv/txt; CSV keeps delimiter auto-detect (comma/semicolon/tab — Excel regional settings).
- Tolerant parsing: header aliases; price cleaner strips Rs/PKR/commas/%; code cleaner restores float + scientific-notation barcodes to digit strings via `sprintf('%.0f')`; empty code → null (never overwrite with blank).
- Sample rows in blank template are skipped on import only on EXACT name+price+sku match (see IMPORT_SAMPLE_ROWS).
- Match precedence barcode → sku → name against a preloaded per-company map (no per-row queries); update maps after create so in-file duplicates update.
- Excel row-cap must be enforced via `getHighestDataRow()` BEFORE `toArray()` — a 5MB xlsx can hold 100k+ rows and OOM shared cPanel PHP (OOM is fatal, try/catch can't save you).
- Known tradeoff (architect-accepted): row matched by barcode/sku also updates NAME — copy-paste-row-forgot-to-change-sku silently renames instead of creating.

POS customers import (`importCustomers`) is still old-style CSV-only — give it this same treatment if the owner asks.
