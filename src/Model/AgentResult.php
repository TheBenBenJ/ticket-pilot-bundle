<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Outcome of a coding agent run.
 */
final readonly class AgentResult
{
    /**
     * @param float $duration Wall-clock seconds the agent ran
     */
    public function __construct(
        public bool $successful,
        public string $output,
        public float $duration = 0.0,
    ) {
    }
}
