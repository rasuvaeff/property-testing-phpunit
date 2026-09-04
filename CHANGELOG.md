# Changelog

## 0.7.0 — 2026-09-04

- An environmental skip (`markTestSkipped()`, `markTestIncomplete()`) is
  reported to the engine as `TrialOutcome::skipped()` rather than as a plain
  discard, so a recorded regression whose replay only skipped is kept instead
  of pruned. A machine without the dependency the body guards against used to
  delete the counterexample for every machine that has it. Requires
  `rasuvaeff/property-testing-core` ^0.8.

## 0.6.3 — 2026-09-04

- Allows `rasuvaeff/property-testing-core` `^0.7`.
- The discard warning is printed before the distribution line, matching the
  Testo adapter; the two emitted the same pair in opposite orders.
- `PropertyCheck::clock()` — an internal seam, mirroring the Testo adapter, that
  makes the `timeoutMs` and `budgetMs` branches testable without real waiting.
- The `id()` documentation is attached to `id()` again; it had drifted onto
  `currentId()`, leaving both methods undocumented.

## 0.6.2 — 2026-09-03

- Synchronize the published dependency documentation with the supported
  `rasuvaeff/property-testing-core` `^0.5 || ^0.6` constraint.

## 0.6.1 — 2026-09-03

- Allows `rasuvaeff/property-testing-core` `^0.6` beside `^0.5`: the 0.6 line changes nothing the adapter calls (the corpus and environment parsing it delegates keep their API), and its `SEQUENCE_EPOCH` bump only fences off seed entries recorded under 0.5.

## 0.6.0 — 2026-09-02

- Requires `rasuvaeff/property-testing-core` `^0.5`. The corpus resolution
  and the `PROPERTY_*` parsing now come from the engine (`CorpusFactory`,
  `EnvironmentOverrides`); the adapter's own `CorpusFromEnv`, `RedisDsn` and
  `LazyPhpRedisCorpusClient` (all `@internal`) are gone. `PROPERTY_RUNS` /
  `PROPERTY_SEED` past the integer range are refused instead of saturating.
- The `PROPERTY_DB` Redis DSN has the IANA shape:
  `redis://host[:port][/db][?prefix=key-prefix]`, `rediss://` for TLS. The
  path is the database index; the pre-0.6 form with the key prefix in the
  path (`redis://host/suite-a:`) is refused with the new spelling in the
  message.
- `markTestSkipped()` / `markTestIncomplete()` inside the body skip that run
  instead of falsifying the property and shrinking toward the smallest input
  that still skips; when every run skipped, the skip is rethrown and PHPUnit
  reports the test as skipped or incomplete.
- With a data provider the corpus id carries the data set name
  (`Class::method with data set "large"`), so one set's replay no longer
  prunes another set's regression.
- The unstable-id warning is printed only when a corpus is in use, and once
  per id per process — not on every `check()` of every closure-derived test
  under Pest.
- `PropertyCheck::__construct()` and `output()` are `@internal`;
  `forAll()` is the entry point.

## 0.5.2 — 2026-08-20

- `forAll()` called from a helper (not directly in the test method) now warns
  on stderr, the way a closure-derived id already did. Its id names the helper,
  so every test using that helper shares one corpus entry and overwrites the
  others' counterexample — a stable-looking id that is silently wrong. Pin it
  with `->id()` to silence the warning.
- `PROPERTY_DB` with credentials in its userinfo (`redis://user:pass@host`) is
  rejected instead of silently dropped — `parse_url` would discard them and the
  connection would go without AUTH. The error never echoes the DSN.
- The resolved corpus is memoized per `PROPERTY_DB` value, so a suite sharing a
  Redis corpus builds one client (and opens one connection) rather than one per
  property. Mirrors the Testo adapter.

## 0.5.1 — 2026-08-20

- `PROPERTY_DB` with a non-`redis` URI scheme is now a configuration error
  instead of a directory named after the scheme. Only an exact `redis://`
  prefix was recognised, so a `rediss://` typo — or any other scheme — fell
  through to `FilesystemCorpus` and silently wrote the corpus to a directory
  nobody reads, exactly the "silent fall back to the filesystem" the design
  forbids. Scheme matching is now case-insensitive (`Redis://` is a shared
  corpus) and the error names the scheme but not the DSN, which may carry
  credentials. A path with no scheme is unchanged.

## 0.5.0 — 2026-08-16

- Added fluent `auto()`: a generator is derived from the property closure's
  own signature for every parameter the `forAll()` map does not cover, via
  core 0.4's `Gen::forParameters()` (the `@param` psalm type from the
  closure's docblock over the native type; a type it cannot read throws
  naming the function and the parameter). The map becomes the overrides and
  may be partial; `forAll()` now defaults to `[]`, so a fully-typed closure
  needs no map at all; with `auto()` a map key that is not a parameter of the
  closure is an error. Strictly opt-in — it will never become the default —
  and deliberately without a `PROPERTY_AUTO` environment variable. Without
  `auto()` behavior is unchanged. Parity with the Testo adapter's
  `#[Property(auto: true)]`.
- Requires `rasuvaeff/property-testing-core` `^0.4`.

## 0.4.0 — 2026-08-15

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
