<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Service;

use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Git\GitClient;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;

/**
 * Orchestrates the end-to-end pipeline for a single ticket:
 * plan branch → create branch → run agent → commit & push → open merge request.
 *
 * The fetching of tickets and the iteration strategy live in the command layer;
 * this service is the reusable, side-effecting core.
 */
final class AutoDevRunner
{
    /**
     * @param list<string> $excludePaths Paths the agent's commit must never include
     */
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly PromptBuilderInterface $promptBuilder,
        private readonly BranchPlanner $branchPlanner,
        private readonly MergeRequestFactory $mergeRequestFactory,
        private readonly GitClient $git,
        private readonly VcsProviderInterface $vcs,
        private readonly array $excludePaths = [],
    ) {
    }

    /**
     * @param callable(string):void|null $onOutput Streamed agent-output callback
     *
     * @throws \RuntimeException when the branch already exists or any step fails
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

        $this->git->commitAndPush(
            $plan->branch,
            $this->mergeRequestFactory->commitMessage($ticket),
            $this->excludePaths,
        );

        $mergeRequest = $this->vcs->createMergeRequest(
            $plan->branch,
            $plan->base,
            $this->mergeRequestFactory->title($ticket),
            $this->mergeRequestFactory->description($ticket, $result->output),
        );

        return new AutoDevOutcome($ticket->key, $plan, $mergeRequest);
    }
}
