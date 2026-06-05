<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Review;

use TheBenBenJ\TicketPilotBundle\Contract\MergeRequestReaderInterface;
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
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly AgentReviewPromptBuilder $promptBuilder,
        private readonly string $agentName,
        private readonly string $screenshotDir,
        private readonly string $login = '',
        private readonly string $password = '',
        private readonly string $summaryStartMarker = '<<<REVIEW_SUMMARY',
        private readonly string $summaryEndMarker = 'REVIEW_SUMMARY>>>',
        private readonly ?MergeRequestReaderInterface $mergeRequestReader = null,
        private readonly ?ReviewReportRenderer $reportRenderer = null,
    ) {
    }

    /**
     * @param callable(string):void|null $onOutput  Streamed agent-output callback
     * @param string|null                $agentName Agent to drive the review (null/'' = configured default)
     */
    public function run(
        Ticket $ticket,
        string $baseUrl,
        string $branch = '',
        ?string $model = null,
        ?callable $onOutput = null,
        ?string $agentName = null,
    ): AgentReviewResult {
        $name = (null !== $agentName && '' !== $agentName) ? $agentName : $this->agentName;
        if (!$this->agents->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Unknown review agent "%s" (available: %s)', $name, implode(', ', $this->agents->names())));
        }

        $mergeRequestDescription = ('' !== $branch && null !== $this->mergeRequestReader)
            ? $this->mergeRequestReader->mergeRequestDescription($branch)
            : '';

        $prompt = $this->promptBuilder->build($ticket, $baseUrl, $mergeRequestDescription, $this->login, $this->password);

        $this->ensureScreenshotDir();
        $startedAt = time();

        $result = $this->agents->get($name)->run($prompt, $model, $onOutput);

        $summary = $this->extractSummary($result->output);
        $screenshots = $this->selectReported($this->collectScreenshots($startedAt), $summary);
        $passed = $this->verdict($summary);
        $reportPdf = $this->reportRenderer?->render($ticket, $passed, $summary, $screenshots);

        return new AgentReviewResult($passed, $summary, $screenshots, $result->output, $reportPdf);
    }

    private function ensureScreenshotDir(): void
    {
        if ('' !== $this->screenshotDir && !is_dir($this->screenshotDir)) {
            @mkdir($this->screenshotDir, 0o777, true);
        }
    }

    /**
     * Collects the images created in the screenshot directory since the review
     * started (PNG/JPEG), so only this run's screenshots are reported.
     *
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
     * Keeps only the screenshots the agent deemed worth reporting (the ones it
     * lists by name in its summary), so the ticket gets the meaningful shots and
     * not every intermediate capture. Falls back to all when the summary names
     * none of them (never lose the evidence).
     *
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

    /**
     * Extracts the agent's summary block delimited by the markers, falling back
     * to the tail of the output when the markers are missing.
     */
    public function extractSummary(string $output): string
    {
        $start = preg_quote($this->summaryStartMarker, '/');
        $end = preg_quote($this->summaryEndMarker, '/');

        if (1 === preg_match('/'.$start.'(.*?)'.$end.'/s', $output, $matches)) {
            return trim($matches[1]);
        }

        return trim(mb_substr($output, -2000));
    }

    /**
     * A review passes only when the summary explicitly states it passed and
     * never states it failed or could not be concluded. A "failed" or
     * "inconclusive" token always wins, so a partial run (scenario not fully
     * executed) or an ambiguous/empty summary is never reported as passed.
     */
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
