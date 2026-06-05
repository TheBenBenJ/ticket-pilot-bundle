<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

/**
 * Result of an iteration run (re-development on an existing branch in response
 * to feedback).
 *
 * @see IterateRunner
 */
final readonly class IterationOutcome
{
    /**
     * @param list<string> $filesChanged Paths the agent touched
     */
    public function __construct(
        public string $ticketKey,
        public string $branch,
        public string $summary,
        public int $feedbackCount,
        public float $duration = 0.0,
        public array $filesChanged = [],
    ) {
    }
}
