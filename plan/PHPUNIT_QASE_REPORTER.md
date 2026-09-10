# Qase PHPUnit Reporter Integration

How `qase/phpunit-reporter` is wired into this repository, when it actually sends anything,
and what the CI orchestration around it looks like.

**Summary:** the reporter is registered as a PHPUnit 12 extension in `phpunit.xml` and is
inert everywhere except CI, where a few `QASE_*` env vars switch it into `testops` mode.

---

## 1. Dependency and registration

| Piece | Location |
| --- | --- |
| Package | `composer.json` → `require-dev`: `"qase/phpunit-reporter": "^2.0.0"` |
| Transitive | `qase/php-commons`, `qase/qase-api-client`, `qase/qase-api-v2-client` |
| Registration | `phpunit.xml` → `<extensions><bootstrap class="Qase\PHPUnitReporter\QaseExtension"/></extensions>` |

That extension block is the **only** wiring point. No code under `app/` or `tests/` imports
any Qase reporter class.

`QaseExtension::bootstrap()`:

1. builds a `CoreReporter` via `ReporterFactory::create('phpunit/phpunit', 'qase/phpunit-reporter')`;
2. registers subscribers on the PHPUnit event stream — `TestRunnerStarted`, `TestPrepared`,
   `TestPassed`, `TestFailed`, `TestErrored`, `TestSkipped`, `TestMarkedIncomplete`,
   `TestConsideredRisky`, `TestWarningTriggered`, `TestFinished`, `TestRunnerFinished`
   (plus `TestPreparationErrored` on PHPUnit 11.4+/12).

---

## 2. Locally it does nothing

There is no `qase.config.json` in the repository, so configuration comes from `QASE_*`
environment variables only.

- `Qase\PhpCommons\Models\Config\QaseConfig` defaults `mode = Mode::OFF`.
- `ReporterFactory::createInternalReporter()` returns `null` for any mode that is not
  `testops` or `report`.

So `./Taskfile test_php_unit` (→ `vendor/bin/paratest --display-all-issues`, see
`bin/lib/backend.bash`) loads the extension, collects events, and discards everything.
`.env.testing` sets no Qase reporting variables.

---

## 3. CI is where it reports

`.github/workflows/15-php-tests.yml`, step **Run phpunit**:

```yaml
env:
  # https://github.com/qase-tms/qase-php-commons/blob/main/README.md#configuration
  QASE_MODE: testops
  QASE_TESTOPS_PROJECT: QMA
  QASE_TESTOPS_RUN_ID: ${{ inputs.qase-run-id }}
  QASE_ROOT_SUITE: 'PHPUnit tests'
```

The API token is imported from Vault as `QASE_TESTOPS_API_TOKEN`
(`qase/data/ci/php/testing QASE_API_TOKEN`) in the same workflow.

### Run lifecycle

The Qase test run is **not** created by the reporter:

1. `.github/workflows/01-prepare-unit-tests.yml` calls `qase-tms/gh-actions/env-create` and
   `qase-tms/gh-actions/run-create` against project `QMA`, and exports
   `UNITS_QASE_TESTOPS_RUN_ID`.
2. That single run id is passed to PHP unit, JS unit and screenshot suites alike
   (`00-unit-tests.yml`, `00-ultimate.yml`), so all unit-level results land in **one** Qase
   run, separated only by `QASE_ROOT_SUITE` (`PHPUnit tests` vs `JS tests`).
3. Completion is left to the reporters themselves: `RunConfig::$complete` defaults to `true`
   on the PHP side; the Node workflow sets `QASE_RUN_COMPLETE: 1` explicitly. There is no
   `run-complete` GitHub action for the unit run — only for e2e
   (`21-complete-e2e-tests.yml`).

---

## 4. Details worth knowing

**Paratest safety.** Every paratest worker process bootstraps the extension separately.
`php-commons`' `StateManager` coordinates through a `flock`-ed `data.json` inside the vendor
directory, refcounting `startRun` / `completeRun` so the shared run is created and completed
exactly once.

**No case linking.** Unlike the E2E suite — where `qase.id()` is mandatory
(`docs/E2E_GUIDELINE.md` §12) — no PHP test uses `#[QaseId]` or `#[Title]`. Results are
auto-created / matched by test title under the `PHPUnit tests` root suite, so PHP unit tests
are **not** traceable to pre-existing QMA case ids.

**Coverage output is unrelated.** The `<coverage>` / `<logging>` blocks in `phpunit.xml`
(`test-reports/clover.xml`, `test-reports/junit.xml`) feed SonarCloud, not Qase.

**Cache short-circuit.** When the `php-unit-reports-${{ github.sha }}` cache hits (the suite
already passed for this commit), the `Run phpunit` step is skipped entirely — that re-run
sends nothing to Qase, although the job still succeeds and still uploads the Sonar artifact.

---

## 5. Reference

- Reporter source: `vendor/qase/phpunit-reporter/src/QaseExtension.php`
- Config defaults: `vendor/qase/php-commons/src/Models/Config/QaseConfig.php`, `RunConfig.php`
- Reporter selection: `vendor/qase/php-commons/src/Reporters/ReporterFactory.php`
- Multi-process state: `vendor/qase/php-commons/src/Utils/StateManager.php`
- Upstream config docs: https://github.com/qase-tms/qase-php-commons/blob/main/README.md#configuration
