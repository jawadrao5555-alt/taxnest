# Pizza Master counter-printer binding — live run record (19 Aug 2026)

Operational task: no application-code change was required. Per-counter receipt,
proof, KOT-copy, and void routing was already implemented; Pizza Master's live
device/user settings had not yet been bound to that routing model.

## Reconfirmation before the change

Live company `23` (`PIZZA  MASTER`) was read immediately before writing:

- Both registered Desktop Agent devices were online.
- Device/card `1` was the only device reporting Windows printer queue `92`; its
  saved per-device receipt printer was already `92`.
- Device/card `2` did not report queue `92`.
- Cashiers Imran and Qamar had no device assignment.
- Company printer settings were:
  - receipt printer: `92`
  - main kitchen KOT printer: `p1`
  - counter KOT copy: enabled on `92`
  - counter KOT owning device: unset
- Recent failed `92` proof jobs were created by Imran, had no device stamp, and
  failed with `Invalid deviceName provided`. Interleaved jobs sometimes succeeded
  when device/card `1` won the unpinned claim race.

## Live change

The write ran in one database transaction with rollback assertions:

1. Kept device/card `1`'s receipt printer at `92`.
2. Assigned Imran to device/card `1`.
3. Set only `counter_kot_printer_device` in the existing printer-settings JSON
   to device/card `1`.

The transaction asserted that:

- every other printer-settings key was byte-for-byte unchanged;
- `p1` and its unpinned owner setting were unchanged;
- Qamar remained unassigned;
- device/card `1` still reported `92` and was online.

The first guarded attempt rolled back cleanly because `users.pos_device_uid` is
not mass assignable. The successful transaction used an explicit model attribute
save, matching the existing Printer Settings controller behavior.

## Controlled verification

An existing Pizza Master order was sent through
`PosController::apiCreatePrintJob()` as an Imran proof-bill reprint. This path
does not create or modify a sale, tax record, restaurant order, or KOT history.

Live print job `1485` recorded:

```text
type: proof
target_printer: 92
device stamp: device/card 1
created by: Imran
attempts: 1
status: done
error: null
```

The job was therefore visible only to device/card `1`, was claimed once, and the
Desktop Agent reported a successful print. The supplied shop video identifies
this counter as the PC beside the physical Epson printer.

Fresh audit at `2026-08-19T22:21:36+05:00`:

- device/card `1` online and still reporting `92`;
- device/card `2` still not reporting `92`;
- Imran assignment and counter-KOT owner both resolved to device/card `1`;
- no `Invalid deviceName provided` failures on or after controlled job `1485`;
- main kitchen setting still `p1`;
- latest `p1` KOT job was `done` with no error.

## Regression check

```text
phpunit tests/Feature/PosKotDeviceRoutingTest.php
OK (19 tests, 73 assertions)
```

Rollback baseline, if the physical mapping is ever disproved: clear Imran's
device assignment and `counter_kot_printer_device`; leave receipt queue `92`,
main kitchen queue `p1`, all jobs, sales, bills, agent credentials, and kitchen
history untouched.