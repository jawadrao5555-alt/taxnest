# Full PHPUnit verification — source `2b500f6a67320a1138cc555df342b5f29bdb2377`

This is the sanitized completion record for the committed partitioned runner.
The run used a fresh independent checkout at the exact source SHA, with the
already-installed locked Composer dependency tree copied into the ignored
`vendor/` directory. No current-head application or test files were used.

## Full-suite result

| Measure | Result |
|---|---:|
| PHPUnit list entries | `5018` |
| JUnit testcases | `5018` |
| Unique testcase IDs | `5018` |
| Missing IDs after PHPUnit dataset normalization | `0` |
| Extra IDs after PHPUnit dataset normalization | `0` |
| Duplicate IDs | `0` |
| Assertions | `39281` |
| Failures | `0` |
| Errors | `0` |
| Skipped | `7` |
| Partitions | `8` |
| Runner exit code | `0` |

Partition testcase counts were `628, 628, 627, 627, 627, 627, 627, 627`.
Each partition had zero duplicate IDs and exit code `0`. The runner's
sanitized JUnit, machine summary, and short report are the companion files
with this source SHA:

| Artifact | SHA-256 |
|---|---|
| `full-phpunit-2b500f6a67320a1138cc555df342b5f29bdb2377.json` | `0671595dba08aed54e5e381fa915025c978accf766069f49ee984c1bbe6a9153` |
| `full-phpunit-2b500f6a67320a1138cc555df342b5f29bdb2377.junit.xml` | `dd2e9e216b80fe45ce01d8dd2b10400818c02297129e35d58154e284c9b5f197` |
| `full-phpunit-2b500f6a67320a1138cc555df342b5f29bdb2377.md` | `97c8eccb41bb2eaee640d72a5fa7fac69f8643fd170076f1295166641b3563e0` |

The aggregate JUnit hash recorded by the runner is
`dd2e9e216b80fe45ce01d8dd2b10400818c02297129e35d58154e284c9b5f197`.
The runner runtime and its raw partition logs remain outside the primary
tracked tree under `.local/recertification/full-phpunit-runtime-2b500...`.

## Fresh dependency/build/guard checks

All checks below ran from the same independent `2b500...` checkout. Audit JSON
files report zero advisories/vulnerabilities and their retained hashes are:

| Check | Command/result | Evidence SHA-256 |
|---|---|---|
| Composer audit | `composer audit --locked --format=json --no-interaction --no-ansi` — exit `0`, `advisories=[]`, `abandoned=[]` | `e464a6f19a008d852b61fd0966a47b663d20d70ccfba0b30edd6483a787f15f5` |
| Root npm audit | `npm audit --json --package-lock-only --no-fund --no-progress` — exit `0`, total vulnerabilities `0` | `e993caa1e6e208484bd6fc7f4c2e0e618bf13755cce7b1b87b9395b5e55f1812` |
| PRA npm audit | same command in `pra-agent/` — exit `0`, total vulnerabilities `0` | `db0c67b59affd2159f4546bcd93ecb16e156eace88344afb0e329df5ff6bea4a` |
| Realtime npm audit | same command in `agent-realtime-gateway/` — exit `0`, total vulnerabilities `0` | `846fc260eb0bf70e6b21419d4cab9fcc61c224c7362324d4cbc8aebf8355f9bd` |
| Mockup sandbox npm audit | same command in `artifacts/mockup-sandbox/` — exit `0`, total vulnerabilities `0` | `ba6999e238113a806166a16328550e66d179f2c3f9d15037eda28e7d74885076` |
| Video pipeline npm audit | same command in `tools/video-pipeline/` — exit `0`, total vulnerabilities `0` | `846fc260eb0bf70e6b21419d4cab9fcc61c224c7362324d4cbc8aebf8355f9bd` |
| Web build | `npm run build` — exit `0`, Vite `7.3.6`, built in `5.08s` | `45e4c27c07fe845ec97b5944acaef7d07c3bf0bca0c7111a2311b21a30988cb9` |
| Node engine guard | explicit Node 22.15.1 `PATH` through `bash scripts/rc-safe-run ... -- node --version` — exit `0`, resolved locked Node `v22.15.1` | `0718bba2a660eb9e3d02c2733847866396444b44fc872c70ca25d28fa7988de6` |
| Artifact guard | `bash scripts/verify-repository-artifacts.sh` — exit `0` | `c994f7555c388641086d1b6ba4175d86446b429dd43187197213bb7576f42663` |
| Network guard build | `RC_NETWORK_GUARD_BUILD_DIR=/tmp/taxnest-rc-guard-2b500 bash scripts/rc-network-build.sh` — exit `0` | `f9f61902828d5d3d54839116fd31fd36be833ef6f92d009a23a5646037655491` |

All six audited lockfile hashes are unchanged from the prepared checkout:

| Lockfile | SHA-256 |
|---|---|
| `composer.lock` | `f77aae4ea1f5d8b5427132fb76e2ca017b7917ebdece26036715055a5fc141e4` |
| `package-lock.json` | `ee6faa95cd57839884299d07e871ae9593029be42bd363f523d1b13caac8ff10` |
| `pra-agent/package-lock.json` | `288f23ac9c63ec72a2318895ebb582f0cb85b31f07aeefcffc60800d22a74472` |
| `agent-realtime-gateway/package-lock.json` | `3c96680d9ce8a9d0c6085fafc250a3b3de4a8df3f099d40b441d4e76f54b461a` |
| `artifacts/mockup-sandbox/package-lock.json` | `aecf45ae4de09b7b7bdbe65fe0c21e88504a1ae01704ea6fdb42c2fd9cff861a` |
| `tools/video-pipeline/package-lock.json` | `1ea36c806550b001e4b9cf14d35160432094bf0a2e6facd73f8c93a087f7acf8` |

`composer validate --strict` retains the pre-existing schema warning that
`webklex/php-imap` uses an unbound `*` constraint; Composer audit itself was
clean and no lockfile was modified.