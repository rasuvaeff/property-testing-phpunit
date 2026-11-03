<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\Event\RunDiscarded;
use Rasuvaeff\PropertyTesting\Event\RunFailed;
use Rasuvaeff\PropertyTesting\Event\RunPassed;
use Rasuvaeff\PropertyTesting\Event\RunStarted;
use Rasuvaeff\PropertyTesting\Event\ShrinkAccepted;
use Rasuvaeff\PropertyTesting\Event\TargetImproved;
use Rasuvaeff\PropertyTesting\PhpUnit\VerboseListener;
use Rasuvaeff\PropertyTesting\Runner\TargetDirection;

/**
 * The exact lines of the `PROPERTY_VERBOSE` trace, one event at a time.
 */
#[CoversClass(VerboseListener::class)]
final class VerboseListenerTest extends TestCase
{
    public function testARunStartIsPrintedWithItsArguments(): void
    {
        self::assertSame(
            "Property \"prop\" attempt 3: x=7, y=\"a\"\n",
            $this->trace(new RunStarted('Cls::prop', 3, ['x' => 7, 'y' => 'a'])),
        );
    }

    public function testAPropertyIdWithoutAClassIsPrintedWhole(): void
    {
        self::assertSame(
            "Property \"bare-id\" attempt 1: x=7\n",
            $this->trace(new RunStarted('bare-id', 1, ['x' => 7])),
        );
    }

    public function testOnlyTheLastSeparatorSplitsTheClassFromTheMethod(): void
    {
        self::assertSame(
            "Property \"method\" attempt 1: x=7\n",
            $this->trace(new RunStarted('A::B::method', 1, ['x' => 7])),
        );
    }

    /**
     * @return iterable<string, array{PropertyEvent}>
     */
    public static function drawEventProvider(): iterable
    {
        yield 'passed' => [new RunPassed('Cls::prop', 2, ['x' => 1], ['draw#1' => 5], [], 10)];
        yield 'failed' => [new RunFailed('Cls::prop', 2, ['x' => 1], ['draw#1' => 5], null, 10)];
        yield 'discarded' => [new RunDiscarded('Cls::prop', 2, ['x' => 1], ['draw#1' => 5])];
    }

    #[DataProvider('drawEventProvider')]
    public function testInBodyDrawsArePrintedForEveryKindOfRun(PropertyEvent $event): void
    {
        self::assertSame("Property \"prop\" attempt 2 draws: draw#1=5\n", $this->trace($event));
    }

    /**
     * @return iterable<string, array{PropertyEvent}>
     */
    public static function noDrawEventProvider(): iterable
    {
        yield 'passed' => [new RunPassed('Cls::prop', 2, ['x' => 1], [], [], 10)];
        yield 'failed' => [new RunFailed('Cls::prop', 2, ['x' => 1], [], null, 10)];
        yield 'discarded' => [new RunDiscarded('Cls::prop', 2, ['x' => 1], [])];
    }

    #[DataProvider('noDrawEventProvider')]
    public function testARunWithoutDrawsPrintsNothing(PropertyEvent $event): void
    {
        self::assertSame('', $this->trace($event));
    }

    public function testATargetImprovementIsPrintedWithItsDirectionAndPreviousBest(): void
    {
        self::assertSame(
            "Property \"prop\" target depth max: - -> 3 (x=7)\n",
            $this->trace(new TargetImproved('Cls::prop', 'depth', TargetDirection::Maximize, 3.0, null, ['x' => 7])),
        );
        self::assertSame(
            "Property \"prop\" target size min: 2 -> 1 (x=5)\n",
            $this->trace(new TargetImproved('Cls::prop', 'size', TargetDirection::Minimize, 1.0, 2.0, ['x' => 5])),
        );
    }

    public function testAnAcceptedShrinkStepIsPrinted(): void
    {
        self::assertSame(
            "Property \"prop\" shrink step 4: x=10 -> 5\n",
            $this->trace(new ShrinkAccepted('Cls::prop', 4, 'x', 10, 5)),
        );
    }

    public function testAnEventOfAnotherKindPrintsNothing(): void
    {
        self::assertSame('', $this->trace(new class implements PropertyEvent {}));
    }

    private function trace(PropertyEvent $event): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        (new VerboseListener($stream))->onEvent($event);

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
