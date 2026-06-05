<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;

/**
 * Lists the runs (developments, iterations, reviews) the bundle has launched,
 * read from the configured run store.
 */
#[AsCommand(
    name: 'ia:runs',
    description: 'List the runs launched by the bundle',
)]
final class RunsCommand extends Command
{
    public function __construct(private readonly RunStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of runs', '30')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by type (auto-dev, iterate, review)')
            ->addOption('ticket', null, InputOption::VALUE_REQUIRED, 'Filter by ticket key')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = max(1, (int) $input->getOption('limit'));
        $type = $input->getOption('type');
        $ticket = $input->getOption('ticket');

        // Over-fetch then filter, so --limit still bounds the displayed rows.
        $records = $this->store->recent(\is_string($type) || \is_string($ticket) ? 1000 : $limit);

        $records = array_values(array_filter($records, static function (RunRecord $r) use ($type, $ticket): bool {
            if (\is_string($type) && $r->type !== $type) {
                return false;
            }

            return !\is_string($ticket) || $r->ticketKey === $ticket;
        }));
        $records = \array_slice($records, 0, $limit);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(array_map(static fn (RunRecord $r): array => $r->toArray(), $records), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ([] === $records) {
            $io->info('No run recorded yet.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Date', 'Type', 'Ticket', 'Status', 'Branch', 'Summary'],
            array_map(static function (RunRecord $r): array {
                $summary = preg_replace('/\s+/', ' ', $r->summary) ?? '';

                return [
                    $r->startedAt,
                    $r->type,
                    $r->ticketKey,
                    $r->status,
                    $r->branch,
                    mb_substr(trim($summary), 0, 60),
                ];
            }, $records),
        );

        return Command::SUCCESS;
    }
}
