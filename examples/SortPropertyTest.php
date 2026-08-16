<?php

declare(strict_types=1);

namespace Examples;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\Assume;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\PhpUnit\PropertyTesting;

/**
 * Canonical usage of the PHPUnit adapter: mix the PropertyTesting trait into a
 * TestCase and describe properties with the fluent forAll()->check() API.
 *
 * Run it from the package root:
 *
 *     vendor/bin/phpunit examples/SortPropertyTest.php
 */
#[CoversNothing]
final class SortPropertyTest extends TestCase
{
    use PropertyTesting;

    public function testSortIsIdempotent(): void
    {
        $this->forAll(['values' => Gen::arrayOf(Gen::int())])
            ->runs(300)
            ->check(static function (array $values): void {
                $once = self::sorted($values);

                self::assertSame($once, self::sorted($once));
            });
    }

    public function testSortKeepsEveryElement(): void
    {
        $this->forAll(['values' => Gen::arrayOf(Gen::intBetween(-100, 100), maxSize: 30)])
            ->runs(200)
            ->check(static function (array $values): void {
                Classify::when(count($values) > 10, 'long');

                $sorted = self::sorted($values);
                self::assertCount(count($values), $sorted);

                foreach ($values as $value) {
                    self::assertContains($value, $sorted);
                }
            });
    }

    public function testMedianStaysBetweenMinAndMax(): void
    {
        $this->forAll(['values' => Gen::nonEmptyArrayOf(Gen::intBetween(-1_000, 1_000))])
            ->runs(200)
            ->check(static function (array $values): void {
                // A one-element list makes min === median === max; still valid,
                // but Assume shows how a discard works: it is a retried run,
                // never a skipped test.
                Assume::that(count($values) > 1);

                $sorted = self::sorted($values);
                $median = $sorted[intdiv(count($sorted), 2)];

                self::assertGreaterThanOrEqual($sorted[0], $median);
                self::assertLessThanOrEqual($sorted[count($sorted) - 1], $median);
            });
    }

    public function testRepeatingAPadOnlyGrowsTheString(): void
    {
        // Auto-derived generators: no forAll() map at all — the closure's
        // @param annotations are the whole specification.
        $this->forAll()
            ->auto()
            ->runs(200)
            ->check(
                /**
                 * @param non-empty-string $pad
                 * @param int<1, 20> $times
                 */
                static function (string $pad, int $times): void {
                    self::assertGreaterThanOrEqual(strlen($pad), strlen(str_repeat($pad, $times)));
                },
            );
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
