# Changelog

## Unreleased

- Added support for PHPUnit 13 on PHP 8.4.1 or newer while preserving PHPUnit
  11 and 12 support for PHP 8.3 projects.

## 0.1.0 — 2026-08-09

- Initial release: a PHPUnit adapter for the property-testing engine — the
  `Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting` trait and the fluent
  `PropertyCheck` builder (`forAll()->runs()->check()`) over
  `rasuvaeff/property-testing-core`.
- Result mapping: a pass registers one assertion; every failing outcome
  surfaces as one `AssertionFailedError` carrying the engine exception as
  `previous`; `Assume::that()` discards are retried runs, never skipped tests.
- Environment and corpus parity with the Testo adapter: the
  `PROPERTY_RUNS`/`PROPERTY_SEED`/`PROPERTY_VERBOSE`/`PROPERTY_DB` contract,
  the distribution report and discard warning, and a regression-corpus format
  interoperable with `rasuvaeff/property-testing` 2.8.
- Supported PHPUnit majors: `^11.5 || ^12.0` (PHP 8.3–8.5).
