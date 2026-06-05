<?php

declare(strict_types=1);

namespace TheBenBenJ\TicketPilotBundle\Tests\Unit\Review;

use PHPUnit\Framework\TestCase;
use TheBenBenJ\TicketPilotBundle\Registry\AgentRegistry;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewPromptBuilder;
use TheBenBenJ\TicketPilotBundle\Review\AgentReviewRunner;

final class AgentReviewRunnerTest extends TestCase
{
    private function runner(): AgentReviewRunner
    {
        return new AgentReviewRunner(
            new AgentRegistry([]),
            new AgentReviewPromptBuilder(),
            'cursor',
            '',
            '',
            '',
            '<<<S',
            'S>>>',
        );
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
