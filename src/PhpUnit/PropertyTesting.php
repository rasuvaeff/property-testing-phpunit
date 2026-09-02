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
     * The id is derived from the calling method, which is why `forAll()` belongs
     * directly in a test method. Called from a closure, derive nothing — name the
     * property with {@see PropertyCheck::id()}, because PHP has no stable name for
     * a closure (see {@see \Rasuvaeff\PropertyTesting\PropertyId}).
     *
     * @param array<string, ArbitraryInterface> $generators One generator per
     *   property-body parameter, keyed by parameter name. May be omitted (or
     *   partial) when the chain continues with {@see PropertyCheck::auto()} —
     *   uncovered parameters are then derived from the closure's signature.
     */
    final protected function forAll(array $generators = []): PropertyCheck
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        $method = $frame['function'];

        // A data provider runs the method once per data set, and each set may
        // build a different property: the corpus id carries the set's name so
        // the sets do not replay — and prune — each other's regressions.
        $dataName = $this->dataName();
        $id = static::class . '::' . $method . ($dataName === '' ? '' : sprintf(' with data set "%s"', (string) $dataName));

        return new PropertyCheck(
            testCase: $this,
            id: $id,
            name: $method,
            generators: $generators,
            // Derived from something other than the running test method — a
            // helper or a closure — so the id is shared across call sites and
            // collides in the corpus. PropertyCheck warns unless id() pins it.
            idDerivedIndirectly: $method !== $this->name(),
        );
    }
}
