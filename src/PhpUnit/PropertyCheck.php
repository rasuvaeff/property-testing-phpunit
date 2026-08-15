<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\PropertyId;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\EdgeCases;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Rasuvaeff\PropertyTesting\Runner\TimeBudgetExceeded;

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
    /**
     * Warn when more than this fraction of runs is discarded via
     * {@see \Rasuvaeff\PropertyTesting\Assume::that()}.
     */
    private const float SKIP_RATE_WARNING_THRESHOLD = 0.9;

    /**
     * Phase names accepted by `PROPERTY_PHASES`, lowercase. Spelled out rather
     * than derived from the enum: the variable is a public contract shared
     * with the Testo adapter, and it must not start accepting a new spelling
     * merely because a case was renamed upstream.
     *
     * @var array<string, Phase>
     */
    private const array PHASES_BY_NAME = [
        'examples' => Phase::Examples,
        'corpus' => Phase::Corpus,
        'random' => Phase::Random,
        'shrink' => Phase::Shrink,
    ];

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
    private ?bool $derandomize = null;

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

    /**
     * @param array<string, ArbitraryInterface> $generators
     */
    public function __construct(
        private readonly TestCase $testCase,
        private string $id,
        private string $name,
        private readonly array $generators,
    ) {}

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

        return $this;
    }

    /**
     * Number of successful checks to complete; discarded runs do not count.
     */
    public function runs(int $runs): self
    {
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
        $this->maxShrinks = $maxShrinks;

        return $this;
    }

    /**
     * Maximum discarded runs before the property gives up.
     */
    public function maxDiscards(int $maxDiscards): self
    {
        $this->maxDiscards = $maxDiscards;

        return $this;
    }

    /**
     * Wall-clock deadline for a single run, in milliseconds.
     */
    public function timeoutMs(int $timeoutMs): self
    {
        $this->timeoutMs = $timeoutMs;

        return $this;
    }

    /**
     * Wall-clock budget for the whole random phase, in milliseconds.
     */
    public function budgetMs(int $budgetMs): self
    {
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
     * Replays the shrink descent of an earlier failure, as reported by
     * `CounterExample::$path`, instead of searching for it again. It needs the
     * seed of the run that produced it — the steps mean nothing against
     * another one.
     */
    public function path(string $path): self
    {
        $this->path = $path;

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
     */
    public function output($stdout, $stderr): self
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;

        return $this;
    }

    /**
     * Runs the property. The closure's parameter names select the generators.
     *
     * @param \Closure(mixed...): void $property
     */
    public function check(\Closure $property): void
    {
        $parameterNames = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionFunction($property))->getParameters(),
        );

        $definition = new PropertyDefinition(
            id: $this->id,
            name: $this->name,
            generators: $this->generators,
            parameterNames: $parameterNames,
            config: new PropertyConfig(
                runs: $this->envRuns() ?? $this->runs ?? 100,
                seed: $this->seed ?? $this->envSeed(),
                maxShrinks: $this->maxShrinks,
                maxDiscards: $this->maxDiscards,
                timeoutMs: $this->timeoutMs,
                budgetMs: $this->budgetMs,
                shrink: $this->shrink,
                shrinkBudgetMs: $this->shrinkBudgetMs,
                // The environment dials the suite; the code pins the property.
                // A phase list is the CI gate knob, so the variable wins over
                // the setter — while a path, like a seed, is a replay of one
                // specific failure and yields to the one written down.
                phases: $this->envPhases() ?? $this->phases,
                derandomize: $this->envDerandomize() ?? $this->derandomize ?? false,
                path: $this->path ?? $this->envPath(),
                edgeCases: $this->envEdgeCases() ?? $this->edgeCases ?? EdgeCases::Mixin,
            ),
            examples: $this->examples,
            replayRegressions: $this->seed === null,
        );

        $this->warnOnUnstableId();

        $listeners = $this->listeners;

        if ($this->envVerbose()) {
            $listeners[] = new VerboseListener($this->stdout);
        }

        $result = (new PropertyRunner())->run(
            $definition,
            new CallableTrialExecutor($property),
            $listeners,
            FilesystemCorpus::fromEnv(),
        );

        $statistics = match (true) {
            $result instanceof Passed => $result->statistics,
            $result instanceof GaveUp => $result->statistics,
            $result instanceof CoverageFailed => $result->statistics,
            $result instanceof TimeBudgetExceeded => $result->statistics,
            default => null,
        };

        if ($statistics instanceof RunStatistics) {
            $this->reportClassifications($statistics);
            $this->warnOnExcessiveSkips($statistics);
        }

        $failure = $result->failure();

        if (!$failure instanceof \Throwable) {
            $this->testCase->addToAssertionCount(1);

            return;
        }

        throw new AssertionFailedError($failure->getMessage(), 0, $failure);
    }

    /**
     * Print the share of (passing) runs that hit each
     * {@see \Rasuvaeff\PropertyTesting\Classify} label.
     */
    private function reportClassifications(RunStatistics $statistics): void
    {
        $classifications = $statistics->classifications;
        $checks = $statistics->checks;

        if ($classifications === [] || $checks <= 0) {
            return;
        }

        arsort($classifications);

        $parts = [];
        foreach ($classifications as $label => $count) {
            $parts[] = sprintf(
                '%s %d%% (%d/%d)',
                $label,
                (int) round(((float) $count / (float) $checks) * 100.0),
                $count,
                $checks,
            );
        }

        fwrite($this->stdout, sprintf('Property "%s" distribution: %s', $this->name, implode(', ', $parts)) . "\n");
    }

    /**
     * An id derived from a closure keys the corpus by something that moves —
     * the engine names the problem, this prints it. Same channel as the
     * excessive-discard warning, and impossible to hit once {@see id()} has
     * been called.
     */
    private function warnOnUnstableId(): void
    {
        $warning = PropertyId::unstableWarning($this->id);

        if ($warning === null) {
            return;
        }

        fwrite($this->stderr, $warning . "\n");
    }

    private function warnOnExcessiveSkips(RunStatistics $statistics): void
    {
        $skips = $statistics->discards;
        $attempts = $statistics->attempts;

        if ($attempts <= 0 || ($skips / $attempts) <= self::SKIP_RATE_WARNING_THRESHOLD) {
            return;
        }

        fwrite($this->stderr, sprintf(
            'Property "%s" discarded %d of %d attempt(s) (%d%%); consider narrowing the generators',
            $this->name,
            $skips,
            $attempts,
            (int) round(((float) $skips / (float) $attempts) * 100.0),
        ) . "\n");
    }

    /**
     * `PROPERTY_PHASES` selects the stages for the whole suite: a
     * comma-separated list of phase names, case-insensitive
     * (`examples,corpus` is the fast pull-request gate). An unknown name is an
     * error rather than a skipped stage — a run that silently performed fewer
     * stages would report green having checked less.
     *
     * @return ?list<Phase>
     */
    private function envPhases(): ?array
    {
        $env = getenv('PROPERTY_PHASES');

        if ($env === false || $env === '') {
            return null;
        }

        $phases = [];

        foreach (explode(',', $env) as $name) {
            $trimmed = trim($name);
            $phase = self::PHASES_BY_NAME[strtolower($trimmed)] ?? null;

            if ($phase === null) {
                throw new \InvalidArgumentException(sprintf(
                    'PROPERTY_PHASES must be a comma-separated list of %s, got "%s"',
                    implode(', ', array_keys(self::PHASES_BY_NAME)),
                    $trimmed,
                ));
            }

            $phases[] = $phase;
        }

        return $phases;
    }

    /**
     * `PROPERTY_EDGE_CASES` selects the numeric boundary bias for the whole
     * suite: `mixin` (the default) or `none`, case-insensitive. It overrides
     * the chain, like every other CI-facing knob, and an unknown value is an
     * error rather than a silent fallback — a suite that quietly kept the bias
     * it was told to drop would spend the discard budget it was trying to save.
     */
    private function envEdgeCases(): ?EdgeCases
    {
        $env = getenv('PROPERTY_EDGE_CASES');

        if ($env === false || $env === '') {
            return null;
        }

        return match (strtolower(trim($env))) {
            'mixin' => EdgeCases::Mixin,
            'none' => EdgeCases::None,
            default => throw new \InvalidArgumentException(sprintf(
                'PROPERTY_EDGE_CASES must be one of mixin, none, got "%s"',
                trim($env),
            )),
        };
    }

    /**
     * `PROPERTY_DERANDOMIZE` (any value except '' and '0') derives every
     * unset seed from the property id, which makes a whole suite reproducible
     * without editing a line of it.
     */
    private function envDerandomize(): ?bool
    {
        $env = getenv('PROPERTY_DERANDOMIZE');

        if ($env === false || $env === '') {
            return null;
        }

        return $env !== '0';
    }

    /**
     * `PROPERTY_PATH` replays a recorded shrink descent. It is one failure's
     * replay, so an explicit {@see path()} wins over it, exactly as an
     * explicit {@see seed()} wins over `PROPERTY_SEED` — and like a path
     * written in code it needs the seed that produced it.
     */
    private function envPath(): ?string
    {
        $env = getenv('PROPERTY_PATH');

        if ($env === false || $env === '') {
            return null;
        }

        return $env;
    }

    private function envRuns(): ?int
    {
        $env = getenv('PROPERTY_RUNS');

        if ($env === false || $env === '') {
            return null;
        }

        if (preg_match('/^\d+\z/', $env) !== 1 || (int) $env < 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_RUNS must be a positive integer, got "%s"', $env));
        }

        return (int) $env;
    }

    private function envSeed(): ?int
    {
        $env = getenv('PROPERTY_SEED');

        if ($env === false || $env === '') {
            return null;
        }

        if (preg_match('/^-?\d+\z/', $env) !== 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_SEED must be an integer, got "%s"', $env));
        }

        return (int) $env;
    }

    private function envVerbose(): bool
    {
        $env = getenv('PROPERTY_VERBOSE');

        return !in_array($env, [false, '', '0'], strict: true);
    }
}
