<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;
use TheBenBenJ\TicketPilotBundle\Contract\TicketReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\TicketSourceInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Event\TicketFailedEvent;
use TheBenBenJ\TicketPilotBundle\Event\TicketProcessedEvent;
use TheBenBenJ\TicketPilotBundle\Exception\QualityGateFailedException;
use TheBenBenJ\TicketPilotBundle\Exception\TicketLockedException;
use TheBenBenJ\TicketPilotBundle\Git\GitInterface;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;

/**
 * Orchestrates the end-to-end pipeline for a single ticket:
 * plan branch → create branch → run agent → quality gate → commit & push → open merge request.
 *
 * The fetching of tickets and the iteration strategy live in the command layer;
 * this service is the reusable, side-effecting core.
 */
final class AutoDevRunner
{
    private const LOCK_PREFIX = 'ticket-pilot-';

    /**
     * @param QualityGateInterface|null     $qualityGate When set, runs after the agent and before push
     * @param EventDispatcherInterface|null $dispatcher  When set, emits TicketProcessedEvent / TicketFailedEvent
     * @param LockFactory|null              $lockFactory When set, takes a per-ticket lock so concurrent runs
     *                                                   (batch / cron) never process the same ticket twice
     */
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly PromptBuilderInterface $promptBuilder,
        private readonly BranchPlanner $branchPlanner,
        private readonly MergeRequestFactory $mergeRequestFactory,
        private readonly GitInterface $git,
        private readonly VcsProviderInterface $vcs,
        private readonly AutoDevOptions $options = new AutoDevOptions(),
        private readonly ?QualityGateInterface $qualityGate = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * @param callable(string):void|null $onOutput Streamed agent-output callback
     *
     * @throws TicketLockedException      when another run already holds the ticket's lock
     * @throws QualityGateFailedException when the quality gate fails and the policy is "abort"
     * @throws \RuntimeException          when the branch already exists or any step fails
     */
    public function process(
        Ticket $ticket,
        string $agentName,
        ?string $model = null,
        ?callable $onOutput = null,
        ?TicketSourceInterface $source = null,
    ): AutoDevOutcome {
        $agent = $this->agents->get($agentName);
        $plan = $this->branchPlanner->plan($ticket);

        $lock = $this->lockFactory?->createLock(self::LOCK_PREFIX.$ticket->key, (float) $this->options->lockTtl);
        if (null !== $lock && !$lock->acquire()) {
            throw new TicketLockedException($ticket->key);
        }

        try {
            if ($this->git->localBranchExists($plan->branch)) {
                throw new \RuntimeException(\sprintf('Local branch "%s" already exists', $plan->branch));
            }
            if ($this->git->remoteBranchExists($plan->branch)) {
                throw new \RuntimeException(\sprintf('Remote branch "%s" already exists', $plan->branch));
            }

            $this->git->createBranch($plan->branch, $plan->base);

            $pushed = false;

            try {
                $prompt = $this->promptBuilder->build($ticket);
                $result = $agent->run($prompt, $model, $onOutput);

                // Capture what the agent touched before the commit cleans the tree.
                $filesChanged = $this->git->changedFiles();

                $qualityFailure = $this->runQualityGate();

                $this->git->commitAndPush(
                    $plan->branch,
                    $this->mergeRequestFactory->commitMessage($ticket),
                    $this->options->excludePaths,
                );
                $pushed = true;

                $draft = $this->options->draft || null !== $qualityFailure;

                $mergeRequest = $this->vcs->createMergeRequest(
                    $plan->branch,
                    $plan->base,
                    $this->mergeRequestFactory->title($ticket),
                    $this->mergeRequestFactory->description($ticket, $result->output, $qualityFailure),
                    $draft,
                );

                if ($source instanceof TicketReporterInterface) {
                    // Best-effort: reporting back must never fail the run.
                    try {
                        $source->reportMergeRequest($ticket, $mergeRequest);
                    } catch (\Throwable) {
                    }
                }

                $outcome = new AutoDevOutcome($ticket->key, $plan, $mergeRequest, $result->duration, $filesChanged);
                $this->dispatcher?->dispatch(new TicketProcessedEvent($ticket, $outcome));

                return $outcome;
            } catch (\Throwable $e) {
                if ($this->options->cleanupOnFailure) {
                    if ($pushed) {
                        $this->git->deleteRemoteBranch($plan->branch);
                    }
                    $this->git->deleteLocalBranch($plan->branch, $plan->base);
                }

                $this->dispatcher?->dispatch(new TicketFailedEvent($ticket, $e));

                throw $e;
            }
        } finally {
            $lock?->release();
        }
    }

    /**
     * Runs the quality gate if configured. Returns the failing report when the
     * policy keeps going (draft), null when it passed or no gate is set, and
     * throws when the policy is "abort".
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
}
