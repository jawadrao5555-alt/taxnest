# Admin package compatibility — local regression evidence

Scope: prevent assigning or reactivating a package belonging to another product or an unregistered ERP vertical. Existing same-product annual pricing, history, extra-branch pricing and overrides are not migrated.

## Failure and change

Both admin assignment routes previously accepted a different product's package and replaced the current subscription. A company-locked compatibility check now runs before replacement; requested-package approval and historical reactivation apply the same rule. Null/empty legacy Digital Invoice types and the `health`/`erps` alias remain supported. Unknown product/vertical values fail closed.

The Assign Subscription form first asks for a company, lists matching packages with product and annual base price, and clears the selected package when the company changes. Base price is labelled explicitly because existing paid branch add-ons are calculated by the assignment service.

## Local evidence

- Reversed only implementation changes: both wrong-product route regressions failed (2 failures, 4 assertions); restored implementation: both passed (2 tests, 17 assertions).
- Focused compatibility, admin actions/billing, PRA/FBR annual pricing, signup and consultant tests: **116 tests, 555 assertions passed**, PHP 8.4.27, SQLite in-memory.
- Two older minimal pricing fixtures now identify their actual POS product. They previously relied on omitted/null product discriminators; application compatibility rules were not weakened to satisfy them.
- PHP syntax and `git diff --check` passed.

Browser/MariaDB and full-suite results are recorded in the PR once complete. No live company, payment, subscription or production configuration was changed by this implementation work. No merge or deployment was performed.
