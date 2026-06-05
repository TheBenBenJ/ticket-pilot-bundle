<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Contract\CodingAgentInterface;
use TheBenBenJ\TicketPilotBundle\Model\AgentResult;
use TheBenBenJ\TicketPilotBundle\Model\Ticket;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewRunner;

final class AgentReviewRunnerTest extends TestCase
{
    /**
     * @param iterable<CodingAgentInterface> $agents
     */
    private function runner(iterable $agents = [], string $defaultAgent = 'cursor'): AgentReviewRunner
    {
        return new AgentReviewRunner(
            new AgentRegistry($agents),
            new AgentReviewPromptBuilder(),
            $defaultAgent,
            '',
            '',
            '',
            '<<<S',
            'S>>>',
        );
    }

    private function ticket(): Ticket
    {
        return new Ticket('PROJ-1', 'Title', 'Desc', 'Bug', 'jira');
    }

    public function testExtractSummaryReadsBetweenMarkers(): void
    {
        $output = "noise\n<<<S\nREVIEW PASSED\n- screen ok\nS>>>\ntrailing noise";

        self::assertSame("REVIEW PASSED\n- screen ok", $this->runner()->extractSummary($output));
    }

    public function testExtractSummaryFallsBackToTailWhenNoMarkers(): void
    {
        self::assertSame('just some agent output', $this->runner()->extractSummary('just some agent output'));
    }

    public function testVerdictPassesOnlyWithPassToken(): void
    {
        $runner = $this->runner();

        self::assertTrue($runner->verdict('REVIEW PASSED, all good'));
        self::assertFalse($runner->verdict('REVIEW FAILED: a regression'));
        // A failure token always wins over a pass token.
        self::assertFalse($runner->verdict("REVIEW PASSED\n...but REVIEW FAILED on edge case"));
        // Ambiguous / empty summaries are treated as a failure.
        self::assertFalse($runner->verdict('the agent rambled without a verdict'));
        self::assertFalse($runner->verdict(''));
    }

    public function testInconclusiveScenarioNeverPasses(): void
    {
        $runner = $this->runner();

        // A scenario that could not be fully executed is inconclusive, not passed.
        self::assertFalse($runner->verdict('REVIEW INCONCLUSIVE: the test data does not exist on this env'));
        // Inconclusive wins over a stray pass token too.
        self::assertFalse($runner->verdict("REVIEW INCONCLUSIVE\nThe screen looked fine but REVIEW PASSED could not be confirmed"));
    }

    public function testRunUsesTheAgentOverride(): void
    {
        $cursor = new RecordingAgent('cursor');
        $claude = new RecordingAgent('claude');
        $runner = $this->runner([$cursor, $claude], 'cursor');

        $runner->run($this->ticket(), 'https://app.test', '', null, null, 'claude');

        self::assertSame(0, $cursor->calls);
        self::assertSame(1, $claude->calls);
    }

    public function testRunFallsBackToTheConfiguredAgent(): void
    {
        $cursor = new RecordingAgent('cursor');
        $runner = $this->runner([$cursor], 'cursor');

        $runner->run($this->ticket(), 'https://app.test');

        self::assertSame(1, $cursor->calls);
    }

    public function testRunRejectsAnUnknownAgent(): void
    {
        $runner = $this->runner([new RecordingAgent('cursor')], 'cursor');

        $this->expectException(\InvalidArgumentException::class);
        $runner->run($this->ticket(), 'https://app.test', '', null, null, 'gpt');
    }

    public function testSelectReportedKeepsOnlyTheShotsNamedInTheSummary(): void
    {
        $shots = ['/tmp/a.png', '/tmp/b.png', '/tmp/c.png'];
        $summary = "REVIEW PASSED\nSee b.png for the planning screen.";

        self::assertSame(['/tmp/b.png'], $this->runner()->selectReported($shots, $summary));
    }

    public function testSelectReportedFallsBackToAllWhenNoneAreNamed(): void
    {
        $shots = ['/tmp/a.png', '/tmp/b.png'];

        self::assertSame($shots, $this->runner()->selectReported($shots, 'REVIEW PASSED, no shot mentioned'));
    }
}

/**
 * Minimal coding agent that records how many times it was asked to run.
 */
final class RecordingAgent implements CodingAgentInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(string $prompt, ?string $model = null, ?callable $onOutput = null): AgentResult
    {
        ++$this->calls;

        return new AgentResult(true, "<<<S\nREVIEW PASSED\nS>>>");
    }
}
