# AGENTS.md — property-testing-phpunit

Guidance for AI agents working on this package. Read before changing code.

## What this is

The PHPUnit adapter of the property-testing family — a thin layer over
`rasuvaeff/property-testing-core`. It ships three classes:

- `Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting` — the trait a `TestCase`
  mixes in; `final protected forAll(array $generators): PropertyCheck` is the
  single entry point;
- `Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck` — the fluent builder:
  resolves the chain and the environment into a core
  `PropertyDefinition`/`PropertyConfig`/`Corpus`, executes the closure through
  `CallableTrialExecutor`, and maps the structured `PropertyResult` onto
  PHPUnit — a pass registers one assertion via
  `TestCase::addToAssertionCount()`, every failing outcome becomes one
  `AssertionFailedError` with the engine failure as `previous`. It also prints
  the distribution report and the >90%-discard warning;
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
composer config repositories.core '{"type":"path","url":"../property-testing-core","options":{"versions":{"rasuvaeff/property-testing-core":"0.1.0"}}}'
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
| `PROPERTY_VERBOSE` | Always | Any value except `''` and `'0'` enables | Attaches `VerboseListener`: every run's arguments/draws and each accepted shrink step | n/a (falsy values disable) |
| `PROPERTY_DB` | Always (`false`/`''` = off, nothing written) | Directory path (created on demand) | Regression corpus via `FilesystemCorpus::fromEnv()`: record on falsification, replay before the random phase, prune on green replay. An explicit `seed()` disables replay for that property | n/a |

`maxDiscards` has no env override: unset means `runs * 10`.

Note the asymmetry the tests pin: an **explicit `seed()`** disables corpus
replay (`PropertyDefinition::$replayRegressions = false`), the **env**
`PROPERTY_SEED` does not — this cannot be derived from `config->seed` alone.

## Invariants & gotchas

- **PHPUnit marks nearly its whole surface `@internal`.** The `psalm.xml`
  `<issueHandlers>` block allows exactly two references:
  `PHPUnit\Framework\AssertionFailedError` (the documented type a third-party
  integration throws to report a FAILURE — an arbitrary exception would
  surface as an ERROR) and `TestCase::addToAssertionCount()` (the only way a
  passing property is an assertion rather than a risky test). Do not widen
  that list casually, and never add `@psalm-suppress` in code.
- **`forAll()` reads the calling test method's name via `debug_backtrace`** —
  it becomes the property id (`Class::method`) that keys events and the
  regression corpus. The trait method must therefore be called directly from
  the test method, never through an intermediate helper, and it stays
  `final protected`.
- **Infection runs through PHPUnit** — `infection.json5` sets no
  `testFramework` (default is phpunit), `minMsi` 85. No
  `testo/bridge-infection` here.
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
