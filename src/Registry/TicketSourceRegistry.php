<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Registry;

use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;

/**
 * Resolves a {@see TicketSourceInterface} by its name.
 *
 * Sources are injected as a name-indexed map built from every service tagged
 * "ticket_pilot.ticket_source".
 */
final class TicketSourceRegistry
{
    /** @var array<string, TicketSourceInterface> */
    private array $sources = [];

    /**
     * @param iterable<TicketSourceInterface> $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->getName()] = $source;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    public function get(string $name): TicketSourceInterface
    {
        return $this->sources[$name]
            ?? throw new \InvalidArgumentException(\sprintf('Unknown ticket source "%s". Available: %s', $name, implode(', ', $this->names()) ?: '(none)'));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->sources);
    }
}
