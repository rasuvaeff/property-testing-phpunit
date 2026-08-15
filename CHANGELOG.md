# Changelog

## Unreleased

- `PROPERTY_DB` now also takes a `redis://host[:port][/key-prefix]` DSN, which
  builds core 0.3's `RedisCorpus`. Until now that class existed and no suite
  could reach it: the engine reads no environment by design, and this adapter
  hardcoded the filesystem corpus. A directory keeps meaning exactly what it
  meant. `ext-redis` is preferred when loaded, `predis/predis` otherwise, and
  neither installed is an error rather than a silent fall back to the
  filesystem. Same variable, same messages as the Testo adapter.

## 0.3.0 — 2026-08-15

- Added `PropertyCheck::edgeCases()` and `PROPERTY_EDGE_CASES` (`mixin` or
  `none`), reaching core 0.3's switch for the numeric boundary bias. Turning it
  off stops a property that cannot use `0`, `±1` or a range's ends from
  spending one run in five on a value it discards. The variable overrides the
  chain, like every other CI-facing knob, and an unknown value throws rather
  than silently keeping the bias it was told to drop.
- **Requires `rasuvaeff/property-testing-core` `^0.3`.**

## 0.2.0 — 2026-08-14

- Added the 0.2 run knobs, as fluent setters and as environment variables:
  `shrink()`/`shrinkBudgetMs()` (report a counterexample as generated, or bound
  the descent), `phases()` (`PROPERTY_PHASES`), `derandomize()`
  (`PROPERTY_DERANDOMIZE`) and `path()` (`PROPERTY_PATH`, replaying a recorded
  shrink descent instead of searching for it again). Precedence follows one
  rule, now stated in the README: the environment dials the suite and wins for
  `PROPERTY_RUNS`/`PROPERTY_PHASES`/`PROPERTY_DERANDOMIZE`, while the code pins
  the property and wins for `seed()`/`path()`.
- A property whose id was derived from a closure now says so on stderr, through
  the channel that already carries the excessive-discard warning. Such an id
  keys the regression corpus by something that moves — `{closure}` on PHP 8.3
  collapses every closure of a class onto one key, and `{closure:file:line}`
  from 8.4 moves when a line is inserted above. `id()` is the fix.
- **Requires `rasuvaeff/property-testing-core` `^0.2`.** The knobs above are
  0.2 engine fields; there is no version of this adapter that offers them
  against core 0.1.

- Added `PropertyCheck::id()`: names the property, replacing the id derived
  from the calling method. The string is used verbatim — as the
  regression-corpus key, as the id on every event, and as the property's
  display name. It exists for the case the derived id cannot serve: a
  `forAll()` called from a closure, where PHP has no stable name to derive
  from. On PHP 8.3 every closure of a class is `{closure}`, so two properties
  in one file share a corpus key and overwrite each other's counterexample;
  from 8.4 the name carries a line number, so an edit above the property
  orphans yesterday's entry. Neither throws — the corpus simply stops
  replaying. Pest's `it()`/`test()` bodies are the common source of both.

## 0.1.1 — 2026-08-10

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
