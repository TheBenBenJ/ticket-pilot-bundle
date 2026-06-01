<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheBenBenJ\TicketPilotBundle\Contract\VcsProviderInterface;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;
use TheBenBenJ\TicketPilotBundle\Service\MergeRequestFactory;

/**
 * Opens a merge request for a ticket whose branch was already pushed (e.g. when
 * the commit/push step is handled outside the bundle).
 */
#[AsCommand(
    name: 'ia:merge-request',
    description: 'Open a merge request for an already-pushed ticket branch',
)]
final class CreateMergeRequestCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly BranchPlanner $branchPlanner,
        private readonly MergeRequestFactory $mergeRequestFactory,
        private readonly VcsProviderInterface $vcs,
        private readonly string $defaultSource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ticket', 't', InputOption::VALUE_REQUIRED, 'Ticket key')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Ticket source ('.implode(', ', $this->sources->names()).')', $this->defaultSource)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = $input->getOption('ticket');
        $sourceName = (string) $input->getOption('source');

        if (null === $key) {
            $io->error('The --ticket option is required');

            return Command::INVALID;
        }
        if (!$this->sources->has($sourceName)) {
            $io->error(\sprintf('Unknown source "%s" (available: %s)', $sourceName, implode(', ', $this->sources->names())));

            return Command::INVALID;
        }

        try {
            $ticket = $this->sources->get($sourceName)->fetchOne((string) $key);
            $plan = $this->branchPlanner->plan($ticket);

            $mergeRequest = $this->vcs->createMergeRequest(
                $plan->branch,
                $plan->base,
                $this->mergeRequestFactory->title($ticket),
                $this->mergeRequestFactory->description($ticket),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('MR/PR #%d created: %s', $mergeRequest->number, $mergeRequest->url));

        return Command::SUCCESS;
    }
}
