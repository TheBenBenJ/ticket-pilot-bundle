<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Registry;

use TheBenBenJ\TicketPilotBundle\Contract\CodingAgentInterface;

/**
 * Resolves a {@see CodingAgentInterface} by its name.
 *
 * Agents are injected as a name-indexed map built from every service tagged
 * "ticket_pilot.agent".
 */
final class AgentRegistry
{
    /** @var array<string, CodingAgentInterface> */
    private array $agents = [];

    /**
     * @param iterable<CodingAgentInterface> $agents
     */
    public function __construct(iterable $agents)
    {
        foreach ($agents as $agent) {
            $this->agents[$agent->getName()] = $agent;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->agents[$name]);
    }

    public function get(string $name): CodingAgentInterface
    {
        return $this->agents[$name]
            ?? throw new \InvalidArgumentException(\sprintf('Unknown coding agent "%s". Available: %s', $name, implode(', ', $this->names()) ?: '(none)'));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->agents);
    }
}
