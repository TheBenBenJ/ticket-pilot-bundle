<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;

/**
 * Lists pending tickets for a source, as a table or JSON.
 */
#[AsCommand(
    name: 'ia:tickets:list',
    description: 'List the pending tickets of a source',
)]
final class ListTicketsCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly BranchPlanner $branchPlanner,
        private readonly string $defaultSource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Ticket source ('.implode(', ', $this->sources->names()).')', $this->defaultSource)
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of tickets', '10')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table, json)', 'table')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceName = (string) $input->getOption('source');

        if (!$this->sources->has($sourceName)) {
            $io->error(\sprintf('Unknown source "%s" (available: %s)', $sourceName, implode(', ', $this->sources->names())));

            return Command::INVALID;
        }

        try {
            $tickets = $this->sources->get($sourceName)->fetchPending((int) $input->getOption('limit'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $isJson = 'json' === $input->getOption('format');

        if ([] === $tickets) {
            $output->writeln($isJson ? '[]' : '<info>No pending ticket</info>');

            return Command::SUCCESS;
        }

        if ($isJson) {
            $rows = array_map(function ($ticket): array {
                $plan = $this->branchPlanner->plan($ticket);

                return $ticket->toArray() + ['branch' => $plan->branch, 'base' => $plan->base];
            }, $tickets);

            $output->writeln((string) json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->table(
            ['Key', 'Title', 'Type', 'Priority', 'Branch', 'Base'],
            array_map(function ($ticket): array {
                $plan = $this->branchPlanner->plan($ticket);

                return [$ticket->key, mb_substr($ticket->title, 0, 50), $ticket->type, $ticket->priority, $plan->branch, $plan->base];
            }, $tickets),
        );

        return Command::SUCCESS;
    }
}
