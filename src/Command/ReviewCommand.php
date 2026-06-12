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
use TheBenBenJ\TicketPilotBundle\Contract\AgentReviewReporterInterface;
use TheBenBenJ\TicketPilotBundle\Contract\RecipeRunnerInterface;
use TheBenBenJ\TicketPilotBundle\Contract\ReviewReporterInterface;
use TheBenBenJ\TicketPilotBundle\Exception\TicketLockedException;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Registry\TicketSourceRegistry;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewRunner;
use TheBenBenJ\TicketPilotBundle\Review\RecipeRepository;
use TheBenBenJ\TicketPilotBundle\Review\ReviewUrlResolver;
use TheBenBenJ\TicketPilotBundle\Run\RunRecord;
use TheBenBenJ\TicketPilotBundle\Run\RunStoreInterface;
use TheBenBenJ\TicketPilotBundle\Service\BranchPlanner;

/**
 * Reviews a ticket against a deployed app, then reports the outcome (and
 * screenshots) back to the ticket.
 *
 * Two drivers (ticket_pilot.review.driver):
 *  - "recipe": replays the YAML test recipe the agent authored, in headless Chromium;
 *  - "agent":  a coding agent drives a real browser (via its own tools/MCP), explores
 *              the app from the ticket and merge request context, and returns a verdict.
 */
#[AsCommand(
    name: 'ia:review',
    description: 'Review a ticket on a deployed app and report the result',
)]
final class ReviewCommand extends Command
{
    public function __construct(
        private readonly TicketSourceRegistry $sources,
        private readonly BranchPlanner $branchPlanner,
        private readonly ReviewUrlResolver $urlResolver,
        private readonly string $driver,
        private readonly string $defaultSource,
        private readonly ?RecipeRepository $recipes = null,
        private readonly ?RecipeRunnerInterface $runner = null,
        private readonly ?AgentReviewRunner $agentRunner = null,
        private readonly ?RunStoreInterface $runs = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ticket', InputArgument::REQUIRED, 'Ticket key (e.g. LYSI-2098)')
            ->addOption('url', 'u', InputOption::VALUE_REQUIRED, 'Base URL to test (overrides the configured pattern)')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Ticket source ('.implode(', ', $this->sources->names()).')', $this->defaultSource)
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, '[agent] Branch whose merge/pull request gives the development context')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, '[agent] Coding agent driving the review (overrides review.agent)')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, '[agent] Model the review agent should use')
            ->addOption('no-report', null, InputOption::VALUE_NONE, 'Do not post the result back to the ticket')
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

        try {
            $ticket = $this->sources->get($sourceName)->fetchOne($key);
            $branch = (string) ($input->getOption('branch') ?? '') ?: $this->branchPlanner->branchName($ticket);
            $url = $this->urlResolver->resolve($ticket->key, $branch, $input->getOption('url'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section(\sprintf('Reviewing %s on %s', $ticket->key, $url));

        return 'agent' === $this->driver
            ? $this->reviewWithAgent($io, $ticket, $url, $branch, $input)
            : $this->reviewWithRecipe($io, $ticket, $url, $branch, $input);
    }

    private function reviewWithAgent(SymfonyStyle $io, Ticket $ticket, string $url, string $branch, InputInterface $input): int
    {
        if (null === $this->agentRunner) {
            $io->error('The "agent" review driver is not wired (ticket_pilot.review.driver: agent).');

            return Command::FAILURE;
        }

        $model = $input->getOption('model');
        $agent = $input->getOption('agent');
        try {
            $result = $this->agentRunner->run(
                $ticket,
                $url,
                $branch,
                \is_string($model) ? $model : null,
                static function (string $chunk) use ($io): void {
                    $io->write($chunk);
                },
                \is_string($agent) ? $agent : null,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (TicketLockedException $e) {
            $io->warning($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine(2);
        $io->writeln($result->summary);
        if (null !== $result->scenarioPath) {
            $io->writeln(\sprintf('Scenario: %s', $result->scenarioPath));
        }
        if ([] !== $result->screenshots) {
            $io->writeln(\sprintf('Screenshots: %s', implode(', ', array_map('basename', $result->screenshots))));
        }

        if (!$input->getOption('no-report')) {
            $source = $this->sources->get((string) $input->getOption('source'));
            if ($source instanceof AgentReviewReporterInterface) {
                try {
                    $source->reportAgentReview($ticket, $result);
                } catch (\Throwable) {
                }
            }
        }

        $status = $result->passed
            ? RunRecord::STATUS_PASSED
            : (str_contains(mb_strtoupper($result->summary), AgentReviewPromptBuilder::INCONCLUSIVE_TOKEN) ? RunRecord::STATUS_INCONCLUSIVE : RunRecord::STATUS_FAILED);
        $agentName = $input->getOption('agent');
        $this->record(RunRecord::create(
            RunRecord::TYPE_REVIEW,
            $ticket->key,
            $status,
            $branch,
            $result->summary,
            $url,
            \is_string($agentName) ? $agentName : '',
            (string) $input->getOption('source'),
            $result->duration,
            array_values($result->screenshots),
            $result->scenario,
        ));

        return $this->verdict($io, $ticket, $result->passed);
    }

    private function reviewWithRecipe(SymfonyStyle $io, Ticket $ticket, string $url, string $branch, InputInterface $input): int
    {
        if (null === $this->recipes || null === $this->runner) {
            $io->error('The "recipe" review driver is not wired.');

            return Command::FAILURE;
        }

        $recipe = $this->recipes->load($ticket->key);
        if (null === $recipe) {
            $io->error(\sprintf('No recipe found for %s (expected %s)', $ticket->key, $this->recipes->defaultPath($ticket->key)));

            return Command::FAILURE;
        }

        $result = $this->runner->run($recipe, $url);

        foreach ($result->steps as $step) {
            $line = \sprintf('%s %s %s', $step->passed ? '<info>✓</info>' : '<error>✗</error>', $step->step->action, $step->step->target ?? '');
            $io->writeln(rtrim($line).('' !== $step->message ? ' — '.$step->message : ''));
        }
        if ([] !== $result->screenshots) {
            $io->writeln(\sprintf('Screenshots: %s', implode(', ', $result->screenshots)));
        }

        if (!$input->getOption('no-report')) {
            $source = $this->sources->get((string) $input->getOption('source'));
            if ($source instanceof ReviewReporterInterface) {
                try {
                    $source->reportReview($ticket, $result);
                } catch (\Throwable) {
                }
            }
        }

        $this->record(RunRecord::create(
            RunRecord::TYPE_REVIEW,
            $ticket->key,
            $result->passed ? RunRecord::STATUS_PASSED : RunRecord::STATUS_FAILED,
            $branch,
            \sprintf('%d/%d steps passed', \count(array_filter($result->steps, static fn ($s): bool => $s->passed)), \count($result->steps)),
            $url,
            '',
            (string) $input->getOption('source'),
            0.0,
            array_values($result->screenshots),
        ));

        return $this->verdict($io, $ticket, $result->passed);
    }

    private function verdict(SymfonyStyle $io, Ticket $ticket, bool $passed): int
    {
        if ($passed) {
            $io->success(\sprintf('Review passed for %s', $ticket->key));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('Review failed for %s', $ticket->key));

        return Command::FAILURE;
    }

    /**
     * Records a run, best-effort: tracking must never break the review.
     */
    private function record(RunRecord $record): void
    {
        try {
            $this->runs?->record($record);
        } catch (\Throwable) {
        }
    }
}
