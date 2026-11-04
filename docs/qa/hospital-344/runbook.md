# Reproduce issue 344 in a disposable lab

Use a fresh checkout of the issue branch, PHP 8.4 with the Composer platform requirements, locked PHP and Node dependencies, a loopback MariaDB 10.11 instance, and Chromium. PHPUnit uses SQLite in memory. The persistent demonstration uses the project’s MariaDB family. The demo is a local fixture. Nothing in this runbook requires production access.

## Provision

1. Provision an entirely separate MariaDB instance bound to loopback. Create a new **empty** database named `taxnest_dev` and give a local user access only to that database. The name follows the existing `DevStagingGuard` allowlist. A database containing tables is rejected. MariaDB was tested with `--alter-algorithm=COPY --innodb-flush-method=fsync` on fresh temporary storage.
2. Create a local, gitignored `.env` with `APP_ENV=local`, a newly generated `APP_KEY`, `APP_URL=http://127.0.0.1:8344`, `DB_CONNECTION=mysql`, the loopback host and port of that new instance, and its disposable credentials. Set `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `CACHE_STORE=array` and `SESSION_DRIVER=file`. Leave regulator credentials empty.
3. Install the dependencies from the repository locks and run the following from the checkout. Set the two demo variables only for this lab. Choose a new password. The initializer rejects shared, pre-populated and nonlocal databases. It excludes the historical customer-data seed and records the exclusion to keep future migrations from importing it.

```bash
composer install
npm ci
npm run build
export HOSPITAL_DEMO_ALLOW=1
read -rs -p 'Disposable demo login password: ' HOSPITAL_DEMO_PASSWORD
export HOSPITAL_DEMO_PASSWORD
php artisan health:demo-hospital
php scripts/hospital-demo-day.php
php scripts/hospital-demo-concurrency.php
php scripts/hospital-demo-clinical.php prepare > /tmp/hospital-344-case.json
```

The day and race scenarios are single-use and refuse a used fixture. The eight synthetic tenants comprise hospital profiles A–F plus a separately owned pharmacy and laboratory. Hospital A's owner email is `a.health_owner@example.test`. The central lab administrator is `demo.saas@example.test`. These are local-only identities.

## Browser, suite and restore

Launch Laravel in another terminal with the same local environment. Use the repository browser helper and a trusted local Chromium installation. The test denies non-loopback browser requests.

```bash
php artisan serve --host=127.0.0.1 --port=8344
# In the test terminal, with the same opt-in and local password:
export HOSPITAL_DEMO_CLINICAL_IDS="$(cat /tmp/hospital-344-case.json)"
BASE_URL=http://127.0.0.1:8344 HOSPITAL_DEMO_POST_PURCHASE=1 node scripts/hospital-demo-browser.mjs
php scripts/hospital-demo-clinical.php close
php scripts/hospital-demo-verify.php --check-purchase --check-journey > /tmp/hospital-344-before.json
```

The purchase step receives ten units and posts two supplier payments. Closing the clinical journey dispenses two units from that batch. Run it once on the fresh fixture. The read-only verification checks the amounts and fingerprints 23 tables. For a restore drill, take a complete `mariadb-dump --single-transaction --skip-lock-tables --no-tablespaces` of the disposable database. Restore the dump to an empty `taxnest_dev` on a **second** local MariaDB instance, use the matching lab encryption key and point the verification process to that instance. Run the verifier with the same flag and compare both JSON files byte-for-byte. Back up the private clinical files from the source test instance as well. Restore those files to a separate private disk root in the second instance, then verify with both flags. The JSON files must match, including the decrypted demo company names, the signed report attachment digest and the clinical balance. A clinical installation must separately exercise offsite key custody and its recovery objectives.

The automated-suite launcher requires Linux and libseccomp. It fails closed if it cannot deny network sockets. It is for SQLite PHPUnit runs. The MariaDB and browser commands above require local sockets.

```bash
python3 scripts/hospital-offline-tests.py php vendor/bin/phpunit tests/Feature/Health*.php
python3 scripts/hospital-offline-tests.py php vendor/bin/phpunit
```

For isolated groups, pass disjoint PHPUnit file lists and collect JUnit for each group. Preserve skips and errors as separate outcomes. Browser and database tests have their own evidence and should not be added to the unit-test count.

## Teardown and rollback

Stop the disposable server and browser. Discard only the explicitly provisioned lab instance, its credentials and its files. Start a new instance for another clean run.

For a future approved rollout, take and restore-test a database and attachment backup before migrations. Run the additive migrations before opening the new screens. If rollback is necessary, pause hospital writes, preserve post-release clinical and financial records and restore compatible application code and schema together. Verify receipt, stock, patient balance, referral and release histories before reopening. Destructive migration down methods destroy clinical records or receipt provenance and are inappropriate for a live rollback.

After every release gate closes, create a same-repository PR to main and wait for **PR checks / validate** on its final commit. Only the owner can approve the pinned PR number and full head SHA in **SaaS Admin → Deploy Approvals → Approve exact release**. Approval Relay, Owner Merge & Deploy, the protected deploy job, and Actions live verification must succeed. Follow [the repository’s owner procedure](../../ops/owner-merge-and-deploy.md). No owner approval is due for this development branch.
