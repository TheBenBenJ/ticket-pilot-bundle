<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TheBenBenJ\TicketPilotBundle\Git\GitClient;
use TheBenBenJ\TicketPilotBundle\Model\BranchPlan;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;

/**
 * Decides the branch name and base branch for a ticket.
 *
 * - Bugs (and any ticket coming from a bug-only source) use the hotfix prefix
 *   and branch off the hotfix base.
 * - Tickets carrying a numeric fix version branch off the matching release
 *   branch when it exists on the remote, falling back otherwise.
 * - Everything else uses the feature prefix and branches off the feature base.
 */
final class BranchPlanner
{
    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $bugTypes Lower-cased ticket types treated as bugs
     */
    public function __construct(
        private readonly GitClient $git,
        private readonly string $featureBase = 'develop',
        private readonly string $hotfixBase = 'main',
        private readonly string $featurePrefix = 'feature',
        private readonly string $hotfixPrefix = 'hotfix',
        private readonly string $releaseBranchPattern = 'release/RC-{version}',
        private readonly array $bugTypes = ['bug', 'anomalie', 'defect'],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function plan(Ticket $ticket): BranchPlan
    {
        return new BranchPlan($this->branchName($ticket), $this->baseBranch($ticket));
    }

    public function branchName(Ticket $ticket): string
    {
        $prefix = $ticket->isBug($this->bugTypes) ? $this->hotfixPrefix : $this->featurePrefix;

        return \sprintf('%s/%s', $prefix, $ticket->key);
    }

    public function baseBranch(Ticket $ticket): string
    {
        $fixVersion = $ticket->fixVersion();

        if (null !== $fixVersion && 1 === preg_match('/^\d+(\.\d+)*$/', $fixVersion)) {
            $releaseBranch = str_replace('{version}', $fixVersion, $this->releaseBranchPattern);

            if ($this->git->remoteBranchExists($releaseBranch)) {
                return $releaseBranch;
            }

            $this->logger->warning(\sprintf('BranchPlanner: release branch %s not found, falling back', $releaseBranch));
        }

        return $ticket->isBug($this->bugTypes) ? $this->hotfixBase : $this->featureBase;
    }
}
