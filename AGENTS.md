# AGENTS.md — property-testing-phpunit

Guidance for AI agents working on this package. Read before changing code.

## What this is

The PHPUnit adapter of the property-testing family — a thin layer over
`rasuvaeff/property-testing-core`. It ships four types:

- `Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting` — the trait a `TestCase`
  mixes in; `final protected forAll(array $generators): PropertyCheck` is the
  single entry point;
- `Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck` — the fluent builder:
  resolves the chain and the environment into a core
  `PropertyDefinition`/`PropertyConfig`/`Corpus`, executes the closure through
  `PhpUnitTrialExecutor` (`@internal`, since 0.6.0), and maps the structured `PropertyResult` onto
  PHPUnit — every outcome registers one assertion via
  `TestCase::addToAssertionCount()` (the check is the assertion, pass or
  fail; only an all-runs-skipped property registers none), every failing
  outcome becomes one `AssertionFailedError` with the engine failure as
  `previous`. It validates the chain at the setters and the `forAll()` map
  at `check()`, naming the property in every message, before the engine's
  nameless refusal would. It also prints the distribution report and the
  >90%-discard warning. `throws(string)` is
  the property-level replacement for `expectException()` (which never sees a
  throw from inside the body): the executor turns a throw of the expected
  class into a pass and a silent return into the failure "Expected \<class\>
  to be thrown, but it was not"; skips and discards keep their own meaning;
- `Rasuvaeff\PropertyTesting\PhpUnit\VerboseListener` — `PROPERTY_VERBOSE`
  output as an exception-hardened engine listener (`@internal`).

Everything algorithmic — generators, shrinking, the runner, the corpus, the
state machine, events — lives in `rasuvaeff/property-testing-core`. Engine
invariants are documented in THAT package's AGENTS.md; do not fix engine
behaviour from here.

This package's own tests run through **PHPUnit, not Testo** (`composer test`
is `phpunit`; the suite lives in `phpunit.xml`).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Preserve environment and corpus parity with the Testo adapter.** The
   `PROPERTY_*` table and the corpus format are one contract across adapters —
   `EnvironmentParityTest` is its definition. A behaviour that diverges from
   `property-testing-testo` (or from frozen 2.8) is a bug even if this
   package's tests still pass.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

`rasuvaeff/property-testing-core` resolves from Packagist — a plain
`composer install` is enough. Only when testing an **unreleased** core change
does the sibling checkout need a temporary path repository (run from the
monorepo root with the whole root mounted, e.g.
`docker run --rm -v "$PWD":/repo -w /repo/property-testing-phpunit composer:2 …`):

```bash
composer config repositories.core '{"type":"path","url":"../property-testing-core","options":{"versions":{"rasuvaeff/property-testing-core":"1.0.0"}}}'
composer update
composer config --unset repositories.core
rm composer.lock
```

Never commit that `repositories` key or a `composer.lock`.

Otherwise, as usual:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.
`composer.lock` is gitignored (library).

## Environment contract

Resolved by `PropertyCheck` (the core `PropertyRunner` never reads the process
environment). Must stay verbatim-equivalent to the Testo adapter's table —
parity is golden rule 3.

| Variable | Read when | Accepts | Effect | Invalid value |
|---|---|---|---|---|
| `PROPERTY_RUNS` | Always (`false`/`''` = unset) | `/^\d+\z/`, `>= 1` | Overrides every property's run count, including `runs()` | `InvalidArgumentException` |
| `PROPERTY_SEED` | Only when `seed()` was not called (explicit seed wins) | `/^-?\d+\z/` | Seeds every unseeded property; unset means a random seed per property | `InvalidArgumentException` |
| `PROPERTY_VERBOSE` | Always | `''` = unset; `0`, `false`, `off`, `no` (case-insensitive, trimmed) = off; anything else enables (core `EnvironmentOverrides::flag()`) | Attaches `VerboseListener`: every run's arguments/draws and each accepted shrink step | n/a (off words disable) |
| `PROPERTY_DB` | Always (`false`/`''` = off, nothing written) | Directory path (created on demand) **or** `redis://host[:port][/db][?prefix=key-prefix]` (`rediss://` = TLS; core `CorpusFactory`) | Regression corpus via core's `CorpusFactory::fromDsn()` (`CorpusFromEnv` was removed in 0.6.0): a path builds a `FilesystemCorpus`, a DSN a `RedisCorpus` (ext-redis preferred, else predis). An explicit `seed()` disables replay for that property | `InvalidArgumentException` — an unusable DSN, or no Redis client installed. Never a silent fall back to the filesystem |
| `PROPERTY_PHASES` | Always (`false`/`''` = unset) | Comma-separated phase names, case-insensitive: `examples`, `corpus`, `random`, `shrink` | Stages of every run, in run order — **overrides** `phases()` | `InvalidArgumentException` naming the accepted values |
| `PROPERTY_DERANDOMIZE` | Always | Same words as `PROPERTY_VERBOSE` | Derives every unset seed from the property id — **overrides** `derandomize()` | n/a (off words disable) |
| `PROPERTY_PATH` | Only when `path()` was not called (explicit path wins) | A recorded `CounterExample::$path` | Replays that shrink descent instead of searching for it; needs the seed of the run that produced it | `check()` rejects a path without a seed (`seed()` or `PROPERTY_SEED`) naming the property; the engine rejects every other path that would be a silent no-op |
| `PROPERTY_EDGE_CASES` | Always (`false`/`''` = unset) | `mixin` or `none`, case-insensitive, trimmed | Numeric boundary bias for every run — **overrides** `edgeCases()` | `InvalidArgumentException` naming the accepted values |

**Diagnostics print in a fixed order: the discard warning first, then the
distribution.** Both adapters emit the same two lines for the same run, and a
log that merges the two streams must not show them in different orders. The
order is pinned here and in the Testo adapter's `AGENTS.md`; changing it means
changing both.

**Three framework asymmetries the parity rule does not cover.**

- *Risky per run.* Testo can mark a single run risky; PHPUnit signals risky at
  the test level, so there is no per-run equivalent to mirror.
  `PropertyCheck` compensates by counting one assertion for every property
  that ran (`$this->testCase->addToAssertionCount(1)`, before the verdict),
  which is what keeps a property whose body asserts nothing out of the risky
  bucket — on a pass, and on a failure, where PHPUnit would otherwise report
  the same test as Failed *and* Risky. A property whose every run was skipped
  rethrows the skip before that line and registers nothing: a skipped test is
  the environment's verdict, not a check. `PhpUnitTrialExecutor` only counts
  the skipped runs; the assertion is the check's, because it is one per
  property, not one per run.
- *A leading newline on every diagnostic line.* PHPUnit prints its progress
  dots on the same terminal while a property runs, so `PropertyCheck::diagnose()`
  writes `"\n" . $line . "\n"`: the distribution, the discard warning and the
  unstable-id warnings all start on a line of their own instead of gluing to
  `....F..`. Testo has no progress row and prints the same lines without it.
  The line *content* stays byte-identical across adapters; only the leading
  `\n` differs, and the exact-string tests in `AdapterDetailsTest` pin both
  newlines. `VerboseListener` is deliberately left alone: a trace is a wall of
  lines already, and double-spacing it would help nobody.
- *Data-set naming.* The corpus id of a data-provider case reads
  `Class::method with data set "0"`, where PHPUnit's own output prints `#0` for
  a numeric key. Deliberate: this string is a corpus key, and respelling it
  would orphan every entry recorded so far. Only the printed name follows
  PHPUnit; the id does not.

The split is deliberate and worth stating: **the environment dials the suite,
the code pins the property.** `PROPERTY_RUNS`, `PROPERTY_PHASES` and
`PROPERTY_DERANDOMIZE` are CI knobs and win over the chain; `PROPERTY_SEED` and
`PROPERTY_PATH` replay one specific failure and yield to what a test wrote
down.

`maxDiscards` has no env override: unset means `runs * 10`.

Note the asymmetry the tests pin: an **explicit `seed()`** disables corpus
replay (`PropertyDefinition::$replayRegressions = false`), the **env**
`PROPERTY_SEED` does not — this cannot be derived from `config->seed` alone.

## Invariants & gotchas

- **PHPUnit marks nearly its whole surface `@internal`.** The `psalm.xml`
  `<issueHandlers>` block allows exactly two boundary points:
  `PHPUnit\Framework\AssertionFailedError` (the documented type a third-party
  integration throws to report a FAILURE — an arbitrary exception would
  surface as an ERROR) and `TestCase::addToAssertionCount()` (the only way a
  property that ran is an assertion rather than a risky test). The first point
  needs two XML entries (`InternalClass` on the class, `InternalMethod` on its
  constructor) — see the comment in `psalm.xml` for the non-obvious part:
  the constructor suppression must name `AssertionFailedError`, not the
  parent `Exception` class that Psalm's own error text names as the
  constructor's declarer. The trait's own two calls, `TestCase::name()` and
  `TestCase::dataName()`, are listed as well, but Psalm 6.17 does not resolve
  `$this` inside the trait to `TestCase` and reports nothing for either with
  or without the entries (verified 2026-09-18) — they document the boundary
  for a Psalm that will. Do not widen this list casually, and never add
  `@psalm-suppress` in code.
- **`forAll()` reads the calling test method's name via `debug_backtrace`** —
  it becomes the property id (`Class::method`) that keys events and the
  regression corpus. The trait method must therefore be called directly from
  the test method, never through an intermediate helper, and it stays
  `final protected`. From a closure there is no stable name to derive
  (`{closure}` on PHP 8.3, `{closure:file:line}` from 8.4), so `PropertyCheck`
  prints `PropertyId::unstableWarning()` on stderr for such an id, and
  `id()` is the fix — it replaces the derived id for the corpus, the events
  and the printed name alike.
- **Infection runs through PHPUnit** — `infection.json5` sets no
  `testFramework` (default is phpunit), `minMsi` 95. No
  `testo/bridge-infection` here.
- **No `benchmarks/` here, deliberately.** The root `AGENTS.md` template
  benchmarks convention (`#[Bench]` + `composer bench` running
  `testo --suite=Benchmarks`) is Testo-specific, and this package has zero
  `testo/*` in `require`/`require-dev` on purpose (drop-in for PHPUnit users
  must not pull Testo transitively). `.php-cs-fixer.php` does not point its
  Finder at `/benchmarks` for the same reason — pointing at a directory that
  will never exist breaks `composer cs`/`build` outright (this shipped once,
  caught by CI). If a CPU-bound hot path in this adapter ever needs
  benchmarking, it needs its own PHPUnit-native harness, not a copy of the
  Testo one.
- **Report lines use a literal `"\n"`, never `PHP_EOL` — in `src/` AND in
  tests.** `PropertyCheck`'s distribution report and discard warning, and
  `VerboseListener`'s trace, are machine-greppable CLI output. `PHP_EOL` is
  `\r\n` on Windows: if the source emits it, plain-LF assertions break; if a
  *test's expected string* builds it (`'...' . PHP_EOL`) while the source
  emits a real `"\n"`, the assertion breaks the other way on Windows only.
  Both directions of this shipped in the same PR, one commit apart, and both
  were caught by the Windows CI job — grep the whole tree for `PHP_EOL`
  before touching this area again, do not fix only `src/`.
- A pinned `seed()` makes falsification deterministic in tests; the corpus
  tests deliberately run unseeded (replay only happens for unseeded
  properties) and rely on a falsification probability that is
  indistinguishable from 1.
- `PropertyCheck::output()` exists so this package's own tests can capture the
  distribution report, discard warning and verbose trace from in-memory
  streams; production code never calls it.
- Code: `declare(strict_types=1)`, `final` classes (`readonly` where state
  allows), `#[\Override]`, explicit types.
- `examples/` is part of the public contract: `examples/SortPropertyTest.php`
  is a real PHPUnit test case (`vendor/bin/phpunit examples/SortPropertyTest.php`).
  Keep it green and update `examples/README.md` when usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
