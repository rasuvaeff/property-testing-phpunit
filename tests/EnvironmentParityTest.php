<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyCheck;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\RecordingListener;
use Rasuvaeff\PropertyTesting\PropertyViolationException;
use Rasuvaeff\PropertyTesting\RegressionViolationException;

/**
 * The environment contract, byte-for-byte the Testo adapter's: PROPERTY_RUNS
 * overrides every run count, PROPERTY_SEED seeds unseeded properties (an
 * explicit seed() wins), PROPERTY_VERBOSE traces runs, PROPERTY_DB records
 * and replays the regression corpus.
 */
#[CoversClass(PropertyCheck::class)]
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

    private function runFalsifiableProperty(?int $seed = null): void
    {
        // Unseeded by default: corpus replay only runs for unseeded
        // properties, and a random value below 100 for all 100 runs is
        // practically impossible, so falsification is certain either way.
        $check = $this->forAll(['value' => Gen::intBetween(0, 10_000)])->runs(100);

        if ($seed !== null) {
            $check->seed($seed);
        }

        $check->check(static function (int $value): void {
            self::assertLessThan(100, $value);
        });
    }
}
