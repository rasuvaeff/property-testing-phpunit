<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\DeadlineExceededException;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\FakeClock;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\RecordingListener;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\Runner\Phase;
use Rasuvaeff\PropertyTesting\Runner\ShrinkMode;
use Rasuvaeff\PropertyTesting\TimeBudgetExceededException;

#[CoversClass(PropertyCheck::class)]
#[CoversTrait(PropertyTesting::class)]
final class PropertyCheckTest extends TestCase
{
    use PropertyTesting;

    /** @var \Closure(): void */
    private \Closure $restoreEnv;

    protected function setUp(): void
    {
        $this->restoreEnv = Env::isolateProperty();
    }

    protected function tearDown(): void
    {
        ($this->restoreEnv)();
    }

    public function testPassingPropertyCountsAnAssertion(): void
    {
        $before = $this->numberOfAssertionsPerformed();

        $this->forAll(['left' => Gen::stringAscii(), 'right' => Gen::stringAscii()])
            ->runs(50)
            ->check(static function (string $left, string $right): void {
                self::assertSame(strlen($left) + strlen($right), strlen($left . $right));
            });

        self::assertGreaterThan($before, $this->numberOfAssertionsPerformed());
    }

    public function testAPropertyWhoseEveryRunIsSkippedIsASkippedTest(): void
    {
        // markTestSkipped() inside the body used to be caught as a failure
        // and shrunk toward the smallest input that still "fails".
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('no redis here');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(5)
            ->check(static function (int $value): void {
                self::markTestSkipped('no redis here');
            });
    }

    public function testAPropertyWhoseEveryRunIsIncompleteIsAnIncompleteTest(): void
    {
        $this->expectException(IncompleteTestError::class);

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(5)
            ->check(static function (int $value): void {
                self::markTestIncomplete('not yet');
            });
    }

    public function testAPartlySkippedPropertyDiscardsTheSkippedRuns(): void
    {
        // Skipped runs are discards: with runs 2 and maxDiscards 2 the budget
        // is exhausted by the skips before two checks are made.
        $calls = 0;

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(2)
                ->maxDiscards(2)
                ->check(static function (int $value) use (&$calls): void {
                    if (++$calls !== 2) {
                        self::markTestSkipped('most runs');
                    }
                });

            self::fail('expected the discard budget to be exhausted');
        } catch (AssertionFailedError $e) {
            self::assertInstanceOf(GaveUpException::class, $e->getPrevious());
        }
    }

    #[DataProvider('dataSets')]
    public function testTheCorpusIdCarriesTheDataSetName(int $upper): void
    {
        // Each data set builds its own property; without the set's name in
        // the id, one set's replay would prune the other set's regression.
        $check = $this->forAll(['value' => Gen::intBetween(0, $upper)]);

        self::assertSame(self::class . '::testTheCorpusIdCarriesTheDataSetName with data set "' . $this->dataName() . '"', $check->currentId());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function dataSets(): iterable
    {
        yield 'small' => [1];
        yield 'large' => [1_000];
    }

    public function testFalsifiedPropertyBecomesAnAssertionFailureWithTheEngineMessage(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(42)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
            self::assertStringContainsString('seed=42', $failure->getMessage());
            self::assertStringContainsString('Shrunk:   value=100', $failure->getMessage());
        }
    }

    public function testAssumeDiscardsInsideThePropertyNeverSkipTheTest(): void
    {
        $listener = new RecordingListener();

        $this->forAll(['value' => Gen::intBetween(0, 1_000)])
            ->runs(30)
            ->seed(7)
            ->listeners($listener)
            ->check(static function (int $value): void {
                Assume::that($value % 2 === 0);

                self::assertSame(0, $value % 2);
            });

        // Discarded attempts were retried until 30 checks completed: the
        // property passed and the test was never marked skipped.
        self::assertGreaterThanOrEqual(30, $listener->count(RunStarted::class));
    }

    public function testThrowsPassesWhenEveryTrialThrowsTheExpectedClass(): void
    {
        // The property-level replacement for expectException(), which never
        // sees a throw from inside a property body.
        $before = $this->numberOfAssertionsPerformed();

        $this->forAll(['value' => Gen::intBetween(0, 1_000)])
            ->runs(25)
            ->seed(9)
            ->throws(\RuntimeException::class)
            ->check(static function (int $value): void {
                throw new \RuntimeException('too big: ' . $value);
            });

        self::assertGreaterThan($before, $this->numberOfAssertionsPerformed());
    }

    public function testThrowsAcceptsASubclassOfTheExpectedClass(): void
    {
        $this->forAll(['value' => Gen::intBetween(0, 100)])
            ->runs(10)
            ->seed(3)
            ->throws(\Exception::class)
            ->check(static function (int $value): void {
                throw new \RuntimeException((string) $value);
            });
    }

    public function testThrowsFailsWhenTheBodyReturnsNormally(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(10)
                ->seed(3)
                ->throws(\RuntimeException::class)
                ->check(static function (int $value): void {});

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);

            $reason = $previous->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $reason);
            self::assertSame('Expected RuntimeException to be thrown, but it was not', $reason->getMessage());
        }
    }

    public function testThrowsFailsWhenTheBodyThrowsAnotherClass(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(10)
                ->seed(3)
                ->throws(\RuntimeException::class)
                ->check(static function (int $value): void {
                    throw new \LogicException('wrong one');
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);

            // The unexpected throw itself is the counterexample's failure,
            // exactly as it would be without throws().
            self::assertInstanceOf(\LogicException::class, $previous->getPrevious());
        }
    }

    public function testTheMissingThrowShrinksLikeAnyOtherCounterexample(): void
    {
        // Failing inputs are the ones that do not throw (value >= 100), so the
        // descent lands on the smallest of them — the same shape the seeded
        // falsification test pins for an ordinary assertion.
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(42)
                ->throws(\RuntimeException::class)
                ->check(static function (int $value): void {
                    if ($value < 100) {
                        throw new \RuntimeException('small enough');
                    }
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('seed=42', $failure->getMessage());
            self::assertStringContainsString('Shrunk:   value=100', $failure->getMessage());
        }
    }

    public function testASkipUnderThrowsIsStillASkipNotAPass(): void
    {
        // A skip is the environment's verdict about the run; it cannot earn
        // the pass that throwing the expected class would.
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('no redis here');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(5)
            ->throws(\RuntimeException::class)
            ->check(static function (int $value): void {
                self::markTestSkipped('no redis here');
            });
    }

    public function testThrowsRejectsAClassThatIsNotAThrowable(): void
    {
        // A typoed class name would otherwise falsify every run without a
        // word of explanation: instanceof against a nonexistent class is
        // false, never an error.
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->throws(\stdClass::class);

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Invalid expected exception class "stdClass": not a Throwable', $exception->getMessage());
        }
    }

    public function testExhaustedDiscardBudgetSurfacesTheGaveUpFailure(): void
    {
        $devnull = fopen('php://memory', 'r+');
        \assert(\is_resource($devnull));

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(5)
                ->maxDiscards(3)
                ->seed(1)
                ->output($devnull, $devnull)
                ->check(static function (int $value): void {
                    Assume::that(condition: false);
                });

            self::fail('The property should have given up');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(GaveUpException::class, $failure->getPrevious());
        }
    }

    public function testFailingExampleShortCircuitsUnshrunk(): void
    {
        $bodies = 0;

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(50)
                ->seed(1)
                ->examples([[999]])
                ->check(static function (int $value) use (&$bodies): void {
                    ++$bodies;

                    self::assertLessThanOrEqual(10, $value);
                });

            self::fail('The pinned example should have failed');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(ExampleViolationException::class, $failure->getPrevious());
            self::assertSame(1, $bodies);
        }
    }

    public function testUnmetCoverageFailsThePassingProperty(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 1_000)])
                ->runs(20)
                ->seed(3)
                ->check(static function (int $value): void {
                    Classify::cover($value < 0, 'negative', 99.0);

                    self::assertGreaterThanOrEqual(0, $value);
                });

            self::fail('The coverage requirement should have failed');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(CoverageViolationException::class, $failure->getPrevious());
        }
    }

    public function testMaxShrinksZeroReportsTheOriginalCounterexample(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(42)
                ->maxShrinks(0)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);

            $example = $previous->getCounterExample();
            self::assertSame($example->originalArguments, $example->shrunkArguments);
            self::assertSame(0, $example->shrinkSteps);
        }
    }

    public function testTheSameSeedReproducesTheSameCounterexample(): void
    {
        self::assertSame(
            $this->falsifiedOriginal(),
            $this->falsifiedOriginal(),
        );
    }

    public function testTimeoutAndBudgetSettersAreAcceptedByTheEngine(): void
    {
        // Generous limits: this pins the wiring (values reach PropertyConfig
        // without exploding), not the timing behaviour the engine already
        // characterises.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(10)
            ->seed(5)
            ->timeoutMs(60_000)
            ->budgetMs(60_000)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });
    }

    public function testAFakeClockMakesTheRunDeadlineDeterministic(): void
    {
        // Six milliseconds per reading against a five-millisecond deadline: the
        // first run overruns, and it does so without the suite waiting for real
        // time. This is what the clock seam exists for.
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(10)
                ->seed(5)
                ->timeoutMs(5)
                ->clock(new FakeClock(6_000_000))
                ->check(static function (int $value): void {
                    self::assertGreaterThanOrEqual(0, $value);
                });

            self::fail('expected the run deadline to be exceeded');
        } catch (AssertionFailedError $e) {
            self::assertInstanceOf(DeadlineExceededException::class, $e->getPrevious());
        }
    }

    public function testAFakeClockMakesThePhaseBudgetDeterministic(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(100)
                ->seed(5)
                ->budgetMs(5)
                ->clock(new FakeClock(6_000_000))
                ->check(static function (int $value): void {
                    self::assertGreaterThanOrEqual(0, $value);
                });

            self::fail('expected the phase budget to be exceeded');
        } catch (AssertionFailedError $e) {
            self::assertInstanceOf(TimeBudgetExceededException::class, $e->getPrevious());
        }
    }

    public function testShrinkOffReportsTheCounterexampleAsGenerated(): void
    {
        // Same behaviour as maxShrinks(0), reached through the mode the engine
        // resolves internally — the two are one behaviour with one
        // implementation, and this pins that the setter reaches it.
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(42)
                ->shrink(ShrinkMode::Off)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);

            $example = $previous->getCounterExample();
            self::assertSame($example->originalArguments, $example->shrunkArguments);
            self::assertSame(0, $example->shrinkSteps);
        }
    }

    public function testPhasesWithoutRandomNeverReachesTheGenerators(): void
    {
        // Examples and corpus only: the property below would be falsified by
        // the very first random draw, and passes because that phase is not in
        // the list.
        $this->forAll(['value' => Gen::intBetween(0, 10_000)])
            ->runs(100)
            ->phases([Phase::Examples, Phase::Corpus])
            ->check(static function (int $value): void {
                self::assertLessThan(100, $value);
            });
    }

    public function testDerandomizeMakesAnUnseededPropertyRepeatItself(): void
    {
        // The seed is what the knob decides, and the event reports the one the
        // engine ran with — a generated value would also match for a generator
        // that ignored its seed.
        $seed = static function (self $case): int {
            $listener = new RecordingListener();

            $case->forAll(['value' => Gen::intBetween(0, 1_000_000)])
                ->id('check::derandomize')
                ->runs(1)
                ->derandomize()
                ->listeners($listener)
                ->check(static function (int $value): void {
                    self::assertGreaterThanOrEqual(0, $value);
                });

            foreach ($listener->events as $event) {
                if ($event instanceof PropertyStarted) {
                    return $event->seed;
                }
            }

            self::fail('No PropertyStarted event was recorded');
        };

        self::assertSame($seed($this), $seed($this));
    }

    public function testPathReplaysTheRecordedDescent(): void
    {
        $path = '';
        $trialsWithoutPath = 0;

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(4242)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);
            $path = $previous->getCounterExample()->path;
            $trialsWithoutPath = $previous->getCounterExample()->shrinkTrials;
        }

        self::assertNotSame('', $path);

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
            $previous = $failure->getPrevious();
            self::assertInstanceOf(PropertyViolationException::class, $previous);

            // The replay walks the recorded steps instead of searching for
            // them: same counterexample, same path, and strictly fewer trials —
            // one body execution per accepted step rather than one per
            // candidate tried. The trial count is what fails if path() is
            // ignored, since a deterministic search reproduces the path anyway.
            self::assertSame($path, $previous->getCounterExample()->path);
            self::assertLessThan($trialsWithoutPath, $previous->getCounterExample()->shrinkTrials);
        }
    }

    public function testShrinkBudgetSetterIsAcceptedByTheEngine(): void
    {
        // Generous budget: this pins the wiring, not the timing — the budget
        // is the one knob that costs determinism, and the engine already
        // characterises what it does.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(10)
            ->seed(5)
            ->shrinkBudgetMs(60_000)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });
    }

    public function testDistributionReportIsPrintedForClassifiedPasses(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        \assert(\is_resource($stdout) && \is_resource($stderr));

        $this->forAll(['value' => Gen::intBetween(-100, 100)])
            ->runs(50)
            ->seed(11)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                Classify::when($value >= 0, 'non-negative');
                Classify::when($value < 0, 'negative');

                self::assertGreaterThanOrEqual(-100, $value);
            });

        rewind($stdout);
        $report = (string) stream_get_contents($stdout);

        self::assertStringContainsString('distribution:', $report);
        self::assertStringContainsString('non-negative', $report);
    }

    public function testExcessiveDiscardsPrintTheWarning(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        \assert(\is_resource($stdout) && \is_resource($stderr));

        // Seed 5 draws its first 0 on attempt 17: sixteen discards for one
        // check is comfortably past the 90% warning threshold, and the
        // property still passes.
        $this->forAll(['value' => Gen::intBetween(0, 100)])
            ->runs(1)
            ->seed(5)
            ->maxDiscards(1_000)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                Assume::that($value === 0);

                self::assertSame(0, $value);
            });

        rewind($stderr);
        $warning = (string) stream_get_contents($stderr);

        self::assertStringContainsString('discarded', $warning);
        self::assertStringContainsString('consider narrowing the generators', $warning);
    }

    public function testAutoDerivesEveryGeneratorFromTheClosureSignature(): void
    {
        // No forAll() map at all: the docblock annotations and the native bool
        // are the whole specification.
        $before = $this->numberOfAssertionsPerformed();

        $this->forAll()
            ->auto()
            ->runs(30)
            ->seed(1)
            ->check(
                /**
                 * @param int<1, 300> $base
                 * @param int<1, 86400> $cap
                 */
                static function (int $base, int $cap, bool $flag): void {
                    self::assertGreaterThanOrEqual(1, $base);
                    self::assertLessThanOrEqual(300, $base);
                    self::assertGreaterThanOrEqual(1, $cap);
                    self::assertLessThanOrEqual(86_400, $cap);
                    self::assertIsBool($flag);
                },
            );

        self::assertGreaterThan($before, $this->numberOfAssertionsPerformed());
    }

    public function testAutoTreatsTheForAllMapAsPartialOverrides(): void
    {
        // The map covers the type-inexpressible float range; the annotated int
        // is derived from the closure's signature.
        $this->forAll(['multiplier' => Gen::floatBetween(1.0, 4.0)])
            ->auto()
            ->runs(30)
            ->seed(1)
            ->check(
                /** @param int<1, 40> $attempt */
                static function (float $multiplier, int $attempt): void {
                    self::assertGreaterThanOrEqual(1.0, $multiplier);
                    self::assertLessThanOrEqual(4.0, $multiplier);
                    self::assertGreaterThanOrEqual(1, $attempt);
                    self::assertLessThanOrEqual(40, $attempt);
                },
            );
    }

    public function testAutoWithAFullMapDerivesNothing(): void
    {
        $this->forAll(['x' => Gen::constant(7)])
            ->auto()
            ->runs(5)
            ->seed(1)
            ->check(static function (int $x): void {
                self::assertSame(7, $x);
            });
    }

    public function testAutoRejectsATypeItCannotReadNamingFunctionAndParameter(): void
    {
        try {
            $this->forAll()
                ->auto()
                ->check(static function (array $anything): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('parameter $anything is typed array', $exception->getMessage());
            self::assertStringContainsString('pass an override', $exception->getMessage());
        }
    }

    public function testAutoRejectsAMapKeyThatIsNotAParameter(): void
    {
        // Merge semantics would silently replace a typoed map entry with a
        // signature-derived generator; an unknown key must be an error instead.
        try {
            $this->forAll(['y' => Gen::constant(7)])
                ->auto()
                ->check(static function (int $x): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('forAll() covers "y"', $exception->getMessage());
            self::assertStringContainsString('not a parameter', $exception->getMessage());
        }
    }

    public function testAMapKeyThatIsNotAParameterIsRejectedWithoutAutoToo(): void
    {
        // Without auto the engine would simply never read the entry — the
        // typo runs green in whatever domain "value" happens to have.
        try {
            $this->forAll(['vlaue' => Gen::constant(7), 'value' => Gen::constant(7)])
                ->check(static function (int $value): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Property "testAMapKeyThatIsNotAParameterIsRejectedWithoutAutoToo": forAll() covers "vlaue", which is not a parameter of the property',
                $exception->getMessage(),
            );
        }
    }

    public function testAListInsteadOfAMapIsRejectedByItsIntegerKey(): void
    {
        try {
            $this->forAll([Gen::constant(7)])
                ->check(static function (int $value): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('forAll() covers "0", which is not a parameter', $exception->getMessage());
        }
    }

    /**
     * @param array<array-key, mixed> $generators
     */
    #[DataProvider('nonArbitraryValueProvider')]
    public function testAValueThatIsNotAnArbitraryIsRejectedNamingTheKeyAndTheType(array $generators, string $expected): void
    {
        // Before this check the engine failed on `generate()` of a non-object,
        // naming neither the key nor the property.
        try {
            $this->forAll($generators)
                ->check(static function (int $value): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Property "testAValueThatIsNotAnArbitraryIsRejectedNamingTheKeyAndTheType": ' . $expected,
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function nonArbitraryValueProvider(): iterable
    {
        yield 'an int' => [['value' => 5], 'forAll() expects array<string, ArbitraryInterface>, got int for key "value"'];
        yield 'a closure' => [['value' => static fn(): int => 5], 'forAll() expects array<string, ArbitraryInterface>, got Closure for key "value"'];
        yield 'null' => [['value' => null], 'forAll() expects array<string, ArbitraryInterface>, got null for key "value"'];
        // The value is checked before the key: a wrong value under a wrong
        // key is reported as the value, the way Testo reports it.
        yield 'a string under a typoed key' => [['vlaue' => 'Gen::int()'], 'forAll() expects array<string, ArbitraryInterface>, got string for key "vlaue"'];
    }

    public function testAValueIsRejectedEvenUnderAuto(): void
    {
        try {
            $this->forAll(['value' => 5])
                ->auto()
                ->check(static function (int $value): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('got int for key "value"', $exception->getMessage());
        }
    }

    public function testAFalsifiedPropertyStillCountsItsOneAssertion(): void
    {
        $before = $this->numberOfAssertionsPerformed();

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->seed(42)
                ->check(static function (int $value): void {
                    // No PHPUnit assertion in the body: without the check's
                    // own assertion this test would be Failed and Risky.
                    if ($value >= 100) {
                        throw new \RuntimeException('too big');
                    }
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
            // Mid-test the counter reflects only addToAssertionCount(): the
            // one the check registered, on the failing path as on the passing.
            self::assertSame($before + 1, $this->numberOfAssertionsPerformed());
        }
    }

    public function testAPropertyThatGaveUpStillCountsItsOneAssertion(): void
    {
        $devnull = fopen('php://memory', 'r+');
        \assert(\is_resource($devnull));
        $before = $this->numberOfAssertionsPerformed();

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(5)
                ->maxDiscards(3)
                ->seed(1)
                ->output($devnull, $devnull)
                ->check(static function (int $value): void {
                    Assume::that(condition: false);
                });

            self::fail('The property should have given up');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(GaveUpException::class, $failure->getPrevious());
            self::assertSame($before + 1, $this->numberOfAssertionsPerformed());
        }
    }

    public function testAPropertyWhoseEveryRunIsSkippedCountsNoAssertion(): void
    {
        // A skipped test is the environment's verdict, not a check that ran;
        // it gets no assertion, so the skip is reported as a skip.
        $before = $this->numberOfAssertionsPerformed();

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(3)
                ->seed(1)
                ->check(static function (int $value): void {
                    self::markTestSkipped('no redis here');
                });

            self::fail('The property should have been skipped');
        } catch (SkippedWithMessageException) {
            self::assertSame($before, $this->numberOfAssertionsPerformed());
        }
    }

    /**
     * @param \Closure(PropertyCheck): PropertyCheck $configure
     */
    #[DataProvider('rejectedSetterValueProvider')]
    public function testASetterRejectsAValueBelowItsMinimumNamingTheProperty(\Closure $configure, string $expected): void
    {
        // The engine rejects the same values, but a dozen properties in one
        // class need to know which chain was wrong — and at the setter, not
        // at check().
        $check = $this->forAll(['value' => Gen::intBetween(0, 10)]);

        try {
            $configure($check);

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Property "testASetterRejectsAValueBelowItsMinimumNamingTheProperty": ' . $expected,
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{\Closure(PropertyCheck): PropertyCheck, string}>
     */
    public static function rejectedSetterValueProvider(): iterable
    {
        yield 'runs(0)' => [static fn(PropertyCheck $check): PropertyCheck => $check->runs(0), 'runs must be greater than or equal to 1'];
        yield 'runs(-1)' => [static fn(PropertyCheck $check): PropertyCheck => $check->runs(-1), 'runs must be greater than or equal to 1'];
        yield 'maxShrinks(-1)' => [static fn(PropertyCheck $check): PropertyCheck => $check->maxShrinks(-1), 'maxShrinks must be greater than or equal to 0'];
        yield 'maxDiscards(-1)' => [static fn(PropertyCheck $check): PropertyCheck => $check->maxDiscards(-1), 'maxDiscards must be greater than or equal to 0'];
        yield 'timeoutMs(0)' => [static fn(PropertyCheck $check): PropertyCheck => $check->timeoutMs(0), 'timeoutMs must be greater than or equal to 1'];
        yield 'budgetMs(0)' => [static fn(PropertyCheck $check): PropertyCheck => $check->budgetMs(0), 'budgetMs must be greater than or equal to 1'];
        yield 'shrinkBudgetMs(0)' => [static fn(PropertyCheck $check): PropertyCheck => $check->shrinkBudgetMs(0), 'shrinkBudgetMs must be greater than or equal to 1'];
    }

    public function testEverySetterAcceptsItsMinimum(): void
    {
        $listener = new RecordingListener();

        // runs(1) is one run; maxShrinks(0) is "no shrinking"; the rest are
        // one millisecond — every boundary is a legal value, not an error.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(1)
            ->maxShrinks(0)
            ->maxDiscards(0)
            ->timeoutMs(1)
            ->budgetMs(1)
            ->seed(1)
            ->listeners($listener)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        self::assertSame(1, $listener->count(RunStarted::class));

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(1)
            ->shrinkBudgetMs(1)
            ->seed(1)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });
    }

    public function testASetterErrorLeavesTheEngineMessageOutOfIt(): void
    {
        // One error, the adapter's, with the property's name — not the engine's
        // nameless one wrapped around it.
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])->runs(0);

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('Runs must be', $exception->getMessage());
        }
    }

    public function testAPathWithoutASeedIsRejectedAtCheckNamingTheProperty(): void
    {
        // path() and seed() may come in either order, so the check lives in
        // check() — before the engine's own, nameless refusal.
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->path('value:1')
                ->check(static function (int $value): void {});

            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Property "testAPathWithoutASeedIsRejectedAtCheckNamingTheProperty": replaying a shrink path requires an explicit seed — seed() or PROPERTY_SEED',
                $exception->getMessage(),
            );
        }
    }

    public function testAPathBeforeItsSeedIsAccepted(): void
    {
        // The order of the two setters must not matter — which is why the
        // seed requirement is not checked inside path().
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(100)
                ->path($this->pathOfAFailure())
                ->seed(4242)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertInstanceOf(PropertyViolationException::class, $failure->getPrevious());
        }
    }

    private function pathOfAFailure(): string
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->id(self::class . '::pathOfAFailure')
                ->runs(100)
                ->seed(4242)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            \assert($previous instanceof PropertyViolationException);

            return $previous->getCounterExample()->path;
        }

        self::fail('The property should have been falsified');
    }

    public function testWithoutAutoTheForAllMapIsUsedVerbatim(): void
    {
        // auto stays opt-in: no derivation happens unless auto() was called,
        // so a missing generator surfaces exactly as before.
        try {
            $this->forAll(['x' => Gen::constant(7)])
                ->runs(1)
                ->seed(1)
                ->check(static function (int $x, int $missing): void {});

            self::fail('Expected a failure about the missing generator');
        } catch (\InvalidArgumentException $failure) {
            // The engine's own refusal for a map that does not cover a
            // parameter — not a derivation error, which auto would produce.
            self::assertSame('No generator for parameter "missing"', $failure->getMessage());
        }
    }

    private function falsifiedOriginal(): mixed
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 100_000)])
                // Pinned: forAll() is one level down from the test method here.
                ->id(self::class . '::falsifiedOriginal')
                ->runs(100)
                ->seed(2026)
                ->check(static function (int $value): void {
                    self::assertLessThan(1_000, $value);
                });
        } catch (AssertionFailedError $failure) {
            $previous = $failure->getPrevious();
            \assert($previous instanceof PropertyViolationException);

            return $previous->getCounterExample()->originalArguments;
        }

        self::fail('The property should have been falsified');
    }
}
