<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Event\PropertyStarted;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\RecordingListener;

/**
 * The adapter's fine print: exact report strings, exact env-validation
 * boundaries, the property id, and the default run count — the details the
 * mutation gate holds this package to.
 */
#[CoversClass(PropertyCheck::class)]
#[CoversTrait(PropertyTesting::class)]
final class AdapterDetailsTest extends TestCase
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

    public function testThePropertyIdIsClassColonColonMethod(): void
    {
        $listener = new RecordingListener();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(1)
            ->seed(1)
            ->listeners($listener)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        $started = $listener->events[0];
        self::assertInstanceOf(PropertyStarted::class, $started);
        self::assertSame(self::class . '::testThePropertyIdIsClassColonColonMethod', $started->propertyId);
    }

    public function testListenersAcceptNamedArgumentsWithoutLeakingStringKeys(): void
    {
        $listener = new RecordingListener();

        // A named variadic argument arrives string-keyed; the builder must
        // reindex before handing the list to the engine.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(1)
            ->seed(1)
            ->listeners(recorder: $listener)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        self::assertNotSame([], $listener->events);
    }

    public function testTheDefaultRunCountIsExactlyOneHundred(): void
    {
        $listener = new RecordingListener();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->seed(1)
            ->listeners($listener)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        self::assertSame(100, $listener->count(RunStarted::class));
    }

    public function testAPassRegistersExactlyOneExtraAssertion(): void
    {
        $before = $this->numberOfAssertionsPerformed();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(5)
            ->seed(1)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        // Mid-test the counter only reflects addToAssertionCount() — the
        // closure's own static assertions merge in after the test ends. The
        // property itself must have registered exactly one.
        self::assertSame($before + 1, $this->numberOfAssertionsPerformed());
    }

    public function testTheAssertionFailureCarriesCodeZero(): void
    {
        try {
            $this->forAll(['value' => Gen::intBetween(0, 10_000)])
                ->runs(50)
                ->seed(42)
                ->check(static function (int $value): void {
                    self::assertLessThan(100, $value);
                });

            self::fail('The property should have been falsified');
        } catch (AssertionFailedError $failure) {
            self::assertSame(0, $failure->getCode());
        }
    }

    public function testTheDistributionReportLineIsExactAndSortedByCount(): void
    {
        [$stdout, $stderr] = $this->streams();

        $this->forAll(['value' => Gen::intBetween(1, 100)])
            ->runs(50)
            ->seed(11)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                Classify::when(condition: true, label: 'always');
                Classify::when($value > 90, 'rare');

                self::assertGreaterThanOrEqual(1, $value);
            });

        rewind($stdout);
        $report = (string) stream_get_contents($stdout);
        rewind($stderr);

        // "always" hits every check, so it leads regardless of insertion
        // order; percentages are integer-rounded of count/checks.
        self::assertSame(1, preg_match('/^Property "\w+" distribution: always 100% \(50\/50\), rare \d+% \(\d+\/50\)\n$/', $report));
        // A clean pass warns about nothing.
        self::assertSame('', (string) stream_get_contents($stderr));
    }

    public function testAPassWithoutClassificationsPrintsNothing(): void
    {
        [$stdout, $stderr] = $this->streams();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(3)
            ->seed(7)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        rewind($stdout);
        rewind($stderr);

        // No labels — no report line at all, not an empty-parts report.
        self::assertSame('', (string) stream_get_contents($stdout));
        self::assertSame('', (string) stream_get_contents($stderr));
    }

    public function testTheDistributionIsSortedByCountNotInsertionOrder(): void
    {
        [$stdout, $stderr] = $this->streams();

        // "once" is inserted first but counts 1/3; "often" counts 2/3 and must
        // lead. The percentages 33 and 67 also pin round() against floor/ceil.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(3)
            ->seed(13)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                static $invocation = 0;
                ++$invocation;

                Classify::when($invocation === 1, 'once');
                Classify::when($invocation <= 2, 'often');

                self::assertGreaterThanOrEqual(0, $value);
            });

        rewind($stdout);

        self::assertSame(
            'Property "testTheDistributionIsSortedByCountNotInsertionOrder" distribution: often 67% (2/3), once 33% (1/3)' . PHP_EOL,
            (string) stream_get_contents($stdout),
        );
    }

    public function testAModestDiscardRateDoesNotWarn(): void
    {
        [$stdout, $stderr] = $this->streams();

        // 1 discard in 10 attempts: rate 0.1, far below the 0.9 threshold.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(9)
            ->seed(1)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                static $invocation = 0;
                ++$invocation;

                Assume::that($invocation !== 1);
            });

        rewind($stderr);

        self::assertSame('', (string) stream_get_contents($stderr));
    }

    public function testADiscardRateExactlyAtTheThresholdDoesNotWarn(): void
    {
        [$stdout, $stderr] = $this->streams();

        // 9 discards in 10 attempts: rate is exactly 0.9 — the boundary stays
        // silent, only strictly-above warns.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(1)
            ->seed(1)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                static $invocation = 0;
                ++$invocation;

                Assume::that($invocation > 9);
            });

        rewind($stderr);

        self::assertSame('', (string) stream_get_contents($stderr));
    }

    public function testTheDiscardWarningPercentageIsRounded(): void
    {
        [$stdout, $stderr] = $this->streams();

        // 29 of 30 is 96.67% — printed as 97, not truncated to 96.
        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(1)
            ->seed(1)
            ->maxDiscards(100)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                static $invocation = 0;
                ++$invocation;

                Assume::that($invocation > 29);
            });

        rewind($stderr);

        self::assertSame(
            'Property "testTheDiscardWarningPercentageIsRounded" discarded 29 of 30 attempt(s) (97%); consider narrowing the generators' . PHP_EOL,
            (string) stream_get_contents($stderr),
        );
    }

    public function testTheDiscardWarningLineIsExact(): void
    {
        [$stdout, $stderr] = $this->streams();

        // Seed 5 draws its first 0 on attempt 17: 16 discards, 1 check.
        $this->forAll(['value' => Gen::intBetween(0, 100)])
            ->runs(1)
            ->seed(5)
            ->maxDiscards(1_000)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                Assume::that($value === 0);
            });

        rewind($stderr);

        self::assertSame(
            'Property "testTheDiscardWarningLineIsExact" discarded 16 of 17 attempt(s) (94%); consider narrowing the generators' . PHP_EOL,
            (string) stream_get_contents($stderr),
        );
    }

    public function testGivingUpStillPrintsTheDiscardWarning(): void
    {
        [$stdout, $stderr] = $this->streams();

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(5)
                ->maxDiscards(3)
                ->seed(1)
                ->output($stdout, $stderr)
                ->check(static function (int $value): void {
                    Assume::that(condition: false);
                });

            self::fail('The property should have given up');
        } catch (AssertionFailedError) {
            rewind($stderr);

            self::assertStringContainsString(
                'discarded 4 of 4 attempt(s) (100%)',
                (string) stream_get_contents($stderr),
            );
        }
    }

    public function testExceededBudgetStillPrintsTheDistributionReport(): void
    {
        [$stdout, $stderr] = $this->streams();

        try {
            // The first check takes ~2 ms, so the 1 ms phase budget is
            // exceeded on the loop's next look at the clock — after one
            // classified, successful run.
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(1_000)
                ->seed(1)
                ->budgetMs(1)
                ->output($stdout, $stderr)
                ->check(static function (int $value): void {
                    Classify::when(condition: true, label: 'ran');
                    usleep(2_000);

                    self::assertGreaterThanOrEqual(0, $value);
                });

            self::fail('The budget should have been exceeded');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('time budget', $failure->getMessage());
            rewind($stdout);
            self::assertStringContainsString('distribution: ran 100%', (string) stream_get_contents($stdout));
        }
    }

    public function testUnmetCoverageStillPrintsTheDistributionReport(): void
    {
        [$stdout, $stderr] = $this->streams();

        try {
            $this->forAll(['value' => Gen::intBetween(0, 10)])
                ->runs(20)
                ->seed(3)
                ->output($stdout, $stderr)
                ->check(static function (int $value): void {
                    Classify::label('seen');
                    Classify::cover($value < 0, 'negative', 99.0);

                    self::assertGreaterThanOrEqual(0, $value);
                });

            self::fail('The coverage requirement should have failed');
        } catch (AssertionFailedError) {
            rewind($stdout);
            self::assertStringContainsString('distribution: seen 100% (20/20)', (string) stream_get_contents($stdout));
        }
    }

    public function testRunsRejectsALeadingGarbagePrefix(): void
    {
        // Without the ^ anchor \d+\z matches the trailing "3", and the
        // (int) cast then yields 12 — which would pass the >= 1 guard.
        putenv('PROPERTY_RUNS=12x3');

        $this->expectException(\InvalidArgumentException::class);

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->check(static function (int $value): void {});
    }

    public function testRunsAcceptsOne(): void
    {
        putenv('PROPERTY_RUNS=1');

        $listener = new RecordingListener();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->seed(1)
            ->listeners($listener)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        self::assertSame(1, $listener->count(RunStarted::class));
    }

    public function testRunsRejectsZero(): void
    {
        putenv('PROPERTY_RUNS=0');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PROPERTY_RUNS must be a positive integer, got "0"');

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->check(static function (int $value): void {});
    }

    public function testSeedRejectsALeadingGarbagePrefix(): void
    {
        putenv('PROPERTY_SEED=x5');

        $this->expectException(\InvalidArgumentException::class);

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->check(static function (int $value): void {});
    }

    public function testVerboseStaysOffWhenUnset(): void
    {
        [$stdout, $stderr] = $this->streams();

        $this->forAll(['value' => Gen::intBetween(0, 10)])
            ->runs(3)
            ->seed(9)
            ->output($stdout, $stderr)
            ->check(static function (int $value): void {
                self::assertGreaterThanOrEqual(0, $value);
            });

        rewind($stdout);

        self::assertStringNotContainsString('attempt', (string) stream_get_contents($stdout));
    }

    /**
     * @return array{resource, resource}
     */
    private function streams(): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        \assert(\is_resource($stdout) && \is_resource($stderr));

        return [$stdout, $stderr];
    }
}
