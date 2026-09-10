# tiden/phpunit-reporter

Tiden reporter for [PHPUnit](https://phpunit.de) — a PHPUnit extension that watches a test
run and reports results into [Tiden](https://tiden.ai)'s public Test Runs API. No changes to
your test code. The PHP counterpart to
[`tiden-javascript`](https://github.com/qase-tms/tiden-javascript) (Playwright, Vitest, Jest)
and [`tiden-go`](https://github.com/qase-tms/tiden-go).

> **Not published yet.** Nothing is on Packagist; the package name `tiden/phpunit-reporter`
> is planned, not claimed.

```bash
composer require --dev tiden/phpunit-reporter
```

Register it in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Tiden\PHPUnitReporter\TidenExtension"/>
</extensions>
```

Then turn it on with environment variables — in CI only, if you like:

```bash
export TIDEN_MODE=tiden
export TIDEN_BASE_URL=https://api.tiden.ai
export TIDEN_API_TOKEN=tfy_...
export TIDEN_PRODUCT_ID=<your product uuid>
vendor/bin/phpunit
```

**With none of those set the extension does nothing and says nothing.** Local development is
unaffected. If you set some of them but not all, it tells you exactly which one is missing —
you meant to report, so a silent no-op would just cost you an afternoon.

## What it does

- Reports every test as it runs, from PHPUnit's event stream. Nothing parses a JUnit file.
- Gives each case a stable, param-free signature so its history accumulates in one place.
- Sends `fields["file_path"]`, the key Tiden joins requirements to tests on.
- Creates the run, or joins one you already created, and completes it when the last worker
  is done — correctly under ParaTest.
- Uploads in batches, retries on rate limits, and never lets its own failure fail your suite.

## Configuration

Every setting can come from an environment variable, from `tiden.config.json` in the working
directory, or from a `<parameter>` in `phpunit.xml`. They use the same names everywhere, and
environment beats file beats parameter.

| Variable | Meaning |
| --- | --- |
| `TIDEN_MODE` | `tiden` to report, `report` to write results to disk, `off` (default) |
| `TIDEN_FALLBACK` | mode to use if the primary one cannot run |
| `TIDEN_BASE_URL` | e.g. `https://api.tiden.ai` |
| `TIDEN_API_TOKEN` | personal API token (`tfy_…`) |
| `TIDEN_PRODUCT_ID` | the product to report into |
| `TIDEN_ROOT_DIR` | directory test paths are relative to (default: cwd) |
| `TIDEN_ROOT_SUITE` | a suite to nest everything under, e.g. `PHPUnit tests` |
| `TIDEN_ENVIRONMENT` | environment slug, e.g. `ci` |
| `TIDEN_RUN_ID` | join an existing run instead of creating one |
| `TIDEN_RUN_COMPLETE` | `false` to leave completion to an orchestrator |
| `TIDEN_RUN_TITLE`, `TIDEN_RUN_DESCRIPTION` | run metadata |
| `TIDEN_BRANCH`, `TIDEN_BUILD_SHA` | git metadata for the run |
| `TIDEN_BATCH_SIZE` | results per request (default 200, max 2000) |
| `TIDEN_STATUS_MAPPING` | e.g. `invalid=failed` |
| `TIDEN_STATE_FILE` | where workers coordinate (see ParaTest below) |
| `TIDEN_REPORT_CONNECTION_PATH` | output directory for `report` mode |
| `TIDEN_DEBUG`, `TIDEN_LOGGING_CONSOLE`, `TIDEN_LOGGING_FILE` | logging |

`tiden.config.json` uses the same nested shape as the JavaScript reporters, so a repository
that already reports from JS can point PHP at the file it already has.

## Attributes

All optional, all display-only — none of them changes which case a test is.

```php
use Tiden\PHPUnitReporter\Attribute\{Title, Suite, Tags, Field, Parameter};
use Tiden\PHPUnitReporter\Tiden;

#[Suite('Billing')]
final class InvoiceTest extends TestCase
{
    #[Title('Charges the customer exactly once')]
    #[Tags('smoke', 'billing')]
    #[Field('layer', 'unit')]
    #[Parameter('currency', 'EUR')]
    public function test_it_charges_once(): void
    {
        Tiden::comment('anything worth seeing next to the result');
        // ...
    }
}
```

`#[Suite]` replaces the namespace-derived path rather than extending it. `Tiden::title()` and
`Tiden::comment()` are no-ops when the extension is not bootstrapped, so tests using them run
unchanged for anyone without Tiden configured.

## Case identity

Each test method is one Tiden case, identified by:

```
php/v3::<namespace segments>::<class>::<method>      (lowercased, whitespace collapsed)
```

Two properties are deliberate and worth knowing:

**It is param-free.** A `#[DataProvider]` with twenty rows is ONE case with twenty result
attempts, not twenty cases. The row is carried in the result's `params`, not in its identity.
This matches `generateSignature` in the JavaScript reporters and `pkg/gotest` in the Go one.

**It never depends on presentation.** `TIDEN_ROOT_SUITE`, `#[Suite]` and `#[Title]` change
where a case appears and what it is called, never what case it is. Otherwise setting a root
suite in CI would fork the history of every case in the product.

Changing the rule means bumping `php/v3`, not editing the pipeline: a signature that changes
for an already-reported test forks its case and strands its history. `SignatureTest` holds a
golden file that fails loudly if anything drifts.

## Requirement linking

Tiden joins requirements to tests by source file, so every result carries
`fields["file_path"]` relative to `TIDEN_ROOT_DIR`.

If a test file resolves outside that root the field is **omitted rather than guessed** — an
unlinkable case is better than one linked to the wrong requirement — and you get a warning
naming the file. If *no* result in the whole run resolved a path, that is a misconfiguration
rather than an edge case, so the reporter says so and **declines to complete the run**. The
usual cause is a containerised suite: PHPUnit sees `/application/tests/...` while
`TIDEN_ROOT_DIR` still points at the host checkout. Set it to the mount point.

## ParaTest

Each ParaTest worker bootstraps the extension in its own process, and ParaTest's parent does
not run PHPUnit's event system — so the workers coordinate through a locked state file (in
the temp directory, keyed by the ParaTest parent process). The run is created exactly once,
and the state file is kept for the whole invocation so that **no worker can ever open a
second run**.

If a worker **dies** without reporting, the run is deliberately left open. An incomplete run
cannot pass a quality gate, whereas a completed run quietly missing a worker's results looks
like a pass. Install `ext-posix` to have the reporter name the process that died; without it
the run is still left open, just without the diagnostic.

**One limitation, stated plainly.** ParaTest tells a worker its own token but never how many
workers there are, so a worker cannot know whether it is the last. It completes the run when
every worker it has *seen* has finished. If a worker starts only after that — possible with a
small suite, where a worker can finish before a sibling has booted — it joins the same run
(never a second one) and logs an error saying its results cannot be recorded, because the run
is closed. If you hit that, take completion out of the workers' hands:

```bash
TIDEN_RUN_COMPLETE=false vendor/bin/paratest
tiden run complete "$TIDEN_RUN_ID"
```

When several suites share one CI job, set `TIDEN_STATE_FILE` explicitly. It is safe to reuse
the same path across runs: a state file whose parent process differs from the current one is
treated as a leftover and ignored.

## Sharded CI

Create the run once, pass its id to every shard, and let the last one close it:

```bash
# shard 1..N
export TIDEN_RUN_ID=<seq>
export TIDEN_RUN_COMPLETE=false
# final step
tiden run complete <seq>
```

## Develop

```bash
composer install
composer test      # phpunit
composer analyse   # phpstan, level 6
composer lint      # pint --test
composer format    # pint
```

`examples/` is a small suite covering every outcome the reporter can report; the integration
test runs it in `report` mode and diffs the output against a golden file. The contract test
in `tests/Contract` validates the request bodies against `tiden-specs`' `openapi.yaml` when
that repository is checked out alongside this one.

Supports PHP ^8.2 and PHPUnit ^10.5 || ^11.0 || ^12.0.

MIT © Qase
