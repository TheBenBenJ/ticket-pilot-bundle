<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use TheBenBenJ\TicketPilotBundle\Model\BranchPlan;
use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;

/**
 * Result of processing a single ticket through the auto-dev pipeline.
 */
final readonly class AutoDevOutcome
{
    /**
     * @param float        $duration     Wall-clock seconds the coding agent ran
     * @param list<string> $filesChanged Working-tree paths the agent changed
     */
    public function __construct(
        public string $ticketKey,
        public BranchPlan $branchPlan,
        public MergeRequest $mergeRequest,
        public float $duration = 0.0,
        public array $filesChanged = [],
    ) {
    }
}
