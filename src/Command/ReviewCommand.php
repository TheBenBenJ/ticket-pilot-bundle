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
use TheBenBenJ\TicketPilotBundle\Contract\RecipeRunnerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\ReviewReporterInterface;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Review\RecipeRepository;
use TheBenBenJ\TicketPilotBundle\Review\ReviewUrlResolver;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;

/**
 * Replays the browser test recipe the agent authored for a ticket against a
 * deployed app, then reports the result (and screenshots) back to the ticket.
 */
#[AsCommand(
    name: 'ia:review',
    description: 'Replay a ticket\'s browser test recipe and report the result',
)]
final class ReviewCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly BranchPlanner $branchPlanner,
        private readonly RecipeRepository $recipes,
        private readonly ReviewUrlResolver $urlResolver,
        private readonly RecipeRunnerInterface $runner,
        private readonly string $defaultSource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ticket', InputArgument::REQUIRED, 'Ticket key (e.g. LYSI-2098)')
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Base URL to test (overrides the configured pattern)')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Ticket source ('.implode(', ', $this->sources->names()).')', $this->defaultSource)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getArgument('ticket');
        $sourceName = (string) $input->getOption('source');

        if (!$this->sources->has($sourceName)) {
            $io->error(\sprintf('Unknown source "%s" (available: %s)', $sourceName, implode(', ', $this->sources->names())));

            return Command::INVALID;
        }

        $recipe = $this->recipes->load($key);
        if (null === $recipe) {
            $io->error(\sprintf('No recipe found for %s (expected %s)', $key, $this->recipes->defaultPath($key)));

            return Command::FAILURE;
        }

        try {
            $ticket = $this->sources->get($sourceName)->fetchOne($key);
            $url = $this->urlResolver->resolve($ticket->key, $this->branchPlanner->branchName($ticket), $input->getOption('url'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('Reviewing %s on %s', $ticket->key, $url));
        $result = $this->runner->run($recipe, $url);

        foreach ($result->steps as $step) {
            $line = \sprintf('%s %s %s', $step->passed ? '<info>✓</info>' : '<error>✗</error>', $step->step->action, $step->step->target ?? '');
            $io->writeln(rtrim($line).('' !== $step->message ? ' — '.$step->message : ''));
        }
        if ([] !== $result->screenshots) {
            $io->writeln(\sprintf('Screenshots: %s', implode(', ', $result->screenshots)));
        }

        // Best-effort: report the outcome back to the ticket.
        $source = $this->sources->get($sourceName);
        if ($source instanceof ReviewReporterInterface) {
            try {
                $source->reportReview($ticket, $result);
            } catch (\Throwable) {
            }
        }

        if ($result->passed) {
            $io->success(\sprintf('Review passed for %s', $ticket->key));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('Review failed for %s', $ticket->key));

        return Command::FAILURE;
    }
}
