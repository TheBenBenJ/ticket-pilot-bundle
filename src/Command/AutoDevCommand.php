<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Security\TicketGuard;
use TheBenBenJ\TicketPilotBundle\Service\AutoDevRunner;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;

/**
 * Full orchestration: fetch tickets, create branches, run the coding agent,
 * commit, push and open merge requests.
 */
#[AsCommand(
    name: 'ia:auto-dev',
    description: 'Fetch tickets, run the coding agent and open merge requests',
)]
final class AutoDevCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly AgentRegistry $agents,
        private readonly BranchPlanner $branchPlanner,
        private readonly AutoDevRunner $runner,
        private readonly TicketGuard $ticketGuard,
        private readonly string $defaultSource,
        private readonly string $defaultAgent,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Ticket source ('.implode(', ', $this->sources->names()).')', $this->defaultSource)
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of tickets', '1')
            ->addOption('ticket', 't', InputOption::VALUE_REQUIRED, 'Process a specific ticket key')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, 'Coding agent ('.implode(', ', $this->agents->names()).')', $this->defaultAgent)
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Agent model override')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List tickets without processing them')
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

        $source = $this->sources->get($sourceName);

        $explicitTicket = null !== $input->getOption('ticket');

        try {
            if ($explicitTicket) {
                $tickets = [$source->fetchOne((string) $input->getOption('ticket'))];
            } else {
                $tickets = $source->fetchPending((int) $input->getOption('limit'));
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // The auto-pickup path is exposed to anyone who can file/label a ticket, so it is
        // gated by the trusted-reporters allowlist. An explicit --ticket is a human action.
        if (!$explicitTicket && $this->ticketGuard->isRestricted()) {
            $kept = array_values(array_filter($tickets, fn ($ticket): bool => $this->ticketGuard->isTrusted($ticket)));
            $skipped = \count($tickets) - \count($kept);
            if ($skipped > 0) {
                $io->note(\sprintf('%d ticket(s) skipped: reporter not in security.trusted_reporters.', $skipped));
            }
            $tickets = $kept;
        }

        if ([] === $tickets) {
            $io->success('No ticket to process');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->table(
                ['Key', 'Title', 'Branch', 'Base'],
                array_map(function ($ticket): array {
                    $plan = $this->branchPlanner->plan($ticket);

                    return [$ticket->key, mb_substr($ticket->title, 0, 50), $plan->branch, $plan->base];
                }, $tickets),
            );

            return Command::SUCCESS;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($tickets as $ticket) {
            $plan = $this->branchPlanner->plan($ticket);
            $io->section(\sprintf('%s (%s from %s)', $ticket->key, $plan->branch, $plan->base));

            try {
                $outcome = $this->runner->process(
                    $ticket,
                    $agentName,
                    $model,
                    static fn (string $buffer) => $output->write($buffer),
                );
                $io->success(\sprintf('MR/PR #%d created: %s', $outcome->mergeRequest->number, $outcome->mergeRequest->url));
                ++$succeeded;
            } catch (\Throwable $e) {
                $io->error(\sprintf('%s: %s', $ticket->key, $e->getMessage()));
                ++$failed;
            }
        }

        $io->section('Summary');
        $io->writeln(\sprintf('Succeeded: %d', $succeeded));
        if ($failed > 0) {
            $io->warning(\sprintf('Failed: %d', $failed));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
