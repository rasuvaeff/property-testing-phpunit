<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\CoverageFailed;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\GaveUp;
use Rasuvaeff\PropertyTesting\Runner\Passed;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;
use Rasuvaeff\PropertyTesting\Runner\RunStatistics;
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

    private ?int $runs = null;
    private ?int $seed = null;
    private ?int $maxShrinks = null;
    private ?int $maxDiscards = null;
    private ?int $timeoutMs = null;
    private ?int $budgetMs = null;

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
        private readonly string $id,
        private readonly string $name,
        private readonly array $generators,
    ) {}

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
            ),
            examples: $this->examples,
            replayRegressions: $this->seed === null,
        );

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
