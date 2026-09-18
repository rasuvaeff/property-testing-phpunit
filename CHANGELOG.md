# Changelog

## Unreleased

- `check()` validates the `forAll()` map before the engine runs, and both
  checks are behaviour changes for a suite that relied on the silence: a
  value that is not an `ArbitraryInterface` is rejected with
  `Property "<name>": forAll() expects array<string, ArbitraryInterface>, got int for key "x"`
  instead of failing inside the runner as a method call on a non-object with
  neither the key nor the property in the message; and a key that is not a
  parameter of the closure is rejected with or without `auto()` — before,
  only `auto()` checked it and a typoed entry ran green in whatever domain
  the real parameter had (#49). Message shapes follow the Testo adapter's.
- Every outcome of a property registers one assertion on the running
  `TestCase`, not only a pass: a falsified property whose body performed no
  PHPUnit assertion was reported twice, as Failed and as Risky ("This test
  did not perform any assertions"). A property whose every run was skipped
  still registers none — that test is skipped, not checked (#50).
- The chain validates at the setters, naming the property: `runs(0)` throws
  `Property "<name>": runs must be greater than or equal to 1` at the call,
  and so do `maxShrinks`/`maxDiscards` below `0` and
  `timeoutMs`/`budgetMs`/`shrinkBudgetMs` below `1`. A `path()` or
  `PROPERTY_PATH` without a `seed()` or `PROPERTY_SEED` is refused by
  `check()` the same way (`path()` and `seed()` may come in either order, so
  the setter cannot). The engine used to reject the same values with the
  same bounds, but without saying which property.
- The distribution report, the discard warning and the unstable-id warnings
  are written as `"\n" . $line . "\n"`: PHPUnit prints its progress dots on
  the same terminal, and a line that started where the cursor was ended up
  glued to `....F..`. The line content is unchanged and still byte-identical
  to the Testo adapter's; the leading newline is a PHPUnit-only asymmetry
  recorded in `AGENTS.md`. The `VerboseListener` trace is untouched.
- Documentation catches up with core 0.10 (README ×2, `llms.txt`,
  `AGENTS.md`): `PROPERTY_VERBOSE`/`PROPERTY_DERANDOMIZE` are off on `0`,
  `false`, `off`, `no` (case-insensitive, trimmed), not only on `''`/`0`;
  `GenerationExhausted` is `GenerationExhaustedException`; `auto()` no
  longer claims to require core `^0.5`. `EnvironmentParityTest` pins the
  off words (skipped under core 0.9, where only `''`/`0` are off).
- `psalm.xml` lists `TestCase::dataName()` next to `TestCase::name()`; the
  comment records that Psalm 6.17 resolves neither call from inside the
  trait, so both entries document the boundary rather than silence anything.

## 0.8.0 — 2026-09-10

- Added `PropertyCheck::throws(string $exceptionClass)`: the property-level
  replacement for `expectException()`, which cannot see a throw from inside a
  `check()` closure. Every trial must throw the class (a subclass matches); a
  trial that returns normally fails with "Expected \<class\> to be thrown, but
  it was not" and shrinks like any other counterexample, a foreign class is
  that trial's failure. Skips and `Assume::that()` discards keep their own
  meaning. A class string that is not a `Throwable` is rejected with
  `InvalidArgumentException` at the `throws()` call.
- Accepts `rasuvaeff/property-testing-core` `^0.10` alongside `^0.9`.
- Documentation catches up with core 0.9 and 0.10, from the family's 1.0
  review: a partly skipped property spends a **separate** budget rather than
  counting against `maxDiscards` (README ×2), and `maxDiscards()` caps both
  budgets when set while leaving them different when unset (fluent-chain table
  ×2). `output()` leaves that table — the section below it already calls the
  method `@internal`, and a public table listing it contradicted that inside
  one document.
- `AGENTS.md` says four types rather than three (`PhpUnitTrialExecutor` has
  shipped since 0.6.0), names that executor rather than core's
  `CallableTrialExecutor` as the one running the closure, and no longer routes
  the corpus through `CorpusFromEnv::resolve()` — a class this package removed
  in 0.6.0.
- `psalm.xml` no longer enables `ext-redis` and `composer-require-checker.json`
  no longer whitelists `Predis\Client` and `Redis`. Both were left over from
  0.6, when this package parsed the DSN itself; neither `src/` file has
  mentioned Redis since. `.gitattributes` drops `export-ignore` entries for
  `/docs`, `/benchmarks` and `/ROADMAP.md`, none of which exist here.
- `composer rector` is green again: the unused variable in
  `EnvironmentParityTest`'s `catch` is gone, as `RemoveUnusedVariableInCatchRector`
  asks. It had been red since that test was written, which is `composer
  release-check` red — `composer build` does not run rector.

## 0.7.1 — 2026-09-05

- Requires `rasuvaeff/property-testing-core` `^0.9`, where an environmental skip
  no longer spends the discard budget and is counted apart from discards. The
  excessive-discard warning this adapter prints therefore stops firing on a
  machine that was merely missing a dependency, without a change here. `^0.8` is
  not kept alongside: the family moves together, and an adapter accepting both
  is what left `-names` unable to install beside one.

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
