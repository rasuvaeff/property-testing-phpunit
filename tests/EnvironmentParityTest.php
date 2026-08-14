<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\RecordingListener;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;
use Rasuvaeff\PropertyTesting\Runner\Phase;

/**
 * The environment contract, byte-for-byte the Testo adapter's: PROPERTY_RUNS
 * overrides every run count, PROPERTY_SEED seeds unseeded properties (an
 * explicit seed() wins), PROPERTY_VERBOSE traces runs, PROPERTY_DB records
 * and replays the regression corpus.
 */
#[CoversClass(PropertyCheck::class)]
// Every test here enters through the trait's forAll(), and the id it derives
// (or is handed) is part of what this suite pins — so the trait is covered in
// fact, and saying so is what lets its mutants be judged by these tests.
#[CoversTrait(PropertyTesting::class)]
final class EnvironmentParityTest extends TestCase
{
    use PropertyTesting;

    private string $corpusDir = '';

    /** @var \Closure(): void */
    private \Closure $restoreEnv;

    protected function setUp(): void
    {
        $this->corpusDir = sys_get_temp_dir() . '/prop-phpunit-' . bin2hex(random_bytes(6));
        $this->restoreEnv = Env::isolateProperty();
    }

    protected function tearDown(): void
    {
        ($this->restoreEnv)();

        if (is_dir($this->corpusDir)) {
            foreach (glob($this->corpusDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->corpusDir);
        }
    }

    public function testPropertyRunsOverridesTheConfiguredRunCount(): void
    {
        putenv('PROPERTY_RUNS=7');
        $listener = new RecordingListener();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(100)
            ->seed(1)
            ->listeners($listener)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        self::assertSame(7, $listener->count(RunStarted::class));
    }

    public function testInvalidPropertyRunsIsRejected(): void
    {
        putenv('PROPERTY_RUNS=oops');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PROPERTY_RUNS must be a positive integer, got "oops"');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->check(static function (int $value): void {});
    }

    public function testPropertySeedSeedsAnUnseededProperty(): void
    {
        putenv('PROPERTY_SEED=424242');

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('seed=424242', $failure->getMessage());
        }
    }

    public function testExplicitSeedWinsOverTheEnvironment(): void
    {
        putenv('PROPERTY_SEED=1');

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(42)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('seed=42', $failure->getMessage());
        }
    }

    public function testInvalidPropertySeedIsRejected(): void
    {
        putenv('PROPERTY_SEED=not-a-seed');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PROPERTY_SEED must be an integer, got "not-a-seed"');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->check(static function (int $value): void {});
    }

    public function testVerboseTraceIsWrittenWhenEnabled(): void
    {
        putenv('PROPERTY_VERBOSE=1');
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        \assert(\is_resource($stdout) && \is_resource($stderr));

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(3)
            ->seed(9)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        rewind($stdout);
        $trace = (string) stream_get_contents($stdout);

        self::assertStringContainsString('attempt 1:', $trace);
        self::assertStringContainsString('value=', $trace);
    }

    public function testCorpusRecordsAFalsificationAndReplaysItFirst(): void
    {
        putenv('PROPERTY_DB=' . $this->corpusDir);

        // First run: falsified, recorded.
        try {
            $this->runFalsifiableProperty();

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
        }

        self::assertNotSame([], glob($this->corpusDir . '/*.json') ?: []);

        // Second run: the recorded minimal input replays before the random
        // phase and fails as a regression.
        try {
            $this->runFalsifiableProperty();

            self::fail('The recorded regression should have replayed');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(RegressionViolationException::class, $failure->getPrevious());
        }
    }

    public function testAnExplicitSeedDisablesCorpusReplay(): void
    {
        putenv('PROPERTY_DB=' . $this->corpusDir);

        try {
            $this->runFalsifiableProperty();

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            // Recorded.
        }

        try {
            $this->runFalsifiableProperty(seed: 4242);

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            // A pinned seed reproduces its own run — replay is off, so the
            // failure is a fresh falsification, not a regression.
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
            self::assertStringContainsString('seed=4242', $failure->getMessage());
        }
    }

    public function testAnExplicitIdKeysTheCorpusEntryAndTheEvents(): void
    {
        // The point of naming a property: two calls that share an id share the
        // recorded counterexample, wherever in the file they are written and
        // whatever PHP would have called the closure they run in.
        putenv('PROPERTY_DB=' . $this->corpusDir);
        $listener = new RecordingListener();

        try {
            $this->runFalsifiableProperty(id: 'stack::push-then-pop', listener: $listener);

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
        }

        $started = array_values(array_filter(
            $listener->events,
            static fn(object $event): bool => $event instanceof PropertyStarted,
        ));
        self::assertCount(1, $started);
        self::assertSame('stack::push-then-pop', $started[0]->propertyId);

        // Same id, written as a different call: the corpus replays.
        try {
            $this->runFalsifiableProperty(id: 'stack::push-then-pop');

            self::fail('The recorded regression should have replayed');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(RegressionViolationException::class, $failure->getPrevious());
        }
    }

    public function testADifferentExplicitIdDoesNotInheritTheRecordedCounterexample(): void
    {
        putenv('PROPERTY_DB=' . $this->corpusDir);

        try {
            $this->runFalsifiableProperty(id: 'stack::first');

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
        }

        // A different name is a different property: it falsifies on its own
        // random phase rather than replaying the other one's regression.
        try {
            $this->runFalsifiableProperty(id: 'stack::second');

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
        }
    }

    public function testAnExplicitIdIsAlsoTheNameInPrintedOutput(): void
    {
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->id('queue::drain-order')
            ->runs(20)
            ->output($stdout, STDERR)
            ->check(static function (int $value): void {
                Classify::label($value % 2 === 0 ? 'even' : 'odd');
            });

        rewind($stdout);

        self::assertStringContainsString('Property "queue::drain-order" distribution:', (string) stream_get_contents($stdout));
    }

    public function testPropertyPhasesLimitsTheStagesOfEveryProperty(): void
    {
        // The pull-request gate: examples and corpus only, no random phase. A
        // property that a random draw would falsify therefore passes, having
        // honestly checked less.
        putenv('PROPERTY_PHASES=examples,corpus');

        $this->forAll(['value' => Gen::intBetween(0, 10_000)])
            ->runs(100)
            ->check(static function (int $value): void {
                self::assertLessThan(100, $value);
            });
    }

    public function testPropertyPhasesRejectsAnUnknownStage(): void
    {
        putenv('PROPERTY_PHASES=examples,rundom');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PROPERTY_PHASES must be a comma-separated list of examples, corpus, random, shrink, got "rundom"');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });
    }

    public function testPropertyPhasesWinsOverAnExplicitPhaseList(): void
    {
        // The environment dials the suite: a CI gate has to be able to cut the
        // random phase out of a property that asks for it in code.
        putenv('PROPERTY_PHASES=examples,corpus');

        $this->forAll(['value' => Gen::intBetween(0, 10_000)])
            ->runs(100)
            ->phases([Phase::Examples, Phase::Corpus, Phase::Random, Phase::Shrink])
            ->check(static function (int $value): void {
                self::assertLessThan(100, $value);
            });
    }

    public function testPropertyPhasesIgnoresSpacingAndCase(): void
    {
        // The variable is typed by a human on a command line.
        putenv('PROPERTY_PHASES= Examples , CORPUS ');

        $this->forAll(['value' => Gen::intBetween(0, 10_000)])
            ->runs(100)
            ->check(static function (int $value): void {
                self::assertLessThan(100, $value);
            });
    }

    public function testPropertyDerandomizeWinsOverAnExplicitFalse(): void
    {
        putenv('PROPERTY_DERANDOMIZE=1');

        $arguments = fn(): array => $this->firstArguments(derandomize: false);

        self::assertSame($arguments(), $arguments());
    }

    public function testPropertyDerandomizeZeroLeavesTheSuiteRandom(): void
    {
        // '0' is the documented off switch, like PROPERTY_VERBOSE.
        putenv('PROPERTY_DERANDOMIZE=0');

        self::assertNotSame($this->firstArguments(), $this->firstArguments());
    }

    public function testAnEmptyPropertyPathIsNotAPath(): void
    {
        // '' means unset for every variable in this table; handing it to the
        // engine as a path would fail the run instead.
        putenv('PROPERTY_PATH=');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(5)
            ->seed(1)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });
    }

    public function testPropertyDerandomizeMakesAnUnseededPropertyReproducible(): void
    {
        putenv('PROPERTY_DERANDOMIZE=1');

        self::assertSame($this->firstArguments(), $this->firstArguments());
    }

    public function testWithoutDerandomizeAnUnseededPropertyDrawsAFreshSeed(): void
    {
        // The other half of the previous test: without the variable the two
        // runs are independent, so the assertion above is about the variable
        // rather than about the generator being degenerate.
        self::assertNotSame($this->firstArguments(), $this->firstArguments());
    }

    public function testPropertyPathReplaysARecordedDescentAndTheExplicitPathWins(): void
    {
        $path = $this->pathOfAFailure(seed: 4242);

        // Replaying it through the environment reproduces the same shrunk
        // counterexample.
        putenv('PROPERTY_SEED=4242');
        putenv('PROPERTY_PATH=' . $path);

        try {
            $this->runFalsifiableProperty();

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);
            self::assertSame($path, $previous->getCounterExample()->path);
        }

        // And an explicit path(), like an explicit seed(), wins over the
        // variable: the bogus one below never runs.
        putenv('PROPERTY_PATH=value:9999');

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(4242)
                ->path($path)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
        }
    }

    public function testAClosureDerivedIdIsReportedOnStderr(): void
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);

        // Reproduces what Pest does: forAll() called from a closure, so the
        // derived id carries {closure and the corpus key would move.
        $run = function () use ($stderr): void {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(3)
                ->output(STDOUT, $stderr)
                ->check(static function (int $value): void {
                    self::assertGreaterThanOrEqual(0, $value);
                });
        };
        $run();

        rewind($stderr);
        $warning = (string) stream_get_contents($stderr);

        // The whole line, newline included: this is machine-greppable CLI
        // output, and "contains" would not notice it losing its terminator.
        self::assertMatchesRegularExpression(
            '/^Property id "[^"]*\{closure[^"]*" comes from a closure and is not stable: .+pass an explicit property id\n$/s',
            $warning,
        );
    }

    public function testANamedPropertyWarnsAboutNothing(): void
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);

        $run = function () use ($stderr): void {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->id('named::property')
                ->runs(3)
                ->output(STDOUT, $stderr)
                ->check(static function (int $value): void {
                    self::assertGreaterThanOrEqual(0, $value);
                });
        };
        $run();

        rewind($stderr);

        self::assertSame('', (string) stream_get_contents($stderr));
    }

    /**
     * The arguments of the first run, as a listener saw them.
     *
     * @return list<mixed>
     */
    private function firstArguments(?bool $derandomize = null): array
    {
        $listener = new RecordingListener();

        $check = $this->forAll(['value' => Gen::intBetween(0, 1_000_000)])
            ->id('parity::derandomize')
            ->runs(1)
            ->listeners($listener);

        if ($derandomize !== null) {
            $check->derandomize($derandomize);
        }

        $check
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        foreach ($listener->events as $event) {
            if ($event instanceof RunStarted) {
                return array_values($event->arguments);
            }
        }

        self::fail('No RunStarted event was recorded');
    }

    private function pathOfAFailure(int $seed): string
    {
        try {
            $this->runFalsifiableProperty($seed);

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);

            return $previous->getCounterExample()->path;
        }
    }

    private function runFalsifiableProperty(
        ?int $seed = null,
        ?string $id = null,
        ?RecordingListener $listener = null,
    ): void {
        // Unseeded by default: corpus replay only runs for unseeded
        // properties, and a random value below 100 for all 100 runs is
        // practically impossible, so falsification is certain either way.
        $check = $this->forAll(['value' => Gen::intBetween(0, 10_000)])->runs(100);

        if ($id !== null) {
            $check->id($id);
        }

        if ($listener instanceof RecordingListener) {
            $check->listeners($listener);
        }

        if ($seed !== null) {
            $check->seed($seed);
        }

        $check->check(static function (int $value): void {
            self::assertLessThan(100, $value);
        });
    }
}
