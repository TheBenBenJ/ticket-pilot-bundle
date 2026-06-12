<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use Symfony\Component\Lock\LockFactory;
use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestReaderInterface;
use TheBenBenJ\TicketPilotBundle\Exception\TicketLockedException;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;

/**
 * Runs an agent-driven browser review: builds the review prompt (ticket + merge
 * request context + project rules + credentials), runs the coding agent (which
 * drives a real browser through its own tools, e.g. a Playwright MCP server),
 * collects the screenshots it produced, then extracts the verdict and summary.
 *
 * The browser itself is the agent's concern (via MCP), so this runner needs no
 * Chromium binding; it only orchestrates the agent and reads the result.
 */
final class AgentReviewRunner
{
    private const LOCK_PREFIX = 'ticket-pilot-review-';

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly AgentReviewPromptBuilder $promptBuilder,
        private readonly string $agentName,
        private readonly string $screenshotDir,
        private readonly string $login = '',
        private readonly string $password = '',
        private readonly string $summaryStartMarker = '<<<REVIEW_SUMMARY',
        private readonly string $summaryEndMarker = 'REVIEW_SUMMARY>>>',
        private readonly string $scenarioStartMarker = '<<<REVIEW_SCENARIO',
        private readonly string $scenarioEndMarker = 'REVIEW_SCENARIO>>>',
        private readonly ?MergeRequestReaderInterface $mergeRequestReader = null,
        private readonly ?ReviewReportRenderer $reportRenderer = null,
        private readonly ?ScenarioRepository $scenarios = null,
        private readonly ?LockFactory $lockFactory = null,
        private readonly int $lockTtl = 3600,
    ) {
    }

    /**
     * @param callable(string):void|null $onOutput  Streamed agent-output callback
     * @param string|null                $agentName Agent to drive the review (null/'' = configured default)
     *
     * @throws TicketLockedException when another review already holds the ticket lock
     */
    public function run(
        Ticket $ticket,
        string $baseUrl,
        string $branch = '',
        ?string $model = null,
        ?callable $onOutput = null,
        ?string $agentName = null,
        string $instructions = '',
    ): AgentReviewResult {
        $name = (null !== $agentName && '' !== $agentName) ? $agentName : $this->agentName;
        if (!$this->agents->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Unknown review agent "%s" (available: %s)', $name, implode(', ', $this->agents->names())));
        }

        $lock = $this->lockFactory?->createLock(self::LOCK_PREFIX.$ticket->key, (float) $this->lockTtl);
        if (null !== $lock && !$lock->acquire()) {
            throw new TicketLockedException($ticket->key);
        }

        try {
            $mergeRequestDescription = ('' !== $branch && null !== $this->mergeRequestReader)
                ? $this->mergeRequestReader->mergeRequestDescription($branch)
                : '';

            $prompt = $this->promptBuilder->build($ticket, $baseUrl, $mergeRequestDescription, $this->login, $this->password, $instructions);

            $this->ensureScreenshotDir();
            $startedAt = time();

            $result = $this->agents->get($name)->run($prompt, $model, $onOutput);

            $scenario = $this->extractBlock($result->output, $this->scenarioStartMarker, $this->scenarioEndMarker);
            $scenarioPath = $this->persistScenario($ticket->key, $scenario);
            $summary = $this->extractSummary($result->output);
            $screenshots = $this->selectReported($this->collectScreenshots($startedAt), $summary);
            $passed = $this->verdict($summary);
            $reportPdf = $this->reportRenderer?->render($ticket, $passed, $summary, $screenshots);

            return new AgentReviewResult($passed, $summary, $screenshots, $result->output, $reportPdf, $result->duration, $scenarioPath, $scenario);
        } finally {
            $lock?->release();
        }
    }

    private function persistScenario(string $ticketKey, string $scenario): ?string
    {
        if (null === $this->scenarios || '' === trim($scenario)) {
            return null;
        }

        try {
            return $this->scenarios->save($ticketKey, $scenario);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureScreenshotDir(): void
    {
        if ('' !== $this->screenshotDir && !is_dir($this->screenshotDir)) {
            @mkdir($this->screenshotDir, 0o777, true);
        }
    }

    /**
     * @return list<string>
     */
    private function collectScreenshots(int $since): array
    {
        if ('' === $this->screenshotDir) {
            return [];
        }

        $matches = glob(rtrim($this->screenshotDir, '/').'/*.{png,jpg,jpeg}', \GLOB_BRACE);
        if (false === $matches) {
            return [];
        }

        $shots = array_values(array_filter(
            $matches,
            static fn (string $f): bool => is_file($f) && filemtime($f) >= $since,
        ));
        sort($shots);

        return $shots;
    }

    /**
     * @param list<string> $screenshots
     *
     * @return list<string>
     */
    public function selectReported(array $screenshots, string $summary): array
    {
        $mentioned = array_values(array_filter(
            $screenshots,
            static fn (string $f): bool => str_contains($summary, basename($f)),
        ));

        return [] !== $mentioned ? $mentioned : $screenshots;
    }

    public function extractSummary(string $output): string
    {
        $extracted = $this->extractBlock($output, $this->summaryStartMarker, $this->summaryEndMarker);

        return '' !== $extracted ? $extracted : trim(mb_substr($output, -2000));
    }

    public function extractBlock(string $output, string $startMarker, string $endMarker): string
    {
        $start = preg_quote($startMarker, '/');
        $end = preg_quote($endMarker, '/');

        if (1 === preg_match('/'.$start.'(.*?)'.$end.'/s', $output, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    public function verdict(string $summary): bool
    {
        $normalized = mb_strtoupper($summary);

        if (str_contains($normalized, AgentReviewPromptBuilder::FAIL_TOKEN)
            || str_contains($normalized, AgentReviewPromptBuilder::INCONCLUSIVE_TOKEN)) {
            return false;
        }

        return str_contains($normalized, AgentReviewPromptBuilder::PASS_TOKEN);
    }
}
