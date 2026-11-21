<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PropertyId;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\Clock;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\CorpusFactory;
use Rasuvaeff\PropertyTesting\Runner\DiagnosticLevel;
use Rasuvaeff\PropertyTesting\Runner\DiagnosticLines;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\Environment;
use Rasuvaeff\PropertyTesting\Runner\FailOnTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;

/**
 * Fluent builder mapping the engine's structured PropertyResult onto PHPUnit:
 * a pass counts one assertion, every failing outcome surfaces as one
 * {@see AssertionFailedError} carrying the engine failure as previous — the
 * message is the engine's own (seed, original and shrunk arguments, shrink
 * statistics included).
 *
 * Environment parity with the Testo adapter: `PROPERTY_RUNS` overrides every
 * run count, `PROPERTY_SEED` seeds unseeded properties (an explicit
 * {@see seed()} wins), `PROPERTY_VERBOSE` traces every run, and `PROPERTY_DB`
 * enables the regression corpus — an explicit seed() disables replay, exactly
 * like an attribute seed does under Testo. An `Assume::that()` discard is a
 * discarded run inside the property, never a skipped PHPUnit test.
 *
 * @api
 */
final class PropertyCheck
{
    private ?int $runs = null;
    private ?int $seed = null;
    private ?int $maxShrinks = null;
    private ?int $maxDiscards = null;
    private ?int $timeoutMs = null;
    private ?int $budgetMs = null;
    private ?ShrinkMode $shrink = null;
    private ?int $shrinkBudgetMs = null;
    private ?string $path = null;
    private ?EdgeCases $edgeCases = null;

    private ?bool $exhaustive = null;

    private int $exhaustiveBudget = 10_000;

    private int $flakyReplays = 2;

    private ?int $searchRuns = null;
    private ?bool $derandomize = null;
    private bool $auto = false;
    private int $failOn = 0;
    private ?string $expectedExceptionClass = null;

    /**
     * Ids already warned about in this process: one line per unstable id, not
     * one per check().
     *
     * @var array<string, string>
     */
    private static array $warnedIds = [];

    /** @var ?list<Phase> */
    private ?array $phases = null;

    /** @var list<list<mixed>> */
    private array $examples = [];

    /** @var list<PropertyListener> */
    private array $listeners = [];

    /** @var resource */
    private $stdout = STDOUT;

    /** @var resource */
    private $stderr = STDERR;

    private ?Clock $clock = null;

    /**
     * @param array<array-key, mixed> $generators The {@see PropertyTesting::forAll()} map as
     *        written; {@see check()} rejects anything in it that is not an
     *        {@see ArbitraryInterface} keyed by a parameter name.
     *
     * @internal Constructed by {@see PropertyTesting::forAll()}.
     */
    public function __construct(
        private readonly TestCase $testCase,
        private string $id,
        private string $name,
        private readonly array $generators,
        private bool $idDerivedIndirectly,
    ) {}

    /**
     * The id the corpus is keyed by, as currently resolved.
     *
     * @internal For this package's own tests.
     */
    public function currentId(): string
    {
        return $this->id;
    }

    /**
     * Names the property, replacing the id derived from the calling method.
     *
     * The id keys the regression corpus entry and every event, so it has to
     * name the same property tomorrow. Derived from the caller it does — for a
     * test method. From a **closure** it cannot: PHP 8.3 calls every closure of
     * a class `{closure}`, so two properties in one file share a corpus key and
     * overwrite each other's counterexample, and from 8.4 the name carries a
     * line number that an edit above shifts, orphaning yesterday's entry.
     * Neither throws — the corpus simply stops replaying the failure it exists
     * to replay. Pest's `it()`/`test()` bodies are the common case.
     *
     * The name given here is used verbatim, and it is also the property's
     * display name, so one string identifies it in the corpus, in the events
     * and in the printed output.
     */
    public function id(string $id): self
    {
        $this->id = $id;
        $this->name = $id;
        // Pinned explicitly: the derivation no longer matters, so drop the
        // indirect-derivation warning.
        $this->idDerivedIndirectly = false;

        return $this;
    }

    /**
     * The exception class every trial must throw. A trial that throws it
     * passes; one that throws anything else, or returns normally, fails and
     * shrinks like any other counterexample.
     *
     * This is the property-level replacement for `expectException()`, which
     * cannot work inside a `check()` closure: the executor observes every
     * throw before PHPUnit's own expectation mechanism does. A failed
     * assertion is never the expected throw, and a class it is an instance
     * of (`\Exception`, `\Throwable`) is refused here. A skip
     * (`markTestSkipped()`, `markTestIncomplete()`) and an
     * `Assume::that()` discard are still what they are — the environment's
     * verdict about the run, never a pass earned by throwing.
     *
     * @param non-empty-string $exceptionClass
     */
    public function throws(string $exceptionClass): self
    {
        if (!is_a($exceptionClass, \Throwable::class, allow_string: true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid expected exception class "%s": not a Throwable',
                $exceptionClass,
            ));
        }
        // PHPUnit's assertion failure is an `\Exception`: a class it is an
        // instance of would match a failed `assertSame()` and pass a
        // falsified body (#54).
        if (is_a(AssertionFailedError::class, $exceptionClass, allow_string: true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid expected exception class "%s": a failed assertion is an instance of it — a falsified body would pass; name the exception the body throws',
                $exceptionClass,
            ));
        }

        $this->expectedExceptionClass = $exceptionClass;

        return $this;
    }

    /**
      * Number of successful checks to complete; discarded runs do not count.
      */
    public function runs(int $runs): self
    {
        $this->assertAtLeast('runs', $runs, 1);
        $this->runs = $runs;

        return $this;
    }

    /**
     * Pins the random phase's seed. A pinned seed also disables regression
     * replay, so the pinned reproducibility wins over the corpus.
     */
    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * Cap on accepted shrink steps; 0 disables shrinking.
     */
    public function maxShrinks(int $maxShrinks): self
    {
        $this->assertAtLeast('maxShrinks', $maxShrinks, 0);
        $this->maxShrinks = $maxShrinks;

        return $this;
    }

    /**
     * Maximum discarded runs before the property gives up.
     */
    public function maxDiscards(int $maxDiscards): self
    {
        $this->assertAtLeast('maxDiscards', $maxDiscards, 0);
        $this->maxDiscards = $maxDiscards;

        return $this;
    }

    /**
     * Wall-clock deadline for a single run, in milliseconds.
     */
    public function timeoutMs(int $timeoutMs): self
    {
        $this->assertAtLeast('timeoutMs', $timeoutMs, 1);
        $this->timeoutMs = $timeoutMs;

        return $this;
    }

    /**
     * Wall-clock budget for the whole random phase, in milliseconds.
     */
    public function budgetMs(int $budgetMs): self
    {
        $this->assertAtLeast('budgetMs', $budgetMs, 1);
        $this->budgetMs = $budgetMs;

        return $this;
    }

    /**
     * How hard to minimise a counterexample: {@see ShrinkMode::Full} (the
     * default), {@see ShrinkMode::Off} to report the input as generated, or
     * {@see ShrinkMode::Bounded} together with {@see shrinkBudgetMs()}.
     */
    public function shrink(ShrinkMode $shrink): self
    {
        $this->shrink = $shrink;

        return $this;
    }

    /**
     * Wall-clock budget for the shrink descent, in milliseconds — the one knob
     * here that costs determinism: how far the descent gets depends on how
     * long the body takes, so the same seed can minimise differently on a
     * fast and a slow machine. It answers "the descent hung", not "reproduce
     * this exactly".
     */
    public function shrinkBudgetMs(int $shrinkBudgetMs): self
    {
        $this->assertAtLeast('shrinkBudgetMs', $shrinkBudgetMs, 1);
        $this->shrinkBudgetMs = $shrinkBudgetMs;

        return $this;
    }

    /**
     * Which stages this run performs, in run order — a subset trades coverage
     * for time on purpose (replaying only the examples and the corpus turns a
     * minutes-long suite into a seconds-long pull-request gate).
     *
     * @param list<Phase> $phases
     */
    public function phases(array $phases): self
    {
        $this->phases = $phases;

        return $this;
    }

    /**
     * Derives an unset seed from the property id instead of drawing it at
     * random, so the same property on the same code always selects the same
     * inputs. An explicit {@see seed()} still wins.
     */
    public function derandomize(bool $derandomize = true): self
    {
        $this->derandomize = $derandomize;

        return $this;
    }

    /**
     * Whether the numeric generators keep biasing toward their boundary values
     * ({@see EdgeCases::Mixin}, the default) or generate uniformly
     * ({@see EdgeCases::None}).
     *
     * Turn them off when the edges are what this property cannot use — a body
     * discarding `0`, a range end that violates a precondition — so the
     * discard budget stops paying for one run in five.
     */
    public function edgeCases(EdgeCases $edgeCases): self
    {
        $this->edgeCases = $edgeCases;

        return $this;
    }

    /**
     * Walk the whole parameter domain instead of sampling it, when every
     * generator has a finite domain (`Enumerable`) and the product fits
     * {@see exhaustiveBudget()}; otherwise the phase samples and a warning
     * says why. {@see runs()} is ignored when it walks. `PROPERTY_EXHAUSTIVE`
     * turns it on for the whole suite.
     */
    public function exhaustive(bool $exhaustive = true): self
    {
        $this->exhaustive = $exhaustive;

        return $this;
    }

    /**
     * The largest domain {@see exhaustive()} walks; 10 000 by default.
     */
    public function exhaustiveBudget(int $exhaustiveBudget): self
    {
        $this->assertAtLeast('exhaustiveBudget', $exhaustiveBudget, 1);
        $this->exhaustiveBudget = $exhaustiveBudget;

        return $this;
    }

    /**
     * Re-executions of the minimised counterexample after the descent — one
     * that passes marks the counterexample flaky, with a `Flaky:` line in the
     * failure. 2 by default; 0 disables the check.
     */
    public function flakyReplays(int $flakyReplays): self
    {
        $this->assertAtLeast('flakyReplays', $flakyReplays, 0);
        $this->flakyReplays = $flakyReplays;

        return $this;
    }

    /**
     * Bodies the targeted search may execute after the random phase, for a
     * body that calls `Target::maximize()`/`minimize()`: the best-scoring
     * inputs are mutated one parameter at a time. 0 (the default) performs no
     * search. `PROPERTY_SEARCH_RUNS` overrides it for the whole suite.
     */
    public function searchRuns(int $searchRuns): self
    {
        $this->assertAtLeast('searchRuns', $searchRuns, 0);
        $this->searchRuns = $searchRuns;

        return $this;
    }

    /**
     * Replays the shrink descent of an earlier failure, as reported by
     * `CounterExample::$path`, instead of searching for it again. It needs the
     * seed of the run that produced it — the steps mean nothing against
     * another one — so {@see check()} refuses a path without a {@see seed()}
     * or a `PROPERTY_SEED`.
     */
    public function path(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * PHP diagnostics of these levels (`E_WARNING`, `E_DEPRECATED`, … as a
     * bitmask) raised while the body runs fail the run with an
     * `\ErrorException` at the place they were raised — a counterexample that
     * shrinks like any other, instead of a line PHPUnit collects for a green
     * suite. 0 (the default) changes nothing. A diagnostic silenced with `@`
     * stays silent; one this does not claim goes on to PHPUnit's own handler.
     * The same knob as the Testo adapter's `#[Property(failOn: …)]`.
     */
    public function failOn(int $levels): self
    {
        $this->assertAtLeast('failOn', $levels, 0);
        $this->failOn = $levels;

        return $this;
    }

    /**
     * Derive a generator from the closure's signature for every parameter the
     * {@see PropertyTesting::forAll()} map does not cover — the `@param` psalm
     * type when the closure has a docblock (`int<1, 300>` beats a bare `int`),
     * the native type otherwise, and an error naming the parameter for
     * anything unreadable. The map becomes the overrides and may be partial —
     * or empty: a fully-typed property needs no map at all.
     *
     * Strictly opt-in, never the default: a bare `int` or `float` derives its
     * full native domain, and only the property's author knows whether that is
     * the intended one. There is deliberately no `PROPERTY_AUTO` environment
     * variable — the environment dials the suite, while this changes what one
     * property's arguments mean.
     */
    public function auto(bool $auto = true): self
    {
        $this->auto = $auto;

        return $this;
    }

    /**
     * Fixed positional argument tuples run before the random phase. A failing
     * example short-circuits and is reported unshrunk — it is already minimal.
     *
     * @param list<list<mixed>> $examples
     */
    public function examples(array $examples): self
    {
        $this->examples = $examples;

        return $this;
    }

    /**
     * Observers of the run's engine events, notified in the given order.
     */
    public function listeners(PropertyListener ...$listeners): self
    {
        $this->listeners = array_values($listeners);

        return $this;
    }

    /**
     * Redirects the distribution report, discard warning and verbose trace —
     * for tests of this adapter itself.
     *
     * @param resource $stdout
     * @param resource $stderr
     *
     * @internal A seam for this package's own tests; the output streams are not a contract.
     */
    public function output($stdout, $stderr): self
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;

        return $this;
    }

    /**
     * Replaces the runner's source of elapsed time — for tests of this adapter
     * itself. The Testo adapter takes the same seam on its interceptor; without
     * it the `timeoutMs` and `budgetMs` branches can only be reached by really
     * waiting, which is not a test.
     *
     * @internal A seam for this package's own tests; the clock is not a contract.
     */
    public function clock(Clock $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * Runs the property. The closure's parameter names select the generators.
     *
     * @param \Closure(mixed...): void $property
     */
    public function check(\Closure $property): void
    {
        $reflection = new \ReflectionFunction($property);
        $parameterNames = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getParameters(),
        );

        $environment = Environment::fromVariables($this->variables());
        $seed = $this->seed ?? $environment->seed;
        $path = $this->path ?? $environment->path;

        if ($path !== null && $seed === null) {
            // The engine refuses this too, but without the property's name —
            // and this is the one chain error that cannot be caught in a
            // setter, because path() and seed() may come in either order.
            throw new \InvalidArgumentException(sprintf(
                'Property "%s": replaying a shrink path requires an explicit seed — seed() or PROPERTY_SEED',
                $this->name,
            ));
        }

        $definition = new PropertyDefinition(
            id: $this->id,
            name: $this->name,
            generators: $this->resolveGenerators($reflection, $parameterNames),
            parameterNames: $parameterNames,
            // The environment dials the suite; the code pins the property
            // (core's Environment::config() applies that table).
            config: $environment->config(
                runs: $this->runs ?? 100,
                seed: $this->seed,
                maxShrinks: $this->maxShrinks,
                maxDiscards: $this->maxDiscards,
                timeoutMs: $this->timeoutMs,
                budgetMs: $this->budgetMs,
                shrink: $this->shrink,
                shrinkBudgetMs: $this->shrinkBudgetMs,
                phases: $this->phases,
                derandomize: $this->derandomize ?? false,
                path: $this->path,
                edgeCases: $this->edgeCases ?? EdgeCases::Mixin,
                exhaustive: $this->exhaustive ?? false,
                exhaustiveBudget: $this->exhaustiveBudget,
                flakyReplays: $this->flakyReplays,
                searchRuns: $this->searchRuns ?? 0,
            ),
            examples: $this->examples,
            replayRegressions: $this->seed === null,
        );

        $corpus = $environment->db === null ? null : CorpusFactory::fromDsn($environment->db);

        if ($corpus instanceof Corpus) {
            // The id is what keys the corpus; without a corpus an unstable one
            // costs nothing, and a warning on every check() would only be noise
            // (under Pest every test is a closure).
            $this->warnOnUnstableId();
        }

        $listeners = $this->listeners;

        if ($environment->verbose) {
            $listeners[] = new VerboseListener($this->stdout);
        }

        $executor = new PhpUnitTrialExecutor($property, $this->expectedExceptionClass);
        $result = (new PropertyRunner($this->clock))->run($definition, new FailOnTrialExecutor($executor, $this->failOn), $listeners, $corpus);

        $skip = $executor->everyRunSkippedWith();

        if ($skip instanceof \Throwable) {
            // Every run was skipped or incomplete: that is the test's verdict,
            // rethrown so PHPUnit reports it as such rather than as a property
            // that gave up on its discard budget.
            throw $skip;
        }

        $statistics = RunStatistics::of($result);

        if ($statistics instanceof RunStatistics) {
            // Warnings on stderr, the rest on stdout, in the order core words
            // them — the order the Testo adapter prints them in. Both adapters
            // share one log whenever a suite merges the two streams.
            foreach (DiagnosticLines::of($this->name, $statistics) as $line) {
                $this->diagnose($line->level === DiagnosticLevel::Warning ? $this->stderr : $this->stdout, $line->text);
            }
        }

        // The check is the assertion, whichever way it went: counted before
        // the verdict, so a falsified property whose body asserted nothing is
        // reported once, as a failure, not a second time as risky.
        $this->testCase->addToAssertionCount(1);

        $failure = $result->failure();

        if (!$failure instanceof \Throwable) {
            return;
        }

        throw new AssertionFailedError($failure->getMessage(), 0, $failure);
    }

    /**
     * The raw `PROPERTY_*` values core's {@see Environment} parses: read here,
     * because the engine never reads the process environment.
     *
     * @return array<string, string|false>
     */
    private function variables(): array
    {
        $variables = [];

        foreach (Environment::VARIABLES as $name) {
            $variables[$name] = getenv($name);
        }

        return $variables;
    }

    /**
     * The generators the property runs with: the {@see forAll()} map plus any
     * `#[Generate]` on the closure's parameters, and — under {@see auto()} —
     * every parameter neither covers derived from the closure's signature. The derivation errors are
     * {@see Gen::forParameters()}'s own, naming the function and the parameter.
     *
     * The map is checked here, before the engine sees it, because the engine
     * names neither the key nor the property: a value that is not an
     * {@see ArbitraryInterface} would fail inside the runner as a call on a
     * non-object, and a key that is not a parameter would be ignored — a
     * typoed entry running green in the wrong domain, or under auto silently
     * replaced by a signature-derived generator.
     *
     * @param list<string> $parameterNames
     *
     * @return array<string, ArbitraryInterface>
     */
    private function resolveGenerators(\ReflectionFunction $property, array $parameterNames): array
    {
        $parameters = array_flip($parameterNames);

        /** @var array<string, ArbitraryInterface> $generators */
        $generators = [];

        foreach ($this->generators as $name => $generator) {
            $name = (string) $name;

            if (!$generator instanceof ArbitraryInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Property "%s": forAll() expects array<string, ArbitraryInterface>, got %s for key "%s"',
                    $this->name,
                    get_debug_type($generator),
                    $name,
                ));
            }

            if (!isset($parameters[$name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Property "%s": forAll() covers "%s", which is not a parameter of the property',
                    $this->name,
                    $name,
                ));
            }

            $generators[$name] = $generator;
        }

        // Without auto nothing is derived from the declared types, but a
        // parameter may still carry its generator as #[Generate]: the map and
        // the attributes together have to cover every parameter.
        return Gen::forParameters($property, $generators, derive: $this->auto);
    }

    /**
     * The engine rejects the same value, but its message names no property;
     * a chain of a dozen properties in one class needs to say which one.
     */
    private function assertAtLeast(string $knob, int $value, int $minimum): void
    {
        if ($value < $minimum) {
            throw new \InvalidArgumentException(sprintf(
                'Property "%s": %s must be greater than or equal to %d',
                $this->name,
                $knob,
                $minimum,
            ));
        }
    }

    /**
     * An id derived from a closure keys the corpus by something that moves —
     * the engine names the problem, this prints it. Same channel as the
     * excessive-discard warning, and impossible to hit once {@see id()} has
     * been called.
     */
    private function warnOnUnstableId(): void
    {
        $warning = PropertyId::unstableWarning($this->id) ?? $this->indirectIdWarning();

        if ($warning === null || isset(self::$warnedIds[$this->id])) {
            return;
        }

        self::$warnedIds[$this->id] = $this->id;
        $this->diagnose($this->stderr, $warning);
    }

    /**
     * `forAll()` was called from a helper (or otherwise not the running test
     * method), so its derived id names the helper, not the test — every caller
     * of that helper shares one corpus entry and overwrites the others'
     * counterexample. `PropertyId::unstableWarning()` misses this because the id
     * is a stable-looking `Class::method`, just the wrong method.
     */
    private function indirectIdWarning(): ?string
    {
        if (!$this->idDerivedIndirectly) {
            return null;
        }

        return sprintf(
            'Property id "%s" was derived from a helper, not the test method that ran, so every call site shares one corpus entry: call forAll() directly in the test method or pass an explicit property id with ->id()',
            $this->id,
        );
    }

    /**
     * One diagnostic line on its own line. PHPUnit is printing its progress
     * dots on the same terminal while a property runs, so a line that starts
     * where the cursor happens to be is glued to the end of `....F..`; the
     * leading newline is what keeps it readable. The Testo adapter has no
     * progress row and prints without it — the one place the two adapters'
     * output differs by design (see AGENTS.md).
     *
     * @param resource $stream
     */
    private function diagnose($stream, string $line): void
    {
        fwrite($stream, "\n" . $line . "\n");
    }
}
