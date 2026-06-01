<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityGateInterface;
use TheBenBenJ\TicketPilotBundle\Contract\QualityReport;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Exception\QualityGateFailedException;
use TheBenBenJ\TicketPilotBundle\Git\GitClient;
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
    /**
     * @param QualityGateInterface|null $qualityGate When set, runs after the agent and before push
     */
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly PromptBuilderInterface $promptBuilder,
        private readonly BranchPlanner $branchPlanner,
        private readonly MergeRequestFactory $mergeRequestFactory,
        private readonly GitClient $git,
        private readonly VcsProviderInterface $vcs,
        private readonly AutoDevOptions $options = new AutoDevOptions(),
        private readonly ?QualityGateInterface $qualityGate = null,
    ) {
    }

    /**
     * @param callable(string):void|null $onOutput Streamed agent-output callback
     *
     * @throws QualityGateFailedException when the quality gate fails and the policy is "abort"
     * @throws \RuntimeException          when the branch already exists or any step fails
     */
    public function process(
        Ticket $ticket,
        string $agentName,
        ?string $model = null,
        ?callable $onOutput = null,
    ): AutoDevOutcome {
        $agent = $this->agents->get($agentName);
        $plan = $this->branchPlanner->plan($ticket);

        if ($this->git->localBranchExists($plan->branch)) {
            throw new \RuntimeException(\sprintf('Local branch "%s" already exists', $plan->branch));
        }
        if ($this->git->remoteBranchExists($plan->branch)) {
            throw new \RuntimeException(\sprintf('Remote branch "%s" already exists', $plan->branch));
        }

        $this->git->createBranch($plan->branch, $plan->base);

        $prompt = $this->promptBuilder->build($ticket);
        $result = $agent->run($prompt, $model, $onOutput);

        $qualityFailure = $this->runQualityGate();

        $this->git->commitAndPush(
            $plan->branch,
            $this->mergeRequestFactory->commitMessage($ticket),
            $this->options->excludePaths,
        );

        $draft = $this->options->draft || null !== $qualityFailure;

        $mergeRequest = $this->vcs->createMergeRequest(
            $plan->branch,
            $plan->base,
            $this->mergeRequestFactory->title($ticket),
            $this->mergeRequestFactory->description($ticket, $result->output, $qualityFailure),
            $draft,
        );

        return new AutoDevOutcome($ticket->key, $plan, $mergeRequest);
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
