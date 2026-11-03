<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\PhpUnit\PhpUnitTrialExecutor;

#[CoversClass(PhpUnitTrialExecutor::class)]
final class PhpUnitTrialExecutorTest extends TestCase
{
    public function testArgumentsAreHandedToTheBodyByPosition(): void
    {
        $seen = null;
        $executor = new PhpUnitTrialExecutor(static function (int $first, int $second) use (&$seen): void {
            $seen = [$first, $second];
        });

        // The keys are the generators' names, not necessarily the body's
        // parameter names: only position may matter.
        $outcome = $executor->execute(['x' => 1, 'y' => 2]);

        self::assertTrue($outcome->isPassed());
        self::assertSame([1, 2], $seen);
    }

    public function testTheFirstSkipIsTheOneReportedWhenEveryRunSkipped(): void
    {
        $first = new SkippedWithMessageException('first');
        $second = new SkippedWithMessageException('second');
        $skips = [$first, $second];
        $executor = new PhpUnitTrialExecutor(static function () use (&$skips): void {
            throw array_shift($skips);
        });

        self::assertNull($executor->everyRunSkippedWith());

        $executor->execute(['x' => 1]);
        $executor->execute(['x' => 2]);

        self::assertSame($first, $executor->everyRunSkippedWith());
    }

    public function testASkipAmongPassingRunsIsNotReported(): void
    {
        $calls = 0;
        $executor = new PhpUnitTrialExecutor(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new SkippedWithMessageException('only once');
            }
        });

        $executor->execute(['x' => 1]);
        $executor->execute(['x' => 2]);

        self::assertNull($executor->everyRunSkippedWith());
    }
}
