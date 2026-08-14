# rasuvaeff/property-testing-phpunit

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-phpunit/v)](https://packagist.org/packages/rasuvaeff/property-testing-phpunit)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-phpunit/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-phpunit)
[![Build](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-phpunit/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-phpunit/php)](https://packagist.org/packages/rasuvaeff/property-testing-phpunit)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[Русская версия](README.ru.md)

PHPUnit adapter for the
[property-testing engine](https://github.com/rasuvaeff/property-testing-core):
a `PropertyTesting` trait with a fluent `forAll()->check()` API over the
framework-agnostic runner. Generate hundreds of random inputs per test, find
the failing one, and shrink it to a minimal counterexample you can actually
read — inside an ordinary PHPUnit `TestCase`.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Part of the property-testing family

| Package | Use it when |
|---|---|
| [`rasuvaeff/property-testing-core`](https://github.com/rasuvaeff/property-testing-core) | You drive the engine yourself: a custom harness, CI guard, CLI checker, or another framework adapter |
| [`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo) | You test with [Testo](https://github.com/php-testo/testo) — drop-in replacement for the frozen `rasuvaeff/property-testing` with the same `#[Property]` attribute |
| **`rasuvaeff/property-testing-phpunit`** (this package) | You test with PHPUnit — the `PropertyTesting` trait with the fluent `forAll()->check()` API |

## Requirements

- PHP 8.3+
- [`phpunit/phpunit`](https://packagist.org/packages/phpunit/phpunit) `^11.5 || ^12.0 || ^13.0`
- [`rasuvaeff/property-testing-core`](https://packagist.org/packages/rasuvaeff/property-testing-core) `^0.1`

PHPUnit 13 requires PHP 8.4.1 or newer. On PHP 8.3, Composer resolves a
compatible PHPUnit 11 or 12 release.

## Installation

```bash
composer require --dev rasuvaeff/property-testing-phpunit
```

No configuration is needed: mix the trait into a `TestCase` and call `forAll()`
from a test method.

## Usage

Map each property-body parameter to a generator, configure the run with the
fluent chain, and hand the property to `check()`. The engine generates random
arguments, runs the closure the configured number of times, and on the first
failure shrinks the counterexample to a minimal one:

```php
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;

final class SortPropertyTest extends TestCase
{
    use PropertyTesting;

    public function testSortIsIdempotent(): void
    {
        $this->forAll(['values' => Gen::arrayOf(Gen::int())])
            ->runs(300)
            ->check(static function (array $values): void {
                sort($values);
                $once = $values;
                sort($values);

                self::assertSame($once, $values);
            });
    }
}
```

The closure's **parameter names select the generators**, exactly like a
`#[Property]` method signature does under the Testo adapter. On failure the
test fails with the engine's message:

```
Property falsified after 12 successful run(s); seed=7382910
  Original: values=[20, 82, 44, 43, 29, 47, 29, 0, … +4 more]
  Shrunk:   values=[0, 0, 0, 0, 0, 0] (7 shrink step(s), 29 trial(s))
  Changed:  values=[20, 82, 44, …] -> [0, 0, 0, 0, 0, 0]
```

Reproduce the exact run by pinning the reported seed: `->seed(7382910)`.

### The fluent chain

`forAll()` returns a `PropertyCheck`; every setter returns it for chaining, and
`check()` runs the property.

| Method | Meaning |
|---|---|
| `id(string)` | Names the property, replacing the id derived from the calling method. Keys the corpus and the events, and is the display name — required when `forAll()` runs inside a closure |
| `runs(int)` | Successful checks to complete (default 100). Discarded runs do not count |
| `seed(int)` | Pins the random phase for reproduction. Also disables corpus replay — the pinned run wins |
| `maxShrinks(int)` | Cap on accepted shrink steps; `0` disables shrinking |
| `maxDiscards(int)` | Discard budget before the property fails with `GaveUpException`; default `runs * 10` |
| `timeoutMs(int)` | Wall-clock deadline for a single run — exceeding it fails with `DeadlineExceededException` |
| `budgetMs(int)` | Wall-clock budget for the whole random phase — running out fails with `TimeBudgetExceededException` |
| `examples(array)` | Fixed positional argument tuples run **before** the random phase; a failing example short-circuits, unshrunk |
| `listeners(...)` | `PropertyListener` observers of the engine's lifecycle events |
| `shrink(ShrinkMode)` | How hard to minimise: `Full` (default), `Off` (report the input as generated), `Bounded` with a budget |
| `shrinkBudgetMs(int)` | Wall-clock budget for the descent — the one knob that costs determinism, since how far it gets depends on how long the body takes |
| `phases(array)` | Stages to perform (`Phase::Examples`, `Corpus`, `Random`, `Shrink`) — a subset trades coverage for time on purpose |
| `derandomize(bool)` | Derives an unset seed from the property id instead of drawing one; an explicit `seed()` still wins |
| `path(string)` | Replays a recorded shrink descent instead of searching for it; needs the seed that produced it |
| `output($stdout, $stderr)` | Redirects the distribution report, discard warning and verbose trace (used by this package's own tests) |

### Naming a property (`id()`)

`id()` names the property:

```php
$this->forAll(['values' => Gen::arrayOf(Gen::int())])
    ->id('sort::idempotent')
    ->runs(300)
    ->check(static function (array $values): void { /* … */ });
```

Left out, the name is derived from the calling method — which is right for a
test method and wrong for a **closure**, because PHP has no stable name for
one. On PHP 8.3 every closure of a class is `{closure}`, so two properties in
one file share a corpus key and overwrite each other's recorded
counterexample; from 8.4 the name is `{closure:/path/File.php:19}`, so
inserting a line above the property orphans yesterday's entry. Nothing throws
— the corpus just stops replaying the failure it exists to replay.

So call `id()` whenever `forAll()` runs inside a closure rather than directly
in a test method (Pest's `it()` and `test()` are the common case). The id keys
the regression corpus and every event, and it also becomes the display name, so
one string identifies the property in the corpus, in the events and in the
printed output.

### How results map onto PHPUnit

- A **pass** counts one assertion — the test is never marked risky.
- Every **failing outcome** (falsified, gave up, unmet coverage, deadline,
  budget, generation failure, failing example, replayed regression) surfaces
  as **one `AssertionFailedError`** whose message is the engine's own — seed,
  original and shrunk arguments, shrink statistics — and whose `previous` is
  the engine exception (`PropertyViolationException`, `GaveUpException`,
  `RegressionViolationException`, …).
- `Assume::that()` is a **discarded run inside the property**, retried by the
  engine — never a skipped PHPUnit test.

### Environment overrides

Byte-for-byte parity with the Testo adapter — one contract across adapters:

| Variable | Effect |
|---|---|
| `PROPERTY_RUNS` | Positive integer that overrides every property's run count (dial runs up in CI) |
| `PROPERTY_SEED` | Integer seed for any property without an explicit `seed()` (replay a whole suite). An explicit `seed()` still wins |
| `PROPERTY_VERBOSE` | Any value except `''`/`'0'` logs every run's generated arguments and each accepted shrink step |
| `PROPERTY_DB` | Directory path enabling the regression corpus. Unset means off, nothing is written |
| `PROPERTY_PHASES` | Comma-separated stage list (`examples,corpus,random,shrink`, case-insensitive) that overrides `phases()` — an unknown name throws rather than skipping a stage. `examples,corpus` is the fast pull-request gate |
| `PROPERTY_DERANDOMIZE` | Any value except `''`/`'0'` derives every unset seed from the property id, making a whole suite reproducible without editing it |
| `PROPERTY_PATH` | A recorded shrink descent (`CounterExample::$path`) replayed instead of searched for. Needs the seed that produced it; an explicit `path()` wins |

The corpus format is exactly the one `rasuvaeff/property-testing` 2.8 wrote —
a corpus recorded under Testo (or under 2.x) replays here and vice versa. On
falsification the minimal input is recorded; the next run replays recorded
failures **first** (unless `seed()` pins the property) and reports a still-red
one as a `RegressionViolationException`; a green one is pruned.

### Distribution and discards

`Classify::label()`/`when()`/`cover()` work inside the property body. When a
classified property passes, the adapter prints the label distribution:

```
Property "testSortKeepsEveryElement" distribution: long 39% (77/200), short 61% (123/200)
```

A property that discards more than 90% of its attempts (via `Assume::that()`)
gets a warning suggesting narrower generators.

### Why no `#[Property]` attribute?

PHPUnit's public extension/event API observes test execution but offers no
stable contract for intercepting and re-invoking a test method many times —
which is exactly what a property attribute must do. This adapter deliberately
does not depend on PHPUnit internals; the fluent API needs only the documented
surface. An attribute may appear later, only if it can be built on the
documented extension API of the supported majors.

### Generators

The full generator catalog (`Gen::int()` … `Gen::subset()`, `Gen::regex()`,
`Gen::commands()`, `Gen::draw()`, `Shrinkable`, writing your own
`ArbitraryInterface`, stateful/model-based testing) is the engine's API,
documented in the
[core README](https://github.com/rasuvaeff/property-testing-core#generators).
Everything there is usable from a `check()` closure as-is.

## Public API of this package

| Type | Role |
|---|---|
| `Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting` | The trait a `TestCase` mixes in; `forAll()` is its single entry point |
| `Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck` | The fluent builder: resolves the chain and the environment into a core `PropertyDefinition`, runs the engine, maps the structured result onto PHPUnit |
| `Rasuvaeff\PropertyTesting\PhpUnit\VerboseListener` | `PROPERTY_VERBOSE` output as an exception-hardened engine listener (internal) |

## Security

Generated values are pseudo-random (seeded MT19937), not cryptographic. Seeds
are not secrets — they are printed in failure output by design. Treat
`PROPERTY_DB` corpus files as test artifacts: they contain generated inputs
verbatim, so do not point the variable at a directory that gets published.

## Examples

See [examples/](examples/) — a complete property-based `TestCase`:

```bash
vendor/bin/phpunit examples/SortPropertyTest.php
```

## Development

```bash
make install     # composer install (Docker)
make build       # validate + normalize + require-checker + cs + psalm + tests
make cs-fix      # apply code style
make mutation    # infection mutation testing
```

Tests run through PHPUnit (`composer test` is `phpunit`), not Testo.

## License

[BSD-3-Clause](LICENSE.md)
