<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

use TheBenBenJ\TicketPilotBundle\Model\MergeRequest;

/**
 * A version-control hosting provider able to open merge/pull requests
 * (GitLab, GitHub, Bitbucket, ...).
 */
interface VcsProviderInterface
{
    public function createMergeRequest(
        string $sourceBranch,
        string $targetBranch,
        string $title,
        string $description,
        bool $draft = false,
    ): MergeRequest;

    /**
     * Whether the given branch exists on the remote.
     */
    public function remoteBranchExists(string $branch): bool;
}
