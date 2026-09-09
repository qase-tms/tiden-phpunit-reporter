# phpunit-reporter

Tiden reporter for [PHPUnit](https://phpunit.de) — reports test results into
[Tiden](https://tiden.ai)'s Test Runs API. The PHP counterpart to
[`tiden-javascript`](https://github.com/qase-tms/tiden-javascript) (Playwright,
Vitest, Jest) and [`tiden-go`](https://github.com/qase-tms/tiden-go).

> **Status: scaffold.** Nothing is implemented yet, and nothing is published to
> Packagist. Planned package name: `tiden/phpunit-reporter`.

## Scope

A PHPUnit extension that observes a run and reports results to Tiden — no
changes to test code required.

## Contract notes

These are fixed by the platform, not by this repo:

- Results go to the public Test Runs API (`ReportResults`), specified in
  [`tiden-specs`](https://github.com/qase-tms/tiden-specs)
  (`public-api/v1/openapi.yaml`).
- A reported result's `id` is the API's **idempotency key** and is validated as
  a UUID.
- **Case identity must be stable and param-free.** Every Tiden reporter derives
  one signature per logical case and keys through it; two identities for the
  same case fork its history. See the identity rules in `tiden-go`'s README and
  `generateSignature` in `tiden-javascript`'s `commons` package before choosing
  the PHPUnit mapping.

## Develop

Requires PHP ^8.2.

```bash
composer install
composer test
```

MIT © Qase
