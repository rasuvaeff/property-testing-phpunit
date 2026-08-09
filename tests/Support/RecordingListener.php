<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support;

use Rasuvaeff\PropertyTesting\Event\PropertyEvent;
use Rasuvaeff\PropertyTesting\PropertyListener;

/**
 * Collects every event it sees, for assertions on the listener wiring.
 */
final class RecordingListener implements PropertyListener
{
    /** @var list<PropertyEvent> */
    public array $events = [];

    #[\Override]
    public function onEvent(PropertyEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @param class-string<PropertyEvent> $type
     */
    public function count(string $type): int
    {
        return count(array_filter($this->events, static fn(PropertyEvent $event): bool => $event instanceof $type));
    }
}
