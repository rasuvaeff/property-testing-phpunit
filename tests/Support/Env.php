<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support;

/**
 * Scoped environment overrides for tests.
 *
 * `putenv('NAME')` in tearDown does not undo `putenv('NAME=value')` — it
 * deletes the variable. A developer or CI job that exported `PROPERTY_DB`,
 * `PROPERTY_RUNS`, `PROPERTY_SEED`, `PROPERTY_VERBOSE`, `PROPERTY_PHASES`,
 * `PROPERTY_DERANDOMIZE` or `PROPERTY_PATH` for the whole run
 * would silently lose it partway through the suite, and every later test would
 * observe a different configuration than the one it was invoked with. Clearing
 * them in setUp through this helper also isolates the suite from an ambient
 * value that would otherwise change what these very tests assert.
 */
final class Env
{
    /**
     * Overrides one variable and returns the undo. Pass null to unset it for
     * the duration of the test.
     *
     * @return \Closure(): void
     */
    public static function set(string $name, ?string $value): \Closure
    {
        $previous = getenv($name);
        putenv($value === null ? $name : $name . '=' . $value);

        return static function () use ($name, $previous): void {
            putenv($previous === false ? $name : $name . '=' . $previous);
        };
    }

    /**
     * Overrides several variables at once; the returned undo restores all of
     * them, in reverse order.
     *
     * @param array<string, string|null> $variables
     *
     * @return \Closure(): void
     */
    public static function setMany(array $variables): \Closure
    {
        $undos = [];

        foreach ($variables as $name => $value) {
            $undos[] = self::set($name, $value);
        }

        return static function () use ($undos): void {
            foreach (array_reverse($undos) as $undo) {
                $undo();
            }
        };
    }

    /**
     * Clears every `PROPERTY_*` variable the adapter reads and returns the undo.
     *
     * @return \Closure(): void
     */
    public static function isolateProperty(): \Closure
    {
        return self::setMany([
            'PROPERTY_RUNS' => null,
            'PROPERTY_SEED' => null,
            'PROPERTY_VERBOSE' => null,
            'PROPERTY_DB' => null,
            'PROPERTY_PHASES' => null,
            'PROPERTY_DERANDOMIZE' => null,
            'PROPERTY_PATH' => null,
            'PROPERTY_EDGE_CASES' => null,
        ]);
    }
}
