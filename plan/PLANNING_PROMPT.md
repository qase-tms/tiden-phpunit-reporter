# Planning prompt — `tiden/phpunit-reporter`

Produce an implementation plan (not code) for building the Tiden PHPUnit reporter and
landing it in the two repositories that consume it. Everything below is context and
constraint; verify each claim against the source before you rely on it — this prompt is a
map, not the territory.

## Objective

Build `tiden/phpunit-reporter`: a PHPUnit extension that observes a test run and reports
results into Tiden's public Test Runs API, requiring no changes to test code. Then adopt
it in `qase-tms/app` so that PHP test results carry into Tiden's requirements/coverage
system, and delete the hand-rolled bridge that currently does this job.

Definition of done for the plan: a reviewer can see, per phase, what gets built, what it
replaces, in what order the repositories change, and how each phase is verified.

## Repositories in play

| Path | What it is | Role here |
| --- | --- | --- |
| `/Users/avaganov/Projects/tiden-workspace/repos/phpunit` | `qase-tms/tiden-phpunit-reporter` — README/LICENSE scaffold, no code, nothing on Packagist | **The package to build.** Planned name `tiden/phpunit-reporter` |
| `/Users/avaganov/Projects/qase-phpunit` | `qase-tms/qase-phpunit` (`qase/phpunit-reporter` v2.1.11) | **Structural reference.** A working PHPUnit 10/11/12 extension: `QaseExtension` bootstrap, one subscriber per event, attribute readers, `StatusDetector`, split of reporter core into `qase/php-commons` |
| `/Users/avaganov/Projects/app` | `qase-tms/app` — the Qase TMS Laravel monorepo | **The consumer.** ~870 PHP test files / ~5,256 test methods, currently invisible to Tiden |
| `/Users/avaganov/Projects/tiden-workspace/repos/javascript` | `tiden-javascript` — npm workspaces `commons`, `playwright`, `vitest`, `jest`, `api-client` | **The reference implementation of the contract.** The PHP package is its sibling; read `commons/src` for the shape (client, config, env, options/modes, reporters, models) |
| `/Users/avaganov/Projects/tiden-workspace/repos/tiden-go` | `tiden-go` — `pkg/gotest` | Same contract, Go side; golden-locked identity rules |
| `/Users/avaganov/Projects/tiden-workspace/repos/specs` | `tiden-specs` | Owns `public-api/v1/openapi.yaml` and generates the PHP client (`sdk/php.yml` → `Tiden\ApiClient`, package `tiden/api-client`) |
| `/Users/avaganov/Projects/tiden-workspace/repos/telemetry-php` | `tiden/telemetry-php` | **Convention reference** for a Tiden PHP package: PHP ^8.2, Pint + PHPStan, `composer test/analyse/lint`, tag release to Packagist |

## Prior art you must read before planning

1. **`repos/phpunit/plan/PHPUNIT_QASE_REPORTER.md`** — how `qase/phpunit-reporter` is wired
   into `qase-tms/app` today: registered only in `phpunit.xml` `<extensions>`, inert
   locally (`mode` defaults off), switched on in CI by `QASE_*` env vars; the test run is
   created *outside* the reporter by GitHub Actions and shared across PHP/JS suites;
   paratest workers each bootstrap the extension and coordinate through a `flock`-ed state
   file. That document is the behavioural bar for the Tiden equivalent.
2. **`qase-tms/app` PR #3865** (`TIDEN-39-test-infra`, open, 56 files, +5110/−4) —
   "Add PHPUnit-to-Tiden reporting bridge and test-env fixes". This is the integration
   *without* a reporter: `app/Testing/Tiden/**` plus two artisan commands
   (`tiden:report-phpunit`, `tiden:ingest-phpunit`) that parse the JUnit XML report and
   shell out to the `tiden` CLI (`run create --format json` → `run report` → `run complete`,
   and a registry ingest path). Read the PR body: it documents four defects found in
   review (non-injective signature, run-id parsing, incomplete-report-reported-green,
   idempotency keys persisted too late). **Those four defects are the acceptance tests for
   the package** — whatever the reporter does must not reintroduce them.

## Fixed constraints (platform, not negotiable in this plan)

- Results go to the public Test Runs API (`ReportResults`), specified in `repos/specs`
  `public-api/v1/openapi.yaml`. Confirm the exact operation and paths there.
- A reported result's `id` is the API's **idempotency key** and is validated as a **UUID**.
- **Case identity must be one stable signature per logical case.** Two identities for the
  same case fork its history; two cases sharing one signature silently drop a result — and
  if the dropped one failed, the survivor reads as a pass.
- Requirement↔test linking joins on `file_path`; a wrong value makes a case unlinkable,
  so it must fail loudly rather than be fabricated.
- PHP ^8.2. PHPUnit version range to be decided (qase-phpunit supports ^10 || ^11 || ^12).

## Decisions the plan must actually resolve

Do not leave these implicit. For each, state the choice, the reason, and what it costs.

1. **Where the reporter core lives.** Qase splits `qase/php-commons` from
   `qase/phpunit-reporter`; `tiden-javascript` keeps `commons` in one monorepo with the
   framework packages. Choose: single package, or `tiden/php-commons` + thin extension —
   knowing PHPUnit will not be the last PHP framework (Pest, Codeception, Laravel).
2. **Case identity, including the parametrized case.** `@tiden/reporter-commons`'
   `generateSignature` is deliberately **param-free** (one identity across every data row;
   params hashed at attempt level). PR #3865's `PhpTestSignatureBuilder` deliberately goes
   the other way: `php/v2` = version tag, repo slug, namespace segments, method, and a
   **dataset segment** (`positional:`/`named:` + `rawurlencode`), so each data-provider row
   is its own Tiden case — and the docblock explicitly says not to "fix" the asymmetry.
   Decide which rule the package ships, and say what happens to already-reported `php/v2`
   signatures in `qase-tms/app` if the answer differs. Cross-check `pkg/gotest` (leaf
   subtest = case, param-free) before choosing.
3. **Transport: API client vs `tiden` CLI.** PR #3865 shells out to the CLI. A reporter
   should presumably talk to the API directly via the generated `tiden/api-client` from
   `repos/specs`. Decide, and account for what the CLI was giving the app for free
   (run creation attached to an intent session, branch routing, `seqNum` handling).
4. **Run lifecycle ownership.** Who creates and completes the run: the reporter, CI, or an
   intent session? In `qase-tms/app` today one shared run id is created by a workflow and
   passed to PHP, JS and screenshot suites. Note the constraint that a reporter opening its
   own run reports against the git branch instead of the intent branch.
5. **Reporting live vs parsing JUnit.** The reporter subscribes to the PHPUnit event stream;
   PR #3865 post-processes `test-reports/junit.xml`. Decide whether the JUnit path survives
   at all (it is what makes the CI cache short-circuit and paratest-worker-death cases
   detectable), or whether the event stream fully replaces it.
6. **Multi-process safety.** `./Taskfile test_php_unit` runs **paratest**. Every worker
   bootstraps the extension. Specify how the run is started/completed exactly once and how
   results are not lost or duplicated across workers.
7. **Registry ingest.** PR #3865 does two jobs: report results *and* upsert the test
   registry so cases become linkable to requirements (`externalId = "s:" + signature`).
   Draw the line: what the reporter package owns, and what stays app-side or moves to the
   `tiden` CLI (`tiden test push` / ingest).
8. **Config surface.** Mirror the JS env contract (`TIDEN_MODE`, `TIDEN_API_TOKEN`,
   `TIDEN_BASE_URL`, `TIDEN_PRODUCT_ID`, `TIDEN_RUN_ID`, `TIDEN_RUN_COMPLETE`, root suite)
   plus a config file, and keep the "any one missing ⇒ silently disable" behaviour so the
   extension is inert for local developers. Verify the actual names in
   `repos/javascript/commons/src/env` and `config` rather than trusting this list.
9. **Attributes / metadata.** Which of qase-phpunit's attributes have a Tiden meaning
   (`Title`, `Suite`, `Tags`, `Field`, `Parameter`) and which do not (`QaseId`, `QaseIds` —
   Tiden has no pre-existing numeric case ids to link against in this flow).

## Phasing to plan for

- **P0 — package skeleton**: composer manifest, PSR-4, Pint/PHPStan/PHPUnit config, CI
  workflow, following `repos/telemetry-php`. Nothing published yet.
- **P1 — reporter core**: config/env resolution, API client wiring, result model +
  transform, signature, status mapping, batching, idempotency keys, run lifecycle,
  multi-process coordination.
- **P2 — PHPUnit extension**: `bootstrap` + event subscribers, attribute reading, status
  detection, attachments/steps if in scope.
- **P3 — adoption in `qase-tms/app`**: register in `phpunit.xml`, wire CI
  (`15-php-tests.yml`), and **delete what the package now owns** — go file by file through
  PR #3865's `app/Testing/Tiden/**`, the two artisan commands, and their ~14 test classes,
  and say for each: replaced by the package / kept app-side (and why) / dropped. Call out
  the parts of that PR that are unrelated to reporting (Passport keypair provisioning in
  `bin/lib/backend.bash`, the `chromium_part1/2` fixes, the API Playwright reporter) and
  should land regardless.
- **P4 — release**: Packagist publication of `tiden/phpunit-reporter`, versioning, and the
  order in which the two repos merge.

## Output

A plan document with: the resolved decisions (§ above) stated as decisions; a file-level
build order for the package; the P3 migration table for `qase-tms/app`; the verification
step per phase (what command proves it, including a real end-to-end report against a
throwaway run, as PR #3865 did); and an explicit list of what is **out of scope**.

Where the evidence is thin or two sources disagree, say so in the plan instead of
resolving it silently. Flag anything that needs a human decision — package naming,
Packagist ownership, whether the `php/v2` signature is allowed to change.
