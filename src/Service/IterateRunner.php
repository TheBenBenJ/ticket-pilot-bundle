<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use Symfony\Component\Lock\LockFactory;
use TheBenBenJ\TicketPilotBundle\Contract\IterationReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestCommentReaderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestReaderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Exception\QualityGateFailedException;
use TheBenBenJ\TicketPilotBundle\Exception\TicketLockedException;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Prompt\IteratePromptBuilder;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;

/**
 * Re-runs the coding agent on an EXISTING ticket branch to address review
 * feedback (merge request discussion + ticket comments), then commits and
 * pushes to the same branch — updating the open merge request in place.
 *
 * Unlike {@see AutoDevRunner} it never creates a branch nor opens a merge
 * request, and it never deletes the branch on failure (the branch pre-exists).
 * The browser review is re-run separately (and manually) once the review app
 * has been redeployed from the updated branch.
 */
final class IterateRunner
{
    private const LOCK_PREFIX = 'ticket-pilot-';

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly IteratePromptBuilder $promptBuilder,
        private readonly MergeRequestFactory $mergeRequestFactory,
        private readonly GitInterface $git,
        private readonly VcsProviderInterface $vcs,
        private readonly AutoDevOptions $options = new AutoDevOptions(),
        private readonly string $summaryStartMarker = '<<<MR_SUMMARY',
        private readonly string $summaryEndMarker = 'MR_SUMMARY>>>',
        private readonly ?QualityGateInterface $qualityGate = null,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * @param callable(string):void|null $onOutput Streamed agent-output callback
     *
     * @throws TicketLockedException      when another run already holds the ticket's lock
     * @throws QualityGateFailedException when the quality gate fails and the policy is "abort"
     * @throws \RuntimeException          when the branch does not exist or any step fails
     */
    public function process(
        Ticket $ticket,
        string $branch,
        string $agentName,
        ?string $model = null,
        ?callable $onOutput = null,
        ?TicketSourceInterface $source = null,
        string $instructions = '',
    ): IterationOutcome {
        $agent = $this->agents->get($agentName);

        $lock = $this->lockFactory?->createLock(self::LOCK_PREFIX.$ticket->key, (float) $this->options->lockTtl);
        if (null !== $lock && !$lock->acquire()) {
            throw new TicketLockedException($ticket->key);
        }

        try {
            if (!$this->git->localBranchExists($branch) && !$this->git->remoteBranchExists($branch)) {
                throw new \RuntimeException(\sprintf('Branch "%s" does not exist — run ia:auto-dev for %s first.', $branch, $ticket->key));
            }

            $this->git->checkoutBranch($branch);

            $feedback = $this->gatherFeedback($ticket, $branch);
            $prompt = $this->promptBuilder->build($ticket, $branch, $feedback, $this->mergeRequestDescription($branch), $instructions);

            $started = microtime(true);
            $result = $agent->run($prompt, $model, $onOutput);
            $duration = microtime(true) - $started;

            $filesChanged = $this->git->changedFiles();
            if (!$this->git->hasChanges()) {
                throw new \RuntimeException(\sprintf('The agent made no change while iterating on %s — nothing to push.', $ticket->key));
            }

            $this->runQualityGate();

            $this->git->commitAndPush(
                $branch,
                $this->mergeRequestFactory->commitMessage($ticket),
                $this->options->excludePaths,
            );

            $summary = $this->extractSummary($result->output);

            if ($source instanceof IterationReporterInterface) {
                // Best-effort: reporting back must never fail the run.
                try {
                    $source->reportIteration($ticket, $branch, $summary);
                } catch (\Throwable) {
                }
            }

            return new IterationOutcome($ticket->key, $branch, $summary, \count($feedback), $duration, $filesChanged);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Collects the reviewer feedback to feed back to the agent: the ticket
     * comments and, when the VCS provider supports it, the merge request notes.
     *
     * @return list<string>
     */
    private function gatherFeedback(Ticket $ticket, string $branch): array
    {
        $feedback = $ticket->comments;

        if ($this->vcs instanceof MergeRequestCommentReaderInterface) {
            $feedback = array_merge($feedback, $this->vcs->mergeRequestComments($branch));
        }

        return array_values($feedback);
    }

    private function mergeRequestDescription(string $branch): string
    {
        return $this->vcs instanceof MergeRequestReaderInterface
            ? $this->vcs->mergeRequestDescription($branch)
            : '';
    }

    /**
     * Runs the quality gate if configured. Returns null when it passed or no gate
     * is set, the failing report when the policy keeps going, and throws when the
     * policy is "abort" (so a failing iteration is never pushed).
     */
    private function runQualityGate(): ?QualityReport
    {
        if (null === $this->qualityGate) {
            return null;
        }

        $report = $this->qualityGate->verify();
        if ($report->passed) {
            return null;
        }

        if ($this->options->abortsOnQualityFailure()) {
            throw new QualityGateFailedException($report);
        }

        return $report;
    }

    private function extractSummary(string $output): string
    {
        $start = preg_quote($this->summaryStartMarker, '/');
        $end = preg_quote($this->summaryEndMarker, '/');

        if (1 === preg_match('/'.$start.'(.*?)'.$end.'/s', $output, $matches)) {
            return trim($matches[1]);
        }

        return trim(mb_substr($output, -1000));
    }
}
