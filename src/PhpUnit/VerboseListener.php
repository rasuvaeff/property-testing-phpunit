<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\PropertyListener;
use Rasuvaeff\PropertyTesting\Runner\TraceListener;

/**
 * The `PROPERTY_VERBOSE` trace on a stream: core's {@see TraceListener} words
 * the lines (one per attempt's arguments, one per attempt's in-body draws, one
 * per target improvement, one per accepted shrink step) — the same lines the
 * Testo adapter prints; this adapter only chooses where they go.
 *
 * Exception-hardened by policy, as the core listener is: a bug in the
 * built-in trace must not fail every verbose consumer.
 *
 * @internal
 */
final readonly class VerboseListener implements PropertyListener
{
    private TraceListener $trace;

    /**
     * @param resource $stream
     */
    public function __construct($stream = STDOUT)
    {
        $this->trace = new TraceListener(static function (string $line) use ($stream): void {
            fwrite($stream, $line . "\n");
        });
    }

    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        $this->trace->onEvent($event);
    }
}
