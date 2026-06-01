<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheBenBenJ\TicketPilotBundle\Contract\PromptBuilderInterface;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;

/**
 * Prints the agent prompt that would be generated for a ticket (debugging aid).
 */
#[AsCommand(
    name: 'ia:prompt',
    description: 'Print the agent prompt generated for a ticket',
)]
final class ShowPromptCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly PromptBuilderInterface $promptBuilder,
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
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln($this->promptBuilder->build($ticket));

        return Command::SUCCESS;
    }
}
