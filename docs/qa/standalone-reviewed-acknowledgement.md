# Standalone IMS acknowledgement parity (#373)

Synthetic acceptance only, not a real IMS/PRAL/FBR certification.

- New retail bills freeze requested environment at creation. Old queued bills with no reliable snapshot, or a changed configured environment, are held before dispatch rather than silently migrated to production. No historical environment is backfilled by assumption.
- Number-only acknowledgement uses the same exact-shape classifier as the PRA/DI IMS adapter: observed FBRInvoiceNumber string, response_received transport evidence, original POS prefix and valid number. It never fabricates Code100.
- Standalone additionally requires a fresh exclusive claim, exact company/bill/device/claim binding, original production environment, and the same independently reviewed device-evidence row at dispatch and callback. Revoked/superseded proof, absent proof, wrong device/number, extra fields, stale claims and absent transport evidence cannot stamp acceptance.
- Review is an explicit SaaS superadmin operation at Company → Reviewed device evidence; approval/revocation is append-only. Heartbeats/configuration are not proof. The company lock serializes review/revocation with callback acceptance. No approval or reporting switch is created by migration.
- Number-only local acceptance is stored as NUMBER_ONLY_REVIEWED, not synthetic Code100; central status stays unknown. Duplicate callbacks preserve the first saved number. Historical supported explicit100 callbacks remain compatible.
- Agent REST and decoded SOAP callbacks transmit bounded field-shape evidence and device/claim, including when the Agent itself treats number-only as unknown. Timeout/reset remain verification holds, not a second invoice dispatch.
- School and Hospital remain local-only; this mechanism does not authorize their routes.

Required external evidence remains: official installed component environment and exact registered company/device/POS mapping, an independently reviewed evidence reference/digest, and actual sandbox/production device plus central reconciliation acceptance. Synthetic tests do not satisfy those requirements.

Local evidence: FbrPosSubmissionEvidenceTest, FbrPosIdentityTest and PraDiSandboxTest; Agent fbr-claim-callback-flow REST/SOAP tests. Hosted native MariaDB and browser/complete CI must pass on the final PR SHA before review readiness.
