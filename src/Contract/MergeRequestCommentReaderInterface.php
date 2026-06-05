<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

/**
 * Optional capability a {@see VcsProviderInterface} may implement to read the
 * human discussion (review comments / notes) of the open merge (or pull) request
 * for a given source branch.
 *
 * Used by the iteration flow to feed reviewer feedback back to the agent.
 * Best-effort: implementations return an empty list when nothing is found, and
 * filter out system/automated notes so only human feedback reaches the agent.
 */
interface MergeRequestCommentReaderInterface
{
    /**
     * @return list<string> Human comments, oldest first, formatted "author: body"
     */
    public function mergeRequestComments(string $sourceBranch): array;
}
