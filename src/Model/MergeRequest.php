<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Result of a merge/pull request creation against a VCS provider.
 */
final readonly class MergeRequest
{
    public function __construct(
        public int $iid,
        public string $url,
    ) {
    }
}
