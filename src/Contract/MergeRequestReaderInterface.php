<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Contract;

/**
 * Optional capability a {@see VcsProviderInterface} may implement to read the
 * description/body of the open merge (or pull) request for a given source branch.
 *
 * Used by the agent review to inject "what was developed" as business context.
 * Best-effort: implementations return an empty string when nothing is found.
 */
interface MergeRequestReaderInterface
{
    public function mergeRequestDescription(string $sourceBranch): string;
}
