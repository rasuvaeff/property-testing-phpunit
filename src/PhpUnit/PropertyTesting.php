<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;

/**
 * The fluent entry point a PHPUnit TestCase mixes in:
 *
 *     $this->forAll(['values' => Gen::arrayOf(Gen::int())])
 *         ->runs(300)
 *         ->check(function (array $values): void {
 *             self::assertSame(sortValues($values), sortValues(sortValues($values)));
 *         });
 *
 * The closure's parameter names select the generators, exactly like a
 * `#[Property]` method signature does under the Testo adapter. A pass counts
 * one assertion; every failing outcome surfaces as one AssertionFailedError
 * carrying the engine failure as its previous exception.
 *
 * @api
 */
trait PropertyTesting
{
    /**
     * @param array<string, ArbitraryInterface> $generators One generator per
     *   property-body parameter, keyed by parameter name.
     */
    final protected function forAll(array $generators): PropertyCheck
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        $method = $frame['function'];

        return new PropertyCheck(
            testCase: $this,
            id: static::class . '::' . $method,
            name: $method,
            generators: $generators,
        );
    }
}
