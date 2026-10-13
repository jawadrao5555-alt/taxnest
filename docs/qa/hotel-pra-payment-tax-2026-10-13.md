# Hotel cash/digital tax and PRA payment identity

Base: deployed main a9cf9d9f415f087a6737dc5d7b4db1b1b8d56652.

## Reproduction and correction

Hotel UI offers `card`; hotel billing stores that method unchanged. The shared
PRA payload map omitted `card`, falling back to cash mode 1 despite using the
digital tax rate. Add the alias as mode 2 for all tenants, retaining debit,
credit, QR, cash and mixed mappings.

Tests also reproduced a hotel card-saving rounding mismatch: the stored tax was
689.66, but the payload reported 689.31 on a 10,000 menu-priced stay. Existing
whole-rupee payload rounding changed the tax. For linked hotel folio invoices
and hotel credit notes, reconcile a bounded (at most one rupee) tax residual to
the saved tax snapshot by transferring it between line sale value and tax.
Line and header payable totals stay identical. Ordinary POS rounding retains
its established behaviour. Folio linkage is company-scoped. Large or unsafe
differences are not silently rewritten.

## Compatibility and scope

- Existing global defaults produce cash 16% and digital 8%; the July migration
  already updates old global digital defaults to 8%.
- Keep company overrides, including explicit zero rates, intact. No historical
  invoices, company settings or global rules are migrated or renumbered.
- Test all five supported methods across exclusive, inclusive and card-saving
  pricing, including the authenticated checkout quote, persisted invoice and
  generated PRA payload. Later settings changes preserve issued snapshots.
- Existing hotel settings already display the effective cash/card rates.
- A company saved with cash 8% or digital 5% still requires an owner settings
  review; this PR does not imply every live tenant is configured at 16%/8%.
- No new mobile-wallet identifier or split-payment tax policy is introduced.

## Local evidence

PHP 8.4.26; disposable SQLite in-memory databases; every application test used
`scripts/rc-safe-run`, which removes external credentials and blocks fiscal
network traffic. No regulator invoice was submitted.

Before correction: the 15-case new regression had six failures: three Card
payment-mode failures and three additional digital card-saving tax mismatches.

After correction:

```text
HotelSimpleDeskPricingTest + PosPraExemptZeroRatedTest +
PosPraReturnFlowTest + HotelCreditNotePolicyTest:
104 tests, 914 assertions, zero failures/errors.

Final current-rate + company-isolation regression:
16 tests, 269 assertions, zero failures/errors.

Final partial/full hotel credit-note rounding regression:
1 test, 17 assertions, zero failures/errors.
```

PHP syntax checks and git diff whitespace checks passed. No Blade/JS/printer
or database schema changes. Browser and native MariaDB verification are not
claimed locally; the PR's existing real browser, native MariaDB and full-suite
CI gates must finish green on the current SHA before owner approval. Deployment
and live verification remain owner-relay responsibilities.
