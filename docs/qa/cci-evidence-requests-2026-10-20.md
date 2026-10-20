# Actionable offline evidence requests

Reproduction: the existing audit reports only aggregate missing counts, so an
owner cannot locate the missing evidence cells without rescanning the workbook.
Adds bounded row/field request locators from the already matched source and
evidence rows. Customer and invoice identifiers remain excluded. Existing
amounts, partition classification, aggregate counts and permanent audit HOLD
semantics are unchanged; no business/fiscal operations are added.

Regression checks cover exact missing field locators, no private identifiers,
filled sales evidence removing only its request, stable totals and unchanged
HOLD. Existing duplicate/changed/missing-row tests remain. PHP unavailable
locally; targeted/full isolated CI required. Real owner source/evidence files
were not accessed, and current pilot readiness is not claimed.

CI exposed a release-path gap: admin/bell support DI targeting, but the Elaan
CLI/parser still rejected audience di. Extend the same strict allowlist with
di (unknown audiences still fail); test exact DI parsing and dry-run without
widening to POS. The CCI release announcement remains DI-only.
