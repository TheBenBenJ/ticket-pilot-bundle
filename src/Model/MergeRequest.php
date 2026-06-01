<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Model;

/**
 * Result of a merge/pull request creation against a VCS provider.
 */
final readonly class MergeRequest
{
    /**
     * @param int $number Provider-side identifier (GitLab MR iid, GitHub PR number)
     */
    public function __construct(
        public int $number,
        public string $url,
    ) {
    }
}
