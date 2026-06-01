<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Outcome of a coding agent run.
 */
final readonly class AgentResult
{
    public function __construct(
        public bool $successful,
        public string $output,
    ) {
    }
}
