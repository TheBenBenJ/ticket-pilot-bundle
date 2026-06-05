<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheBenBenJ\TicketPilotBundle\Exception\QualityGateFailedException;
use TheBenBenJ\TicketPilotBundle\Exception\TicketLockedException;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;
use TheBenBenJ\TicketPilotBundle\Service\IterateRunner;

/**
 * Re-runs the coding agent on a ticket's existing branch to address review
 * feedback (merge request discussion + ticket comments), then pushes — updating
 * the open merge request. Launched manually; the browser review is re-run
 * separately once the review app has been redeployed.
 */
#[AsCommand(
    name: 'ia:iterate',
    description: 'Address review feedback on a ticket branch and push the update',
)]
final class IterateCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly AgentRegistry $agents,
        private readonly BranchPlanner $branchPlanner,
        private readonly IterateRunner $runner,
        private readonly string $defaultSource,
        private readonly string $defaultAgent,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ticket', InputArgument::REQUIRED, 'Ticket key (e.g. LYSI-2098)')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Ticket source ('.implode(', ', $this->sources->names()).')', $this->defaultSource)
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'Branch to iterate on (default: the ticket branch)')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, 'Coding agent ('.implode(', ', $this->agents->names()).')', $this->defaultAgent)
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Agent model override')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceName = (string) $input->getOption('source');
        $agentName = (string) $input->getOption('agent');
        $model = $input->getOption('model');
        $model = null !== $model ? (string) $model : null;

        if (!$this->sources->has($sourceName)) {
            $io->error(\sprintf('Unknown source "%s" (available: %s)', $sourceName, implode(', ', $this->sources->names())));

            return Command::INVALID;
        }
        if (!$this->agents->has($agentName)) {
            $io->error(\sprintf('Unknown agent "%s" (available: %s)', $agentName, implode(', ', $this->agents->names())));

            return Command::INVALID;
        }

        try {
            $source = $this->sources->get($sourceName);
            $ticket = $source->fetchOne((string) $input->getArgument('ticket'));
            $branchOption = $input->getOption('branch');
            $branch = \is_string($branchOption) && '' !== $branchOption ? $branchOption : $this->branchPlanner->branchName($ticket);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('Iterating on %s (%s)', $ticket->key, $branch));

        try {
            $outcome = $this->runner->process(
                $ticket,
                $branch,
                $agentName,
                $model,
                static fn (string $buffer) => $output->write($buffer),
                $source,
            );
        } catch (TicketLockedException $e) {
            $io->note($e->getMessage());

            return Command::SUCCESS;
        } catch (QualityGateFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(\sprintf('%s: %s', $ticket->key, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->newLine();
        if ('' !== $outcome->summary) {
            $io->writeln($outcome->summary);
        }
        $io->success(\sprintf('Iteration pushed to %s (%d file(s), %d feedback item(s) addressed)', $outcome->branch, \count($outcome->filesChanged), $outcome->feedbackCount));

        return Command::SUCCESS;
    }
}
