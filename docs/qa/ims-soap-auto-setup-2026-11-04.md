# Local IMS SOAP and automatic setup

## Reproduction and scope
The supplied WCF metadata exposes `GetFBRInvoiceNumberByModel` at
`/TcpService/IMSHttp`; the old alongside bridge accepts only the JSON route.
This mismatch is reproduced with synthetic SOAP fixtures. Standalone FBR POS
also used JSON transport; this is a compatibility finding, not proof of the
reported company's individual rejection reason. No live fiscal calls made.

## Compatibility matrix
| Configuration | Required behavior |
|---|---|
| Alongside PRA + SOAP IMS | ordered XML request, parsed result, one fiscal POST |
| Existing JSON IMS profile | original JSON transport and saved URL retained |
| Standalone FBR local IMS | SOAP on existing configured port when identified; JSON fallback retained |
| One online PC / one service | selection populated without UID/URL typing |
| Multiple PCs / services | explicit choice; never guess regulator registration |
| Another tenant / counter | device telemetry stays scoped to authenticated company and UID |
| Older Agent | omitted telemetry preserves data; cannot claim new SOAP records |
| Existing PRA configuration | mode, tokens and fiscal submission code unchanged |

Discovery uses bounded loopback WSDL GETs, standard ports plus Windows HTTP
registrations. It does not decrypt config or submit probe invoices. Detection
does not establish POS ownership, environment or central synchronization.
The local component environment must be verified independently.

## Other reported paths
Open POS restores minimized windows and retries a prior offline cold-start on
an explicit click. This specific recovery path is addressed; the owner's full
Windows symptom has not been reproduced on their PC.
The Agent already registers complete portable installations in current-user
Windows Installed Apps, and NSIS owns its own entry. The install directory is
per-user AppData, not necessarily Program Files. Added direct buttons to find
the actual installation and open Windows uninstall settings. No blind registry
deletion or program-folder relocation.

## Verification
- Focused Node checks: 26 passing (SOAP, discovery, bridge, renderer/preload,
  visibility, launch UI, release identity, Windows uninstall registration).
- Full Agent run after dependency installation: 141 tests; one unavailable
  Electron binary test and an unrelated waiter ordering test fail. Remaining
  tests pass or Windows-only skip. Waiter test reads unchanged production
  assets; not represented as caused or fixed by this patch.
- `node --check`: Agent main, preload, transport and discovery passed.
- `git diff --check`: passed.
- PHP/application/MariaDB/Chrome: unavailable locally. Bootstrap fails because
  PHP is absent; MariaDB helper reports missing server/sudo; apt fails with
  restricted setgroups/seteuid. Added device telemetry regression test, pending CI.
- Real Windows installer, original Open POS failure and real-company fiscal
  acceptance remain unverified. PR is not evidence of a live fix.
