<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\CoverageViolationException;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\ExampleViolationException;
use Rasuvaeff\PropertyTesting\GaveUpException;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\RecordingListener;
use Rasuvaeff\PropertyTesting\PropertyViolationException;

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

    private function falsifiedOriginal(): mixed
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 100_000)])
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
