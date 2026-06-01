<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Where the work for a ticket should live: the branch to create and its base.
 */
final readonly class BranchPlan
{
    public function __construct(
        public string $branch,
        public string $base,
    ) {
    }
}
