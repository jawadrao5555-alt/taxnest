# Healthcare pilot — rollout, training and go-live acceptance

**Task:** 1555 — Prepare Healthcare ERP pilot rollout
**Product line:** Nest ERPS (healthcare)
**Companion:** `docs/ops/healthcare-pilot-runbook.md` (deploy, queue, backup, recovery)

This document is the *people* half of the pilot. The runbook keeps the server alive; this one
decides whether the hospital is actually running on the system and how we will know.

---

## 1. Configuring the first hospital

Order matters — each step is the input to the next. Everything on this list is importable through
**Settings → Setup import** (owner-only), which previews every sheet before it writes anything.

| # | What | Sheet | Depends on |
|---|---|---|---|
| 1 | Branches and departments | `departments` | — |
| 2 | Service rates / charge heads | `services` | departments |
| 3 | Doctors, with share terms | `doctors` | departments |
| 4 | Staff logins and roles | `staff` | branches |
| 5 | Medicine catalogue | `medicines` | — |
| 6 | Opening pharmacy stock | `opening_stock` | medicines |
| 7 | Supplier opening balances | `suppliers` | — |
| 8 | Opening account balances | `opening_accounts` | chart of accounts seeded |
| 9 | Existing patient files (optional) | `patients` | branches, and each row must carry its existing file number |

Sheets whose module is switched off are not offered at all. Only `departments`, `services` and
`patients` are always available; the rest follow the modules the hospital bought.

Rules the importer enforces, stated here because the hospital's data person needs to know them
before they start filling sheets:

- **The downloaded template is empty below the header row.** Type your first record in row 2.
  Examples and the rule for each column are on the `guide` sheet, which is never imported. There is
  no "delete the sample row" step to forget.
- **Preview is not a dry run of a different file.** Commit re-parses and re-validates the same
  uploaded workbook, and it refuses outright unless that workbook's preview was actually rendered —
  the middle press is not skippable from the URL bar. If the stored file changes after the preview,
  the commit sends you back to look again, so "the commit does what the preview showed" stays true.
- **Every sheet is idempotent on a natural key.** Re-uploading a corrected file updates rows
  instead of duplicating them. This is what makes "fix the three bad rows and upload again" safe.
  The keys are: department code (or name), service code (or name), doctor name plus department,
  staff email, patient file number, medicine code (or name plus strength), medicine batch plus
  expiry plus branch, supplier name, account code.
- **A patient row must carry its existing file number.** The import refuses a blank one rather than
  allocating it, because a file number allocated on the way in gives that row no stable identity —
  a second upload would register the same person again. Patients without an old number are
  registered at the desk, where a number is allocated once.
- **Opening stock posts as an adjustment-in, not a purchase.** It does not create a supplier bill
  and does not touch anyone's payable.
- **An opening count restates the shelf; it never adds to it.** A lot is matched on medicine,
  batch, expiry and branch. Recount 480 as 500, upload again, and the shelf holds 500 — not 980.
  This is the whole point of being able to correct a count, and it works downwards too.
- **Opening account balances restate.** Importing them twice does not double the trial balance; the
  earlier opening entry is reversed first.
- **The staff sheet has no password column.** Every login's first password is generated on the
  server, hashed immediately, and shown to the owner once at the end of the commit. Nothing
  readable ever reaches the uploaded file — which matters, because the hospital emails that file
  around. Copy the passwords when they are shown, or reset them later from the team screen.
- **Sheet headers stay English.** They are a machine contract; the screen around them is translated.

### Acceptance for this stage

The hospital's own accountant signs off that the imported opening trial balance, opening stock
value and supplier balances match their existing books **to the rupee**. Not "close enough" — a
pilot that starts from a wrong opening balance produces a month of unexplainable variances.

---

## 2. Role-specific onboarding

Each role gets a short session on **their own screens only**. Nobody is trained on the whole system;
that is how staff end up avoiding it.

| Role | Session (≈) | Must be able to do it unaided afterwards |
|---|---|---|
| Reception / registration | 45 min | Register a patient, find an existing one without creating a duplicate, book and check in, take an OPD fee, reprint a receipt |
| Doctor | 30 min | See their queue, record a consultation, write a structured prescription, complete a visit |
| Nurse / ward | 45 min | Vitals and nursing notes, admission paperwork, bed state, request a discharge |
| Pharmacy counter | 60 min | Dispense against a prescription, sell over the counter, take payment, handle a partial fill, print a label |
| Pharmacy store | 45 min | Receive a purchase, batch and expiry, adjust stock with a reason, read the stock report |
| Cashier / billing | 60 min | Build a consolidated bill from charges, finalize, take payment, part-payment, refund, day-close |
| Accounts | 60 min | Read the day's journals, reconcile cash, doctor settlements, financial reports |
| HR | 30 min | Roster, punches and corrections, attendance report, payroll export |
| Owner | 60 min | Modules, team and permissions, setup import, audit run, everything above at a glance |

Two rules for the sessions themselves:

1. **They practise on their own hospital's data**, configured in stage 1 — not a demo hospital.
   Training on someone else's catalogue teaches nothing about their own.
2. **Every session ends with the trainee doing the whole journey once, alone, while we watch and say
   nothing.** That is the only part that tells us whether the training worked.

Leave behind: a one-page card per role (the five things they do daily, and who to call).

---

## 3. Parallel run

**Length:** two full weeks, including at least one month-end day if the timing allows.
**Rule:** the hospital keeps doing whatever it does today, *and* enters everything into the system.
Nothing is switched off until the reconciliation below passes.

Reconcile **daily**, at day-close, and record each day's result:

| Reconciliation | Passes when |
|---|---|
| OPD count and fee collection | System day-close total == counter's cash count, exactly |
| Pharmacy sales and stock | Value dispensed == stock movement; physical spot-count of 10 items matches |
| Inpatient charges | Bed-days + procedures + consumables on the discharge bill == what the ward recorded |
| Doctor shares | Accrued == the hospital's own calculation on the same bills |
| Bank/cash | Payments recorded == deposits |
| Attendance | Worked days == the supervisor's own register |
| Audit run | No unexplained findings carried into the next day |

A mismatch is not closed by adjusting the system to agree with the manual figure. It is closed by
finding **which one is right and why**, because that answer is the whole point of a parallel run.

Daily standup during the parallel run: 15 minutes, same time each morning, three questions — what
broke yesterday, what did not reconcile, what is blocking anyone today.

---

## 4. Feedback and issues

One route, so nothing is lost in a WhatsApp thread:

- Staff report to **one named person at the hospital** (their pilot coordinator).
- The coordinator logs it once, with: who, which screen, what they expected, what happened, and
  whether they could carry on.
- We triage the same day into: **blocker** (a hospital function cannot be performed), **defect**
  (wrong behaviour with a workaround), **change** (works as designed, they want it different).
- **Blockers are fixed inside the pilot. Changes are collected and decided after go-live.** A pilot
  that absorbs every "can you also…" never ends, and shipping half-considered changes into a live
  hospital is how a pilot becomes an outage.

Everything is written down, including the ones we decline and why.

---

## 5. Go-live acceptance criteria

Go-live means the parallel run stops and the manual system is retired. It requires **all** of the
following, in writing, signed by the hospital:

1. **Five consecutive days** where every reconciliation in section 3 passes with no unexplained
   variance.
2. **Zero open blockers.** Every defect is either fixed or has a written, accepted workaround the
   affected staff have been shown.
3. Every role has **at least two trained people** who can work unaided. One trained receptionist is
   a single point of failure that a holiday will find.
4. Each of these has been printed on the hospital's own printers and accepted by the person who
   hands it to a patient: OPD receipt, prescription, pharmacy label, patient statement, discharge
   summary, day-close report, financial summary.
5. `health:pilot-readiness --company=<id>` reports **no FAIL**, and every WARN has a named owner.
6. A backup was **taken and restored** within the last seven days, and the restored copy was checked.
7. The runbook has been **exercised**, not just read: someone other than the author has restarted
   the queue, found a failed job, and located an error in the log.
8. Owner sign-off that opening balances, stock and doctor terms are correct as of the cut-over date.

### Cut-over day

- Take a backup immediately before.
- Freeze setup imports for the day; no configuration changes while the first live day runs.
- Someone from our side is reachable for the full working day, and both sides know the number.
- Day one closes with a full reconciliation before anyone goes home.

### What would make us stop

Any of these pauses the pilot and returns to parallel running: patient records visible to the wrong
hospital or the wrong role, money that does not reconcile two days running, a stock figure that
cannot be explained, or a clinical record that was lost rather than merely wrong.
