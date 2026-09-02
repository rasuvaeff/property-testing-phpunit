<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit;

use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use Rasuvaeff\PropertyTesting\AssumptionSkipped;
use Rasuvaeff\PropertyTesting\Runner\TrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\TrialOutcome;

/**
 * Runs the property body and folds what it throws into the engine's
 * {@see TrialOutcome} the way PHPUnit means it: {@see AssumptionSkipped} is a
 * discard; a skipped or incomplete test (`markTestSkipped()`,
 * `markTestIncomplete()`) is a discard too, and remembered — a property whose
 * every run was skipped is a skipped test, not a falsified one shrunk toward
 * the smallest input that still skips; anything else thrown is the failure.
 *
 * @internal Driven by {@see PropertyCheck}.
 */
final class PhpUnitTrialExecutor implements TrialExecutor
{
    private int $runs = 0;

    private int $skipped = 0;

    private ?\Throwable $firstSkip = null;

    public function __construct(
        private readonly \Closure $body,
    ) {}

    #[\Override]
    public function execute(array $arguments): TrialOutcome
    {
        ++$this->runs;

        try {
            ($this->body)(...array_values($arguments));
        } catch (AssumptionSkipped) {
            return TrialOutcome::discarded();
        } catch (SkippedTest|IncompleteTest $skip) {
            ++$this->skipped;
            $this->firstSkip ??= $skip;

            return TrialOutcome::discarded();
        } catch (\Throwable $failure) {
            return TrialOutcome::failed($failure);
        }

        return TrialOutcome::passed();
    }

    /**
     * What the first skipped or incomplete run threw, when every run so far
     * was one (and there was one) — the exception to rethrow so PHPUnit
     * reports the test as skipped or incomplete.
     */
    public function everyRunSkippedWith(): ?\Throwable
    {
        return $this->runs > 0 && $this->skipped === $this->runs ? $this->firstSkip : null;
    }
}
