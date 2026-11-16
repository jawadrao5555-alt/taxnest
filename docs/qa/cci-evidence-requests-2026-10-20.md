# Actionable offline evidence requests

Reproduction: the existing audit reports only aggregate missing counts, so an
owner cannot locate the missing evidence cells without rescanning the workbook.
Adds bounded row/field request locators from the already matched source and
evidence rows. Customer and invoice identifiers remain excluded. Existing
amounts, partition classification, aggregate counts and permanent audit HOLD
semantics are unchanged; no business/fiscal operations are added.

Regression checks cover exact missing field locators, no private identifiers,
filled sales evidence removing only its request, stable totals and unchanged
HOLD. Existing duplicate/changed/missing-row tests remain. Hosted full CI remains required for the integrated commit. Real owner source/evidence files
were not accessed, and current pilot readiness is not claimed.

Integration note (15 November 2026): the offline audit/service tests are integrated from source #228. Six isolated unit tests pass, including blank-row locator stability. No original owner workbook was accessed, no import was performed and HOLD remains permanent. Release-announcement/parser changes are not part of this evidence-tooling integration.
