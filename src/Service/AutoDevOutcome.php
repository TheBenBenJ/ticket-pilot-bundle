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
    public function __construct(
        public string $ticketKey,
        public BranchPlan $branchPlan,
        public MergeRequest $mergeRequest,
    ) {
    }
}
